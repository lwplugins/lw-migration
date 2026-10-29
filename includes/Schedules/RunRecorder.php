<?php
/**
 * Records how a scheduled run ended.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Job\FailureReporter;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobLog;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Called from the finish hook of every job (Services::finished), whichever
 * driver ended it (cron, browser, CLI). A job of a schedule — state.schedule,
 * the snapshot taken when it started — gets a log entry, a finished backup
 * the schedule's title as label, and the mail its notification setting asks
 * for. A run of a schedule deleted meanwhile is not recorded. The error text
 * loses the server's folders, the storage folder's name (also where a relative
 * path names it) and URL tokens, and is clipped.
 */
final class RunRecorder {

	public const CRON = 'cron';

	public const MANUAL = 'manual';

	public const SKIPPED = 'skipped';

	/** A finished backup whose uploads did not all succeed (spec 21.1). */
	public const PARTIAL = 'partial';

	/** What stands for the storage folder's name in a log entry and a mail. */
	public const STORAGE = '[storage]';

	private const ERROR_BYTES = 500;

	/**
	 * @param Notifier           $notifier    Mailer.
	 * @param array<int, string> $redactPaths Absolute paths removed from errors (Services::redactPaths()).
	 * @param \Closure           $exists      fn( string $id ): bool — whether the schedule still exists.
	 * @param string             $storageName Name of the storage folder, shown as STORAGE wherever a text still names it (any case).
	 * @param \Closure|null      $note        fn( Job $job, string $line ): void — adds a line to the job's log (best effort).
	 */
	public function __construct( private Notifier $notifier, private array $redactPaths, private \Closure $exists, private string $storageName = '', private ?\Closure $note = null ) {
	}

	public static function fromServices( Services $services ): self {
		return new self(
			new Notifier(),
			$services->redactPaths(),
			static fn( string $id ): bool => null !== $services->schedules()->find( $id ),
			basename( $services->storage()->root() ),
			static function ( Job $job, string $line ) use ( $services ): void {
				try {
					JobLog::append( $services->jobs()->dir( $job->id ), time(), $line );
				} catch ( \Throwable $e ) {
					unset( $e ); // Logging is best effort.
				}
			}
		);
	}

	/**
	 * What a run keeps of its schedule (state.schedule): the run is reported
	 * and pruned the way the schedule was when it started.
	 *
	 * @param Schedule $schedule Schedule.
	 * @param string   $trigger  CRON or MANUAL.
	 * @return array{id: string, title: string, trigger: string, retention: array<string, int>, notify: array{when: string, email: string}}
	 */
	public static function snapshot( Schedule $schedule, string $trigger ): array {
		return [
			'id'        => $schedule->id,
			'title'     => Repository::shownTitle( $schedule ),
			'trigger'   => $trigger,
			'retention' => $schedule->retention,
			'notify'    => [
				'when'  => $schedule->notifyWhen,
				'email' => $schedule->notifyEmail,
			],
		];
	}

	public function finished( Job $job, int $now ): void {
		$snapshot = self::snapshotOf( $job );
		if ( null === $snapshot || ! ( $this->exists )( $snapshot['id'] ) ) {
			return;
		}
		$done    = Job::DONE === $job->status;
		$backup  = is_array( $job->state['backup'] ?? null ) ? $job->state['backup'] : [];
		$partial = $done && CloudOutcome::partial( $job );
		$entry   = [
			'job'       => $job->id,
			'trigger'   => $snapshot['trigger'],
			'status'    => $partial ? self::PARTIAL : $job->status,
			'startedAt' => $job->createdAt,
			'endedAt'   => $now,
			'backup'    => $done ? (string) ( $backup['name'] ?? '' ) : '',
			'size'      => $done ? (int) ( $backup['size'] ?? 0 ) : 0,
			'error'     => $this->redact( $job->error ),
			'warnings'  => $job->warningCount,
		];
		$cloud   = CloudOutcome::forLog( $job );
		if ( [] !== $cloud ) {
			$entry['cloud'] = $cloud;
		}
		if ( '' !== $entry['backup'] && '' !== $snapshot['title'] && false !== ( $backup['local'] ?? true ) ) {
			try {
				Labels::set( $entry['backup'], $snapshot['title'] );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e ); // Not a backup name we could label; the log entry still names it.
			}
		}
		$this->record( $snapshot, $entry, $now, $job );
	}

	/**
	 * A due run that never became a job (its password does not open, the site
	 * was busy for a day, …).
	 *
	 * @param Schedule $schedule Schedule.
	 * @param string   $trigger  CRON or MANUAL.
	 * @param string   $status   'failed' or SKIPPED.
	 * @param string   $reason   Message for people (translated).
	 * @param int      $now      Unix time.
	 */
	public function notStarted( Schedule $schedule, string $trigger, string $status, string $reason, int $now ): void {
		$this->record(
			self::snapshot( $schedule, $trigger ),
			[
				'job'       => '',
				'trigger'   => $trigger,
				'status'    => $status,
				'startedAt' => $now,
				'endedAt'   => $now,
				'backup'    => '',
				'size'      => 0,
				'error'     => $this->redact( $reason ),
				'warnings'  => 0,
			],
			$now
		);
	}

	/**
	 * The entry is written before the mail goes (mail PENDING, and for a
	 * failure the throttle time), so a send whose result is never recorded
	 * still throttles the next failure; a success clears the throttle time,
	 * a failed send restores the one before.
	 *
	 * @param array<string, mixed> $snapshot state.schedule.
	 * @param array<string, mixed> $entry    Entry without the mail result.
	 * @param int                  $now      Unix time.
	 * @param Job|null             $job      The ended job (null: the run never became one).
	 */
	private function record( array $snapshot, array $entry, int $now, ?Job $job = null ): void {
		$id            = (string) $snapshot['id'];
		$before        = Log::failureMailAt( $id );
		$failure       = in_array( $entry['status'], [ Job::FAILED, self::SKIPPED, self::PARTIAL ], true );
		$entry['mail'] = $this->notifier->decide( $snapshot, (string) $entry['status'], Log::all( $id ), $before, $now );
		$pending       = Notifier::PENDING === $entry['mail'];
		Log::add( $id, $entry, Job::DONE === $entry['status'] ? 0 : ( $pending && $failure ? $now : null ) );
		if ( ! $pending ) {
			return;
		}
		$result = $this->notifier->send(
			$snapshot,
			$entry,
			function ( string $reason ) use ( $job ): void {
				if ( null !== $job && null !== $this->note ) {
					( $this->note )( $job, $this->redact( $reason ) );
				}
			}
		);
		Log::setMail( $id, $result, Notifier::FAILED === $result && $failure ? $before : null );
	}

	/**
	 * @param Job $job Ended job.
	 * @return array<string, mixed>|null state.schedule, or null for a job of no schedule.
	 */
	private static function snapshotOf( Job $job ): ?array {
		$snapshot = $job->state['schedule'] ?? null;
		if ( ! is_array( $snapshot ) || ! is_string( $snapshot['id'] ?? null ) || 1 !== preg_match( Schedule::ID_PATTERN, $snapshot['id'] ) ) {
			return null;
		}
		$snapshot['title']   = is_string( $snapshot['title'] ?? null ) ? $snapshot['title'] : '';
		$snapshot['trigger'] = self::CRON === ( $snapshot['trigger'] ?? null ) ? self::CRON : self::MANUAL;

		return $snapshot;
	}

	private function redact( string $text ): string {
		$text = FailureReporter::redactWith( $text, $this->redactPaths );
		if ( '' !== $this->storageName ) {
			$text = str_ireplace( $this->storageName, self::STORAGE, $text );
		}

		return Text::clip( AddressRedactor::reduce( $text ), self::ERROR_BYTES );
	}
}

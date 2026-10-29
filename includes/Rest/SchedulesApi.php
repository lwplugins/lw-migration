<?php
/**
 * Schedules over REST.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Schedules\CurrentJob;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\NextRun;
use LightweightPlugins\Migration\Schedules\Queue;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Schedules\ScheduleException;
use LightweightPlugins\Migration\Schedules\ScheduleJobs;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderRefusedException;

defined( 'ABSPATH' ) || exit;

/**
 * List, create, change, delete, run now, read and clear the log. The list
 * heals the WP-Cron events (like an admin request). A new schedule gets its
 * event; an edit re-plans it only when the timing changed (switching on or
 * off included) or it has none, so an edit never drops a pending run. The
 * anchor of the "every N" counting is the repository's (Repository::save).
 * Deleting a schedule removes its event, its waiting run and its log; its
 * backups stay. "Run now" is a manual run (no mail) the browser then drives
 * like any job. No answer carries the sealed password (hasPassword instead).
 */
final class SchedulesApi {

	private const UPCOMING = 5;

	private const WEEK_DAYS = 7;

	private const WEEK_MAX = 200;

	/**
	 * @param Repository     $schedules Schedules.
	 * @param Queue          $queue     Due runs.
	 * @param SealedPassword $passwords Seals a new password.
	 * @param \Closure       $now       fn(): int.
	 * @param \Closure       $tz        fn(): \DateTimeZone — the site time zone.
	 * @param \Closure       $running   fn(): ?string — the schedule whose job is running, if any.
	 */
	public function __construct( private Repository $schedules, private Queue $queue, private SealedPassword $passwords, private \Closure $now, private \Closure $tz, private \Closure $running ) {
	}

	public static function fromServices( Services $services ): self {
		return new self(
			$services->schedules(),
			new Queue(),
			new SealedPassword(),
			static fn(): int => time(),
			static fn(): \DateTimeZone => wp_timezone(),
			static fn(): ?string => CurrentJob::fromServices( $services )->runningSchedule() // As the cron driver sees it: a run given up is over.
		);
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, cron: array{disabled: bool, late: bool}, timezone: string}
	 */
	public function all(): array {
		$all     = $this->schedules->all();
		$running = ( $this->running )(); // Once: it scans the job folders.
		Events::heal( $all, $this->now(), Events::workLeft( $this->queue, static fn(): ?string => $running ) );

		return [
			'items'    => array_map( fn( Schedule $s ): array => $this->view( $s, $running ), $all ),
			'cron'     => Events::health( $all, $this->now() ),
			'timezone' => ( $this->tz )()->getName(),
		];
	}

	/**
	 * @param array<string, mixed> $body Schedule fields.
	 * @return array<string, mixed> The item.
	 * @throws RestException On a refused field, or beyond the schedule limit.
	 */
	public function create( array $body ): array {
		$schedule = ScheduleInput::apply( Schedule::fromArray( [ 'id' => $this->newId() ] ), $body, $this->sealer() );

		$schedule->createdAt = $this->now();
		$schedule->updatedAt = $this->now();
		$this->store( $schedule );
		Events::sync( $schedule, $this->now() );

		return $this->view( $schedule, null );
	}

	/**
	 * @param string               $id   Schedule id.
	 * @param array<string, mixed> $body Fields to change.
	 * @return array<string, mixed> The item.
	 * @throws RestException When it does not exist or a field is refused.
	 */
	public function update( string $id, array $body ): array {
		$old      = $this->find( $id );
		$schedule = ScheduleInput::apply( $old, $body, $this->sealer() );
		$retimed  = ! $old->sameTiming( $schedule );

		$schedule->updatedAt = $this->now();
		$this->store( $schedule );
		if ( $retimed || null === Events::nextRun( $id ) ) {
			Events::sync( $schedule, $this->now() );
		}

		return $this->view( $schedule, ( $this->running )() );
	}

	/**
	 * @param string $id Schedule id.
	 * @throws RestException When it does not exist.
	 */
	public function delete( string $id ): void {
		$this->find( $id );
		$this->schedules->delete( $id );
		Events::unschedule( $id );
		$this->queue->remove( $id );
	}

	/**
	 * @param string $id Schedule id.
	 * @return array{entries: array<int, array<string, int|string>>}
	 * @throws RestException When it does not exist.
	 */
	public function log( string $id ): array {
		$this->find( $id );

		return [ 'entries' => Log::all( $id ) ];
	}

	/**
	 * @param string $id Schedule id.
	 * @return array{entries: array<int, array<string, int|string>>}
	 * @throws RestException When it does not exist.
	 */
	public function clearLog( string $id ): array {
		$this->find( $id );
		Log::clear( $id );

		return [ 'entries' => [] ];
	}

	/**
	 * @param string       $id      Schedule id.
	 * @param ScheduleJobs $starter Starts the job.
	 * @return Job The new manual job.
	 * @throws RestException When it does not exist (404) or cannot run (409); a busy site comes through as SiteBusyException (ErrorMap: 409).
	 */
	public function run( string $id, ScheduleJobs $starter ): Job {
		$schedule = $this->find( $id );
		try {
			return $starter->start( $schedule, RunRecorder::MANUAL, $this->now() );
		} catch ( ScheduleException $e ) {
			$folder = $e->getPrevious();
			throw $folder instanceof FolderRefusedException ? RestException::backupDirUnusable( $folder ) : new RestException( 'lw_migration_schedule_unusable', $e->getMessage(), 409 );
		}
	}

	/**
	 * @param string $id Schedule id from the route.
	 * @throws RestException 404 when there is no such schedule.
	 */
	private function find( string $id ): Schedule {
		$schedule = 1 === preg_match( Schedule::ID_PATTERN, $id ) ? $this->schedules->find( $id ) : null;
		if ( null === $schedule ) {
			throw new RestException( 'lw_migration_schedule_missing', __( 'This schedule does not exist (any more). Reload the page.', 'lw-migration' ), 404 );
		}

		return $schedule;
	}

	/**
	 * An id no stored schedule has (a clash would replace that schedule).
	 */
	private function newId(): string {
		do {
			$id = Schedule::newId();
		} while ( null !== $this->schedules->find( $id ) );

		return $id;
	}

	/**
	 * @param Schedule $schedule Schedule to save (the repository sets its anchor).
	 * @throws RestException Beyond the schedule limit.
	 */
	private function store( Schedule $schedule ): void {
		try {
			$this->schedules->save( $schedule );
		} catch ( ScheduleException $e ) {
			throw new RestException( 'lw_migration_too_many_schedules', $e->getMessage(), 409 );
		}
	}

	/**
	 * @param Schedule    $schedule Schedule.
	 * @param string|null $running  The schedule whose job runs now.
	 * @return array<string, mixed>
	 */
	private function view( Schedule $schedule, ?string $running ): array {
		$upcoming = $schedule->enabled ? NextRun::upcoming( $schedule, $this->now(), ( $this->tz )(), self::UPCOMING ) : [];

		$week = $schedule->enabled ? NextRun::within( $schedule, $this->now(), ( $this->tz )(), $this->now() + self::WEEK_DAYS * DAY_IN_SECONDS, self::WEEK_MAX ) : [];
		$log  = Log::all( $schedule->id );

		$shown        = clone $schedule;
		$shown->title = Repository::shownTitle( $schedule );

		return ScheduleView::of( $shown, Events::nextRun( $schedule->id ), $upcoming, $log[0] ?? null, $running === $schedule->id, $week, $log );
	}

	private function sealer(): \Closure {
		return fn( string $plain ): string => $this->passwords->seal( $plain );
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}

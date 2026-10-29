<?php
/**
 * Drives the other site's job of a push or a pull.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link\Steps;

use LightweightPlugins\Migration\Archive\ArchiveDigest;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Link\Client;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\LinkPipeline;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the other site's job from here, one signed call at a time, until
 * the deadline: push mode (link_import) ticks the restore that link_upload
 * started there and relays its confirmation to this site's user; pull mode
 * (link_export) starts the other site's export once (idempotent by this
 * job's id) and ticks it until its archive is ready, then hands the
 * download address to link_download. The other site's state is kept in
 * state.remote (its texts reduced); from the confirmation this site
 * forwarded until the other site ends, a cancel of the push is deferred
 * (LinkPipeline::pastNoReturn()); a job there that shows the same state for
 * LinkPipeline::STALL_SECONDS fails this one. Every successful call counts in
 * cursor.calls, so a long remote run never looks stuck to Job\AttemptGuard
 * (review C1). Calls within a tick are at least PushUploadStep::MIN_INTERVAL
 * apart. Passing failures wait and try again (Retry).
 */
final class RemoteJobStep implements StepInterface {

	public const PUSH_KEY = 'link_import';

	public const PULL_KEY = 'link_export';

	private const MAX_PAUSE = 10;

	private const TEXT_BYTES = 500;

	private const STATUSES = [ Job::PENDING, Job::RUNNING, Job::NEEDS_INPUT, Job::DONE, Job::FAILED, Job::CANCELLED ];

	/**
	 * Pause: fn( float $seconds ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $pause;

	/**
	 * @param LinkClients   $clients Clients of the outgoing links.
	 * @param bool          $pull    Pull mode (link_export) instead of push mode (link_import).
	 * @param \Closure      $now     fn(): int.
	 * @param \Closure|null $pause   fn( float $seconds ): void (default usleep).
	 */
	public function __construct( private LinkClients $clients, private bool $pull, private \Closure $now, ?\Closure $pause = null ) {
		$this->pause = $pause ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1000000 ) );
		};
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$client = $this->clients->forJob( $job );
		try {
			$last = null;
			do {
				if ( null !== $last ) { // Spaced like the parts of a push: the other site caps signed requests (nonces).
					Retry::wait( $this->pause, $deadline, PushUploadStep::MIN_INTERVAL - ( microtime( true ) - $last ) );
				}
				$last = microtime( true );
				$view = $this->remember( $job, $this->call( $client, $job ) );
				Retry::clear( $job );
				// Progress for Job\AttemptGuard, which fingerprints the cursor: without it the 6th tick of a long
				// remote run would count as "started 5 times without progress" (review C1). A request that dies
				// mid-call never gets here, so the guard keeps its purpose.
				$job->cursor['calls'] = (int) ( $job->cursor['calls'] ?? 0 ) + 1;
				$ended                = $this->settle( $job, $client, $view );
				if ( null !== $ended ) {
					return $ended;
				}
			} while ( ! $deadline->reached() );
		} catch ( LinkException $e ) {
			$count = Retry::note( $job, $e, (int) ( $this->now )() );
			Retry::wait( $this->pause, $deadline, (float) min( self::MAX_PAUSE, 2 * $count ) );

			/* translators: 1: the other site's name, 2: why it does not answer */
			return StepResult::more( (int) ( $job->state['remote']['percent'] ?? 0 ), sprintf( __( 'Waiting for %1$s: %2$s', 'lw-migration' ), self::peer( $job ), $e->getMessage() ) );
		}
		$message = (string) $job->state['remote']['message'];

		/* translators: 1: the other site's name, 2: its status line */
		return StepResult::more( (int) $job->state['remote']['percent'], '' === $message ? self::peer( $job ) : sprintf( __( '%1$s: %2$s', 'lw-migration' ), self::peer( $job ), $message ) );
	}

	/**
	 * The other site's name as stored with the link (its address when it has none).
	 *
	 * @param Job $job Push or pull job.
	 */
	public static function peer( Job $job ): string {
		$name = (string) ( $job->state['link']['name'] ?? '' );

		return '' !== $name ? $name : AddressRedactor::display( (string) ( $job->state['link']['address'] ?? '' ) );
	}

	/**
	 * One call: start the export (pull, once), forward the confirmation while
	 * the other site still waits for it, or tick.
	 *
	 * @param Client $client The link's client.
	 * @param Job    $job    Job.
	 * @return mixed The other site's job view, unchecked.
	 * @throws LinkException On every failure.
	 */
	private function call( Client $client, Job $job ) {
		$remote = (string) ( $job->state['remote']['job'] ?? '' );
		if ( '' === $remote ) {
			if ( ! $this->pull ) {
				throw new LinkException( LinkException::PROTOCOL, __( 'The other site never started the restore.', 'lw-migration' ) );
			}

			return $client->json( 'POST', '/pull', [ 'ref' => $job->id ] )['json'];
		}
		if ( true !== ( $job->state['input']['confirm'] ?? null ) ) {
			return $client->json( 'POST', '/jobs/' . $remote . '/tick', null, Client::TICK_TIMEOUT )['json'];
		}
		$view  = $client->json( 'GET', '/jobs/' . $remote )['json'];
		$input = is_array( $view['input'] ?? null ) ? $view['input'] : [];
		if ( Job::NEEDS_INPUT === ( $view['status'] ?? null ) && 'confirm' === ( $input['kind'] ?? null ) ) {
			$answer = [ 'confirm' => true ];
			if ( is_bool( $job->state['input']['safetyBackup'] ?? null ) ) {
				$answer['safetyBackup'] = $job->state['input']['safetyBackup'];
			}
			$view = $client->json( 'POST', '/jobs/' . $remote . '/input', $answer, Client::TICK_TIMEOUT )['json'];
		}
		unset( $job->state['input']['confirm'], $job->state['input']['safetyBackup'] ); // Only once the other site has it.
		$job->state['remote']['confirmed'] = true; // From here a cancel is deferred until the other site ends (ruling I4).

		return $view;
	}

	/**
	 * @param Job          $job    Job.
	 * @param Client       $client The link's client.
	 * @param array<mixed> $view   The other site's checked job view.
	 * @throws LinkException When the other site failed, was cancelled or asks what only it can answer.
	 */
	private function settle( Job $job, Client $client, array $view ): ?StepResult {
		$status = $view['status'];
		if ( Job::DONE === $status ) {
			return $this->pull ? $this->ready( $job, $client, $view ) : StepResult::done(
				/* translators: %s: the other site's name */
				sprintf( __( '%s is restored.', 'lw-migration' ), self::peer( $job ) )
			);
		}
		if ( Job::FAILED === $status ) {
			/* translators: %s: the other site's error message */
			throw new LinkException( LinkException::REMOTE, sprintf( __( 'The other site stopped: %s', 'lw-migration' ), self::text( $view['error'] ?? '' ) ) );
		}
		if ( Job::CANCELLED === $status ) {
			throw new LinkException( LinkException::REMOTE, __( 'The run was cancelled on the other site.', 'lw-migration' ) );
		}
		if ( Job::NEEDS_INPUT === $status ) {
			$input = is_array( $view['input'] ?? null ) ? $view['input'] : [];
			if ( ! $this->pull && 'confirm' === ( $input['kind'] ?? null ) && is_array( $input['summary'] ?? null ) ) {
				return StepResult::needsInput( 'confirm', self::plain( $input['summary'] ) ); // JobView filters it again on its way out.
			}
			throw new LinkException( LinkException::REMOTE, __( 'The other site asked a question this site cannot answer, so the run was stopped.', 'lw-migration' ) );
		}

		return null;
	}

	/**
	 * Pull: the archive is ready on the other site.
	 *
	 * @param Job          $job    Job.
	 * @param Client       $client The link's client.
	 * @param array<mixed> $view   The other site's job view.
	 * @throws LinkException PROTOCOL without a plausible archive size or its SHA-256.
	 */
	private function ready( Job $job, Client $client, array $view ): StepResult {
		$size   = $view['archiveBytes'] ?? null;
		$sha256 = $view['archiveSha256'] ?? null;
		if ( ! is_int( $size ) || $size < 2 * Format::HEADER_SIZE || ! is_string( $sha256 ) || 1 !== preg_match( ArchiveDigest::PATTERN, $sha256 ) ) {
			throw new LinkException( LinkException::PROTOCOL, __( 'The other site finished without an archive to download.', 'lw-migration' ) );
		}
		$job->state['download'] = [
			'url'    => $client->urlOf( '/jobs/' . $job->state['remote']['job'] . '/archive' ),
			'size'   => $size,
			'ranges' => true,
			'sha256' => $sha256, // From the signed view; PullDownloadStep checks the file against it (issue #1).
		];

		/* translators: %s: the other site's name */
		return StepResult::done( sprintf( __( 'The backup of %s is ready to download.', 'lw-migration' ), self::peer( $job ) ) );
	}

	/**
	 * Checks the other site's view and keeps what this site shows of it in
	 * state.remote: its job id, status, overall percentage (as the job
	 * screen counts it), status line, point of no return and safety backup.
	 *
	 * @param Job   $job  Job.
	 * @param mixed $view The other site's answer.
	 * @return array<mixed> The checked view.
	 * @throws LinkException PROTOCOL for an answer that is not a job view of the expected job.
	 */
	private function remember( Job $job, $view ): array {
		if ( ! self::isView( $view ) || ( '' !== (string) ( $job->state['remote']['job'] ?? '' ) && $view['id'] !== $job->state['remote']['job'] ) ) {
			throw new LinkException( LinkException::PROTOCOL, __( 'The other site sent an answer this site does not understand. Update LW Migration on both sites.', 'lw-migration' ) );
		}
		$steps  = max( 1, count( $view['steps'] ) );
		$done   = Job::DONE === $view['status'];
		$safety = $view['result']['safetyBackup'] ?? null;
		$remote = [
			'job'          => $view['id'],
			'status'       => $view['status'],
			'percent'      => $done ? 100 : min( 99, intdiv( min( $steps, max( 0, $view['stepIndex'] ) ) * 100 + min( 100, max( 0, $view['progress'] ) ), $steps ) ),
			'message'      => self::text( $view['message'] ?? '' ),
			'pastNoReturn' => true === ( $view['pastNoReturn'] ?? null ), // Shown only: never decides a cancel here (LinkPipeline::pastNoReturn()).
		] + $this->moved( $job, $view );
		if ( is_string( $safety ) && BackupName::isValid( $safety ) ) {
			$remote['safetyBackupName'] = $safety;
		}
		$job->state['remote'] = array_merge( (array) ( $job->state['remote'] ?? [] ), $remote );

		return $view;
	}

	/**
	 * When the other site's job last changed (status, step, progress or
	 * text): a job that shows the same for LinkPipeline::STALL_SECONDS is
	 * given up on, so endless "running" answers never hold this job and the
	 * site lock.
	 *
	 * @param Job          $job  Job.
	 * @param array<mixed> $view The other site's checked view.
	 * @return array{fingerprint: string, changedAt: int}
	 * @throws LinkException When the other site stopped making progress.
	 */
	private function moved( Job $job, array $view ): array {
		$now   = (int) ( $this->now )();
		$print = sha1( (string) wp_json_encode( [ $view['status'], $view['stepIndex'], $view['progress'], $view['message'] ?? '' ] ) );
		$since = $job->state['remote']['changedAt'] ?? null;
		if ( ( $job->state['remote']['fingerprint'] ?? null ) !== $print || ! is_int( $since ) ) {
			return [
				'fingerprint' => $print,
				'changedAt'   => $now,
			];
		}
		if ( $now - $since >= LinkPipeline::STALL_SECONDS ) {
			throw new LinkException( LinkException::REMOTE, __( 'The other site stopped making progress.', 'lw-migration' ) );
		}

		return [
			'fingerprint' => $print,
			'changedAt'   => $since,
		];
	}

	/**
	 * @param mixed $view The other site's answer.
	 */
	private static function isView( $view ): bool {
		if ( ! is_array( $view ) || ! is_string( $view['id'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{16}\z/', $view['id'] ) ) {
			return false;
		}
		$steps = $view['steps'] ?? null;
		if ( ! is_array( $steps ) || array_values( $steps ) !== $steps || count( array_filter( $steps, 'is_string' ) ) !== count( $steps ) ) {
			return false;
		}

		return in_array( $view['status'] ?? null, self::STATUSES, true )
			&& is_int( $view['stepIndex'] ?? null ) && is_int( $view['progress'] ?? null )
			&& is_string( $view['message'] ?? '' ) && is_string( $view['error'] ?? '' );
	}

	/**
	 * A text of the other site as plain, reduced and clipped text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		return self::clean( trim( AddressRedactor::reduce( wp_strip_all_tags( is_string( $value ) ? $value : '' ) ) ) );
	}

	/**
	 * The other site's summary with every text cleaned (no control
	 * characters, at most TEXT_BYTES), its numbers and flags as they are;
	 * anything nested deeper than a few levels, and anything else, is left out.
	 *
	 * @param array<mixed> $data  Summary.
	 * @param int          $depth Nesting so far.
	 * @return array<mixed>
	 */
	public static function plain( array $data, int $depth = 0 ): array {
		$plain = [];
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$plain[ $key ] = self::clean( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
				$plain[ $key ] = $value;
			} elseif ( is_array( $value ) && $depth < 4 ) {
				$plain[ $key ] = self::plain( $value, $depth + 1 );
			}
		}

		return $plain;
	}

	/**
	 * @param string $text Text of the other site.
	 */
	private static function clean( string $text ): string {
		return Text::clip( (string) preg_replace( '/[\x00-\x1f\x7f]/', '', $text ), self::TEXT_BYTES );
	}
}

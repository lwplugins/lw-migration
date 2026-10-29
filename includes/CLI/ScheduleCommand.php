<?php
/**
 * `wp lw-migration schedule …`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Schedules\CronDriver;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Queue;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Schedules\ScheduleJobs;
use LightweightPlugins\Migration\Services;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Lists backup schedules, runs them, shows their run logs. Schedules are
 * created and changed in wp-admin.
 */
final class ScheduleCommand {

	/**
	 * Lists the backup schedules with their next run (site time) and last result.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args  Unused.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function list_( array $args, array $assoc ): void {
		$services = Services::get();
		Events::healSite( $services, time() ); // WP-Cron may be off: the events an import took come back here too.
		$items = [];
		foreach ( $services->schedules()->all() as $schedule ) {
			$next    = $schedule->enabled ? Events::nextRun( $schedule->id ) : null;
			$last    = Log::last( $schedule->id );
			$items[] = [
				'id'        => $schedule->id,
				'title'     => Repository::shownTitle( $schedule ),
				'enabled'   => $schedule->enabled ? 'yes' : 'no',
				'frequency' => $schedule->frequency,
				'next'      => null === $next ? '' : (string) wp_date( 'Y-m-d H:i', $next ),
				'last'      => null === $last ? '' : (string) $last['status'],
			];
		}
		Console::items( (string) ( $assoc['format'] ?? 'table' ), $items, [ 'id', 'title', 'enabled', 'frequency', 'next', 'last' ] );
	}

	/**
	 * Runs one schedule now, or every schedule whose time has come.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Schedule id (see `wp lw-migration schedule list`). Runs it now, even when it is switched off; no e-mail is sent.
	 *
	 * [--due]
	 * : Runs every schedule whose time has come, one after the other, the way WP-Cron would (with its e-mails). For a server cron on sites with few visitors or with WP-Cron switched off. Exit status 1 when a run failed or could not be continued, 2 when the due schedules wait for another backup or restore.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration schedule run 0a1b2c3d
	 *     # From the server's crontab, every 5 minutes:
	 *     wp lw-migration schedule run --due --path=/var/www/site --quiet
	 *
	 * @param array<int, string>   $args  Schedule id.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function run( array $args, array $assoc ): void {
		$due   = ! empty( $assoc['due'] );
		$hasId = [] !== $args;
		if ( $due === $hasId ) {
			Console::error( __( 'Give a schedule id or --due, not both.', 'lw-migration' ) );
			return;
		}
		if ( $due ) {
			$this->runDue();
			return;
		}
		$schedule = self::find( (string) $args[0] );
		if ( null !== $schedule ) {
			$this->runOne( $schedule );
		}
	}

	/**
	 * Shows the last runs of a schedule, newest first.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule id.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array<int, string>   $args  Schedule id.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function log( array $args, array $assoc ): void {
		$schedule = self::find( (string) ( $args[0] ?? '' ) );
		if ( null === $schedule ) {
			return;
		}
		$format = (string) ( $assoc['format'] ?? 'table' );
		$items  = [];
		foreach ( Log::all( $schedule->id ) as $entry ) {
			$items[] = [
				'ended'   => (string) wp_date( 'Y-m-d H:i', (int) $entry['endedAt'] ),
				'trigger' => $entry['trigger'],
				'status'  => $entry['status'],
				'backup'  => $entry['backup'],
				'size'    => 'table' === $format ? size_format( (int) $entry['size'], 1 ) : (int) $entry['size'],
				'mail'    => $entry['mail'],
				'error'   => $entry['error'],
			];
		}
		Console::items( $format, $items, [ 'ended', 'trigger', 'status', 'backup', 'size', 'mail', 'error' ] );
	}

	/**
	 * A manual run in this process, with the usual progress output.
	 *
	 * @param Schedule $schedule Schedule.
	 */
	private function runOne( Schedule $schedule ): void {
		$services = Services::get();
		$secrets  = new MemorySecretStore();
		try {
			$job = ScheduleJobs::fromServices( $services, $secrets )->start( $schedule, RunRecorder::MANUAL, time() );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		try {
			$done = Drive::run( $services, $job->id, $secrets );
		} catch ( \RuntimeException $e ) { // Ctrl-C, lost lock, busy job, store errors.
			Console::error( Drive::stop( $services, $job->id, $secrets, $e ) );
			return;
		}
		/* translators: %s: backup file name */
		Console::log( sprintf( __( 'Backup file: %s', 'lw-migration' ), (string) ( $done->state['backup']['name'] ?? '' ) ) );
		CloudLines::conclude( $done ); // A partial result is a warning, not a failure: the backup exists.
	}

	/**
	 * Fires the overdue schedule events here (WP-Cron may be off): each plans
	 * its next run and queues this one, then every queued run is driven to its
	 * end and the new log entries are reported.
	 */
	private function runDue(): void {
		$services  = Services::get();
		$schedules = $services->schedules();
		$driver    = CronDriver::fromServices( $services );
		$queue     = new Queue();
		$now       = time();
		$before    = [];
		$recorder  = RunRecorder::fromServices( $services );
		Events::healSite( $services, $now ); // The server cron line may be the only driver: an event an import took comes back.
		try {
			foreach ( $schedules->existing() as $schedule ) {
				$before[ $schedule->id ] = Log::last( $schedule->id );
				$next                    = $schedule->enabled ? Events::nextRun( $schedule->id ) : null;
				if ( null !== $next && $next <= $now ) {
					Events::due( $schedules, $queue, $schedule->id, $now, $driver->runningSchedule(), $recorder );
				}
			}
		} catch ( \RuntimeException $e ) { // The queue kept changing under this call (Queue::change()).
			Console::error( __( 'The due schedules could not be queued because other requests changed the queue at the same time. Run the command again.', 'lw-migration' ) );
			return;
		}
		$result = $driver->work( null );
		$this->conclude( $this->report( $schedules->existing(), $before ), $result );
	}

	/**
	 * Prints one line per run that ended during this call.
	 *
	 * @param array<int, Schedule>                          $schedules Schedules.
	 * @param array<string, array<string, int|string>|null> $before    Newest log entry of each before the run.
	 * @return array{0: int, 1: int} Runs that ended, runs that failed.
	 */
	private function report( array $schedules, array $before ): array {
		$ran    = 0;
		$failed = 0;
		foreach ( $schedules as $schedule ) {
			$last     = Log::last( $schedule->id );
			$previous = $before[ $schedule->id ] ?? null;
			if ( null === $last || $previous === $last ) {
				continue;
			}
			++$ran;
			$cloud = isset( $last['cloud'] ) && is_array( $last['cloud'] ) ? $last['cloud'] : [];
			if ( 'done' === $last['status'] ) {
				/* translators: 1: schedule name, 2: backup file name */
				Console::log( sprintf( __( '%1$s: backup ready (%2$s).', 'lw-migration' ), Repository::shownTitle( $schedule ), (string) $last['backup'] ) );
				CloudLines::printLog( $cloud );
				continue;
			}
			if ( RunRecorder::PARTIAL === $last['status'] ) { // The backup exists: a warning, not a failed run (exit code 0).
				/* translators: 1: schedule name, 2: backup file name */
				Console::warning( sprintf( __( '%1$s: backup ready (%2$s), but not every upload succeeded.', 'lw-migration' ), Repository::shownTitle( $schedule ), (string) $last['backup'] ) );
				CloudLines::printLog( $cloud );
				continue;
			}
			++$failed;
			/* translators: 1: schedule name, 2: reason */
			Console::warning( sprintf( __( '%1$s: %2$s', 'lw-migration' ), Repository::shownTitle( $schedule ), '' !== (string) $last['error'] ? (string) $last['error'] : (string) $last['status'] ) );
		}

		return [ $ran, $failed ];
	}

	/**
	 * The exit status: 1 when a run failed or a job could not be continued,
	 * 2 when the due schedules wait for another job (neither a failure nor
	 * done), 0 otherwise.
	 *
	 * @param array{0: int, 1: int} $counts Runs that ended, runs that failed.
	 * @param string                $result CronDriver::work() result.
	 */
	private function conclude( array $counts, string $result ): void {
		[ $ran, $failed ] = $counts;
		$waiting          = CronDriver::BUSY === $result || CronDriver::ELSEWHERE === $result;
		if ( $waiting ) {
			Console::warning( __( 'Due schedules are waiting: another backup or restore is running. They start when it has ended.', 'lw-migration' ) );
		}
		if ( $failed > 0 ) {
			/* translators: %d: number of failed runs */
			Console::error( sprintf( _n( '%d scheduled backup failed. See: wp lw-migration schedule log <id>', '%d scheduled backups failed. See: wp lw-migration schedule log <id>', $failed, 'lw-migration' ), $failed ) );
			return;
		}
		if ( CronDriver::STUCK === $result ) {
			Console::error( __( 'A scheduled backup cannot save its progress (the disk may be full). It is tried again later; when it still cannot save, it is stopped and logged as failed.', 'lw-migration' ) );
			return;
		}
		if ( CronDriver::MORE === $result ) {
			Console::error( __( 'The due schedules did not finish in this run. Run the command again.', 'lw-migration' ) );
			return;
		}
		if ( $waiting ) {
			WP_CLI::halt( 2 );
		}
		Console::success( 0 === $ran ? __( 'Nothing was due.', 'lw-migration' ) : __( 'The due schedules have run.', 'lw-migration' ) );
	}

	/**
	 * @param string $id Schedule id from the command line.
	 * @return Schedule|null Null after the error was printed (Console::error() halts).
	 */
	private static function find( string $id ): ?Schedule {
		$schedule = 1 === preg_match( Schedule::ID_PATTERN, $id ) ? Services::get()->schedules()->find( $id ) : null;
		if ( null === $schedule ) {
			Console::error( __( 'There is no schedule with this id. See: wp lw-migration schedule list', 'lw-migration' ) );
		}

		return $schedule;
	}
}

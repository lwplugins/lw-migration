<?php
/**
 * The WP-Cron side of the schedules.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Job\FailureReporter;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Support\ControlChars;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 17: one single WP-Cron event per enabled schedule (HOOK, argument: the
 * schedule id) at its next run time; WordPress' cron table is the only place
 * that time is kept. A firing event plans the next one first, queues the run
 * (coalescing) and lets CronDriver work. CONTINUE_HOOK is the fallback when
 * a loopback request does not arrive. Healing (admin requests except
 * admin-ajax, cron requests, activation, the REST schedule list)
 * gives every enabled schedule its event back — an import replaces the cron
 * table but keeps the schedules — and takes it from a disabled one, and asks
 * for a continuation while work is left and none is planned; an event
 * of an unknown id is dropped when it fires. On a network everything runs and
 * is planned on the main site only (the schedules are its options). A failure inside a cron hook is written to the PHP
 * error log without the site's folders; it never stops the other events.
 */
final class Events {

	public const HOOK = 'lw_migration_schedule';

	public const CONTINUE_HOOK = 'lw_migration_schedule_continue';

	public const LATE_AFTER = 900;

	private const HEAL_CONTINUE = 60;

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'fire' ] );
		add_action( self::CONTINUE_HOOK, [ self::class, 'continueWork' ] );
		add_action( 'admin_init', [ self::class, 'healAll' ] );
		add_action( 'init', [ self::class, 'healOnCron' ] );
		add_action( 'update_option_timezone_string', [ self::class, 'retime' ] );
		add_action( 'update_option_gmt_offset', [ self::class, 'retime' ] );
	}

	/**
	 * The next run is planned first, on its own: whatever happens to this
	 * run, the schedule keeps its event.
	 *
	 * @param mixed $id The event's argument (a schedule id).
	 */
	public static function fire( $id ): void {
		if ( ! MainSite::isContext() || ! is_string( $id ) ) {
			return;
		}
		$now      = time();
		$schedule = null;
		self::guarded(
			static function () use ( $id, $now, &$schedule ): void {
				$schedule = self::plan( Services::get()->schedules(), $id, $now );
			}
		);
		if ( null === $schedule ) {
			return;
		}
		self::guarded(
			static function () use ( $schedule, $now ): void {
				$services = Services::get();
				$driver   = CronDriver::fromServices( $services );
				self::enqueue( new Queue(), $schedule->id, $now, $driver->runningSchedule(), self::skipped( RunRecorder::fromServices( $services ), $schedule, $now ) );
				$driver->work( CronDriver::budget() );
			}
		);
	}

	public static function continueWork(): void {
		if ( ! MainSite::isContext() ) {
			return;
		}
		self::guarded(
			static function (): void {
				CronDriver::fromServices( Services::get() )->work( CronDriver::budget() );
			}
		);
	}

	/**
	 * Plans the next run and queues this one (plan() then enqueue()).
	 *
	 * @param Repository       $schedules Schedules.
	 * @param Queue            $queue     Due runs.
	 * @param string           $id        Schedule id.
	 * @param int              $now       Unix time.
	 * @param string|null      $running   Schedule of the site's current unfinished job (CronDriver::runningSchedule()).
	 * @param RunRecorder|null $recorder  Logs a waiting run this one replaced (enqueue()).
	 * @return bool Whether the run was queued (false: unknown, disabled, running or already waiting).
	 */
	public static function due( Repository $schedules, Queue $queue, string $id, int $now, ?string $running = null, ?RunRecorder $recorder = null ): bool {
		$schedule = self::plan( $schedules, $id, $now );

		return null !== $schedule && self::enqueue( $queue, $id, $now, $running, null === $recorder ? null : self::skipped( $recorder, $schedule, $now ) );
	}

	/**
	 * Gives a due schedule the event of its next run; an unknown or disabled
	 * one loses its event.
	 *
	 * @param Repository $schedules Schedules.
	 * @param string     $id        Schedule id.
	 * @param int        $now       Unix time.
	 * @return Schedule|null The schedule whose run is due, or null when none runs.
	 */
	public static function plan( Repository $schedules, string $id, int $now ): ?Schedule {
		$schedule = 1 === preg_match( Schedule::ID_PATTERN, $id ) ? $schedules->find( $id ) : null;
		if ( null === $schedule || ! $schedule->enabled ) {
			self::unschedule( $id );

			return null;
		}
		self::sync( $schedule, $now );

		return $schedule;
	}

	/**
	 * Queues the run, unless this schedule's own run is still going:
	 * overlapping runs coalesce (decision 5), so a run that outlasts its
	 * interval is not followed by another right away.
	 *
	 * A waiting run past the skip limit that this one replaced is handed to
	 * $replaced once, after the queue was written (never inside its write).
	 *
	 * @param Queue         $queue    Due runs.
	 * @param string        $id       Schedule id.
	 * @param int           $now      Unix time.
	 * @param string|null   $running  Schedule of the site's current unfinished job.
	 * @param \Closure|null $replaced fn( int $due ): void — the replaced run (skipped()).
	 * @return bool Whether it was queued.
	 */
	public static function enqueue( Queue $queue, string $id, int $now, ?string $running, ?\Closure $replaced = null ): bool {
		if ( $id === $running ) {
			return false;
		}
		$stale = null;
		$added = $queue->add( $id, $now, $stale );
		if ( null !== $stale && null !== $replaced ) {
			$replaced( $stale );
		}

		return $added;
	}

	/**
	 * Logs a replaced waiting run as skipped (its run log and mail rules apply).
	 *
	 * @param RunRecorder $recorder Run recorder.
	 * @param Schedule    $schedule The schedule.
	 * @param int         $now      Unix time.
	 * @return \Closure fn( int $due ): void.
	 */
	public static function skipped( RunRecorder $recorder, Schedule $schedule, int $now ): \Closure {
		return static function () use ( $recorder, $schedule, $now ): void {
			$recorder->notStarted( $schedule, RunRecorder::CRON, RunRecorder::SKIPPED, CronDriver::skipReason(), $now );
		};
	}

	/**
	 * Replaces the schedule's event by the one of its next run (none when disabled).
	 *
	 * @param Schedule $schedule Schedule.
	 * @param int      $now      Unix time.
	 */
	public static function sync( Schedule $schedule, int $now ): void {
		self::unschedule( $schedule->id );
		if ( $schedule->enabled && MainSite::isContext() ) {
			wp_schedule_single_event( NextRun::after( $schedule, $now, wp_timezone() ), self::HOOK, [ $schedule->id ] );
		}
	}

	public static function unschedule( string $id ): void {
		wp_clear_scheduled_hook( self::HOOK, [ $id ] );
	}

	/**
	 * Gives every enabled schedule its event back and takes it from a disabled
	 * one; asks for a continuation while work is left and none is planned (an
	 * import replaced the cron table under a waiting run or a running job).
	 *
	 * @param array<int, Schedule> $schedules Stored schedules.
	 * @param int                  $now       Unix time.
	 * @param \Closure             $workLeft  fn(): bool — whether a run waits or a scheduled job is unfinished (workLeft()).
	 */
	public static function heal( array $schedules, int $now, \Closure $workLeft ): void {
		foreach ( $schedules as $schedule ) {
			$planned = false !== wp_next_scheduled( self::HOOK, [ $schedule->id ] );
			if ( $schedule->enabled && ! $planned ) {
				self::sync( $schedule, $now );
			} elseif ( ! $schedule->enabled && $planned ) {
				self::unschedule( $schedule->id );
			}
		}
		if ( false === wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			self::guarded(
				static function () use ( $workLeft ): void {
					if ( $workLeft() ) {
						self::continueIn( self::HEAL_CONTINUE );
					}
				}
			);
		}
	}

	/**
	 * Whether scheduled work is left: a run waits in the queue, or a schedule's job is unfinished.
	 *
	 * @param Queue    $queue   Due runs.
	 * @param \Closure $running fn(): ?string — the schedule whose job is running (CurrentJob::runningSchedule()).
	 * @return \Closure fn(): bool.
	 */
	public static function workLeft( Queue $queue, \Closure $running ): \Closure {
		return static fn(): bool => [] !== $queue->all() || null !== $running();
	}

	/**
	 * Heals the stored schedules' events (admin requests, activation, the
	 * schedule list); never on admin-ajax requests (the heartbeat would read
	 * the schedules every minute).
	 */
	public static function healAll(): void {
		if ( MainSite::isContext() && ! wp_doing_ajax() ) {
			self::healSite( Services::get(), time() );
		}
	}

	/**
	 * Heals the stored schedules with the site's queue and jobs (also
	 * from the command line, where WP-Cron may be off).
	 *
	 * @param Services $services Services.
	 * @param int      $now      Unix time.
	 */
	public static function healSite( Services $services, int $now ): void {
		$running = static function () use ( $services ): ?string {
			// No valid storage folder name stored: no job either, and this read path writes nothing (StorageDir::fromWordPress() would).
			$storage = StorageDir::stored();

			return null === $storage ? null : CurrentJob::fromServices( $services, new JobStore( $storage->jobsDir() ) )->runningSchedule();
		};
		self::heal( $services->schedules()->existing(), $now, self::workLeft( new Queue(), $running ) );
	}

	/**
	 * The site time zone changed (update_option_timezone_string / _gmt_offset):
	 * the planned run times are wall-clock times in it, so they are planned again.
	 */
	public static function retime(): void {
		if ( MainSite::isContext() ) {
			self::guarded(
				static function (): void {
					self::resync( Services::get()->schedules()->existing(), time() );
				}
			);
		}
	}

	/**
	 * Plans every enabled schedule's next run again and takes the event of a
	 * disabled one. An event whose time has come stays: it fires and plans the
	 * next run itself, so a pending run is never dropped.
	 *
	 * @param array<int, Schedule> $schedules Stored schedules.
	 * @param int                  $now       Unix time.
	 */
	public static function resync( array $schedules, int $now ): void {
		foreach ( $schedules as $schedule ) {
			$next = self::nextRun( $schedule->id );
			if ( ! $schedule->enabled ) {
				if ( null !== $next ) {
					self::unschedule( $schedule->id );
				}
			} elseif ( null === $next || $next > $now ) {
				self::sync( $schedule, $now );
			}
		}
	}

	public static function healOnCron(): void {
		if ( wp_doing_cron() ) {
			self::healAll();
		}
	}

	/**
	 * One fallback continuation at a time.
	 *
	 * @param int $seconds Delay.
	 */
	public static function continueIn( int $seconds ): void {
		if ( false === wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + $seconds, self::CONTINUE_HOOK );
		}
	}

	public static function nextRun( string $id ): ?int {
		$next = wp_next_scheduled( self::HOOK, [ $id ] );

		return false === $next ? null : (int) $next;
	}

	/**
	 * Decision 18, computed on read (nothing is written to detect it).
	 *
	 * @param array<int, Schedule> $schedules Schedules.
	 * @param int                  $now       Unix time.
	 * @param bool|null            $disabled  Whether WP-Cron is off (default: DISABLE_WP_CRON).
	 * @return array{disabled: bool, late: bool}
	 */
	public static function health( array $schedules, int $now, ?bool $disabled = null ): array {
		$late = false;
		foreach ( $schedules as $schedule ) {
			$next = $schedule->enabled ? self::nextRun( $schedule->id ) : null;
			$late = $late || ( null !== $next && $next < $now - self::LATE_AFTER );
		}

		return [
			'disabled' => $disabled ?? ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'late'     => $late,
		];
	}

	public static function unscheduleAll(): void {
		wp_unschedule_hook( self::HOOK );
		wp_unschedule_hook( self::CONTINUE_HOOK );
	}

	/**
	 * @param \Closure $work fn(): void.
	 */
	private static function guarded( \Closure $work ): void {
		try {
			$work();
		} catch ( \Throwable $e ) {
			$paths = [ ABSPATH, WP_CONTENT_DIR ];
			try {
				$paths = Services::get()->redactPaths(); // With the storage folder.
			} catch ( \Throwable $unknown ) {
				unset( $unknown ); // No storage folder yet: the site's folders still go.
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a cron hook has no other place to report a failure.
			error_log( 'LW Migration schedules: ' . get_class( $e ) . ': ' . ControlChars::escape( FailureReporter::redactWith( $e->getMessage(), $paths ) ) );
		}
	}
}

<?php
/**
 * The schedules of the site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A list of Schedule::toArray() rows in one option (autoload off). Only
 * admins write it (REST); the cron side only reads. When the option was
 * never written, the first read through all() (the list a person looks at)
 * creates the default schedule — disabled, so nothing runs before someone
 * switches it on (spec 17), and created at read time because its title is
 * translated in the language of the request (the text domain is not loaded
 * during activation). existing() and find() never create it: cron and finish
 * paths read without writing. An emptied list stays empty. A row that does
 * not load (bad id, not an array) is skipped. The repository owns the
 * anchor the "every N" counting starts from: a new schedule, a changed
 * timing and switching it on restart it from now.
 */
final class Repository {

	public const MAX = 20;

	/**
	 * Title of the default schedule, stored as this source text (never in the
	 * language of whoever read first) and translated where it is shown.
	 */
	public const DEFAULT_TITLE = 'Daily backup';

	/**
	 * Clock.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * Checks that a stored password is a seal; built on first use.
	 *
	 * @var SealedPassword|null
	 */
	private ?SealedPassword $passwords;

	/**
	 * @param \Closure|null       $now       fn(): int (default: time()).
	 * @param SealedPassword|null $passwords Opens a password before it is stored (default: the site key).
	 */
	public function __construct( ?\Closure $now = null, ?SealedPassword $passwords = null ) {
		$this->now       = $now ?? static fn(): int => time();
		$this->passwords = $passwords;
	}

	/**
	 * @return array<int, Schedule> In stored order.
	 */
	public function all(): array {
		$stored = $this->stored();
		if ( null === $stored ) {
			$default = self::defaultSchedule( $this->now() );
			add_option( Options::SCHEDULES, [ $default->toArray() ], '', false );
			// Core's add_option() is an upsert: when two first reads race, the row
			// written last stays, so both answer with what is stored.
			wp_cache_delete( Options::SCHEDULES, 'options' );
			$stored = $this->stored();
		}

		return $stored ?? [];
	}

	/**
	 * The stored schedules, without creating the default (for cron, healing
	 * and finished runs: nothing is written there).
	 *
	 * @return array<int, Schedule>
	 */
	public function existing(): array {
		return $this->stored() ?? [];
	}

	/**
	 * @param string $id Schedule id.
	 * @return Schedule|null The stored schedule; never creates the default.
	 */
	public function find( string $id ): ?Schedule {
		foreach ( $this->existing() as $schedule ) {
			if ( $schedule->id === $id ) {
				return $schedule;
			}
		}

		return null;
	}

	/**
	 * Replaces the schedule with the same id, or appends it. The anchor is
	 * set to now for a new schedule, a changed timing (switching it on
	 * included) or a stored row without one; otherwise the stored anchor stays.
	 * The anchor is written into the passed object itself, so the caller sees
	 * what was stored. A password must be a seal that opens: a plain one can
	 * never reach the option. The one exception is the seal already stored for
	 * this schedule, byte for byte: after the salts changed it no longer opens,
	 * but the schedule must still be switched off, renamed or edited (the run
	 * fails and asks for the password again). A new or different value that
	 * does not open is still refused.
	 *
	 * @param Schedule $schedule Schedule (its anchor is updated).
	 * @return void
	 * @throws ScheduleException When a new schedule would exceed MAX.
	 * @throws \InvalidArgumentException When the password is not a seal of this site (a caller bug, never shown).
	 */
	public function save( Schedule $schedule ): void {
		$list = $this->all();
		if ( '' !== $schedule->password && ! $this->storedSeal( $list, $schedule ) && null === $this->passwords()->open( $schedule->password ) ) {
			throw new \InvalidArgumentException( 'A schedule password is stored only sealed.' );
		}
		$found = false;
		foreach ( $list as $i => $stored ) {
			if ( $stored->id === $schedule->id ) {
				$schedule->anchor = $this->anchor( $stored, $schedule );
				$list[ $i ]       = $schedule;
				$found            = true;
			}
		}
		if ( ! $found ) {
			if ( count( $list ) >= self::MAX ) {
				/* translators: %d: largest number of schedules */
				throw new ScheduleException( sprintf( __( 'A site can have at most %d schedules.', 'lw-migration' ), self::MAX ) );
			}
			$schedule->anchor = $this->now();
			$list[]           = $schedule;
		}
		$this->write( $list );
	}

	/**
	 * Removes the schedule and its run log; its backups stay.
	 *
	 * @param string $id Schedule id.
	 * @return bool Whether it existed.
	 */
	public function delete( string $id ): bool {
		$list = $this->all();
		$kept = array_values( array_filter( $list, static fn( Schedule $s ): bool => $s->id !== $id ) );
		if ( count( $kept ) === count( $list ) ) {
			return false;
		}
		$this->write( $kept );
		Log::clear( $id );

		return true;
	}

	/**
	 * The title as people see it: the untouched default in the reader's
	 * language, any title a user saved as it is.
	 *
	 * @param Schedule $schedule Schedule.
	 */
	public static function shownTitle( Schedule $schedule ): string {
		return self::DEFAULT_TITLE === $schedule->title ? __( 'Daily backup', 'lw-migration' ) : $schedule->title;
	}

	/**
	 * @param int $now Unix time.
	 */
	public static function defaultSchedule( int $now ): Schedule {
		return new Schedule(
			Schedule::newId(),
			self::DEFAULT_TITLE,
			false,
			Schedule::DAILY,
			1,
			0,
			3,
			1,
			1,
			$now,
			[],
			'',
			[
				'count' => 7,
				'bytes' => 0,
				'days'  => 0,
			],
			Schedule::NOTIFY_FAILURE,
			'',
			$now,
			$now
		);
	}

	/**
	 * @return array<int, Schedule>|null Null when the option was never written.
	 */
	private function stored(): ?array {
		$stored = get_option( Options::SCHEDULES, null );
		if ( null === $stored ) {
			return null;
		}
		$all = [];
		foreach ( is_array( $stored ) ? $stored : [] as $row ) {
			try {
				$all[] = Schedule::fromArray( is_array( $row ) ? $row : [] );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		return $all;
	}

	/**
	 * @param Schedule $stored   As stored.
	 * @param Schedule $schedule As it is saved.
	 * @return int The anchor to store.
	 */
	private function anchor( Schedule $stored, Schedule $schedule ): int {
		$timing = static fn( Schedule $s ): array => array_diff_key( $s->timing(), [ 'anchor' => true ] );

		return $stored->anchor > 0 && $timing( $stored ) === $timing( $schedule ) ? $stored->anchor : $this->now();
	}

	/**
	 * @param array<int, Schedule> $list     Stored schedules.
	 * @param Schedule             $schedule As it is saved.
	 * @return bool Whether its password is exactly the one stored for it.
	 */
	private function storedSeal( array $list, Schedule $schedule ): bool {
		foreach ( $list as $stored ) {
			if ( $stored->id === $schedule->id ) {
				return hash_equals( $stored->password, $schedule->password );
			}
		}

		return false;
	}

	private function passwords(): SealedPassword {
		$this->passwords ??= new SealedPassword();

		return $this->passwords;
	}

	private function now(): int {
		return (int) ( $this->now )();
	}

	/**
	 * @param array<int, Schedule> $list Schedules.
	 */
	private function write( array $list ): void {
		// An unchanged list makes update_option() return false; that is not a failure.
		update_option( Options::SCHEDULES, array_values( array_map( static fn( Schedule $s ): array => $s->toArray(), $list ) ), false );
	}
}

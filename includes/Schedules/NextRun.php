<?php
/**
 * When a schedule runs next.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

defined( 'ABSPATH' ) || exit;

/**
 * Wall-clock times in the site's time zone. Daily, every N days, weekly and
 * monthly runs are calendar based: a local time that does not exist on a
 * spring-forward day is moved forward by the gap (PHP's normalisation), an
 * ambiguous autumn time is always its first occurrence (so it runs once); the
 * monthly day is clamped to the month's last day. Hourly and every-N-hours
 * runs step in real hours from their first run (always 3600 s apart, also
 * across a DST change). An "every" below 1 counts as 1. The result is always
 * strictly after $now.
 */
final class NextRun {

	private const HOUR = 3600;

	private const DAY = 86400;

	public static function after( Schedule $s, int $now, \DateTimeZone $tz ): int {
		return match ( $s->frequency ) {
			Schedule::HOURLY  => self::hourly( $s->minute, 1, $now, $now, $tz ),
			Schedule::HOURS   => self::hourly( $s->minute, $s->every, self::anchor( $s, $now ), $now, $tz ),
			Schedule::DAYS    => self::days( $s, $now, $tz ),
			Schedule::WEEKLY  => self::weekly( $s, $now, $tz ),
			Schedule::MONTHLY => self::monthly( $s, $now, $tz ),
			default           => self::firstAfter( self::local( $now, $tz ), $s, $now, 1 ),
		};
	}

	/**
	 * @param Schedule      $s     Schedule.
	 * @param int           $now   Unix time.
	 * @param \DateTimeZone $tz    Site time zone.
	 * @param int           $count How many.
	 * @return array<int, int> Unix times, ascending.
	 */
	public static function upcoming( Schedule $s, int $now, \DateTimeZone $tz, int $count ): array {
		$runs = [];
		$at   = $now;
		for ( $i = 0; $i < $count; $i++ ) {
			$at     = self::after( $s, $at, $tz );
			$runs[] = $at;
		}

		return $runs;
	}

	/**
	 * Every run in the window (now, $until], stepping like upcoming().
	 *
	 * @param Schedule      $s     Schedule.
	 * @param int           $now   Unix time.
	 * @param \DateTimeZone $tz    Site time zone.
	 * @param int           $until Last moment of the window (inclusive).
	 * @param int           $limit At most this many runs.
	 * @return array<int, int> Unix times, ascending.
	 */
	public static function within( Schedule $s, int $now, \DateTimeZone $tz, int $until, int $limit ): array {
		$runs = [];
		$at   = self::after( $s, $now, $tz );
		for ( $i = 0; $i < $limit && $at <= $until; $i++ ) {
			$runs[] = $at;
			$at     = self::after( $s, $at, $tz );
		}

		return $runs;
	}

	/**
	 * The first moment at or after $anchor whose local minute is $minute, then every $every hours.
	 *
	 * @param int           $minute Minute of the hour.
	 * @param int           $every  Hours between runs.
	 * @param int           $anchor Counting starts here.
	 * @param int           $now    Unix time.
	 * @param \DateTimeZone $tz     Site time zone.
	 */
	private static function hourly( int $minute, int $every, int $anchor, int $now, \DateTimeZone $tz ): int {
		$local = self::local( $anchor, $tz );
		$base  = $anchor - ( (int) $local->format( 'i' ) * 60 + (int) $local->format( 's' ) ) + $minute * 60;
		if ( $base < $anchor ) {
			$base += self::HOUR;
		}
		if ( $base > $now ) {
			return $base;
		}
		$step = max( 1, $every ) * self::HOUR;

		return $base + ( intdiv( $now - $base, $step ) + 1 ) * $step;
	}

	private static function days( Schedule $s, int $now, \DateTimeZone $tz ): int {
		$every  = max( 1, $s->every );
		$today  = self::local( $now, $tz );
		$offset = ( self::dayNumber( $today ) - self::dayNumber( self::local( self::anchor( $s, $now ), $tz ) ) ) % $every;
		$first  = 0 === $offset ? $today : $today->modify( '+' . ( $every - $offset ) . ' days' );

		return self::firstAfter( $first, $s, $now, $every );
	}

	private static function weekly( Schedule $s, int $now, \DateTimeZone $tz ): int {
		$today = self::local( $now, $tz );
		$delta = ( $s->weekday - (int) $today->format( 'N' ) + 7 ) % 7;

		return self::firstAfter( $today->modify( '+' . $delta . ' days' ), $s, $now, 7 );
	}

	private static function monthly( Schedule $s, int $now, \DateTimeZone $tz ): int {
		$month = self::local( $now, $tz )->modify( 'first day of this month' );
		while ( true ) {
			$day = min( $s->monthday, (int) $month->format( 't' ) );
			$at  = self::at( $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day ), $s );
			if ( $at > $now ) {
				return $at;
			}
			$month = $month->modify( 'first day of next month' );
		}
	}

	/**
	 * The schedule's time on $day, or on $day + $step days, … — the first one after $now.
	 *
	 * @param \DateTimeImmutable $day  First candidate day (local).
	 * @param Schedule           $s    Schedule.
	 * @param int                $now  Unix time.
	 * @param int                $step Days between candidates.
	 */
	private static function firstAfter( \DateTimeImmutable $day, Schedule $s, int $now, int $step ): int {
		$at = self::at( $day, $s );
		while ( $at <= $now ) {
			$day = $day->modify( '+' . $step . ' days' );
			$at  = self::at( $day, $s );
		}

		return $at;
	}

	/**
	 * The schedule's wall time on $day. PHP resolves a wall time that happens
	 * twice (autumn) by the offset $day already carries, so the answer would
	 * depend on $now; it is always the first occurrence here: when the same
	 * wall time also existed under the earlier, larger offset, that moment wins.
	 *
	 * @param \DateTimeImmutable $day Day (local).
	 * @param Schedule           $s   Schedule.
	 */
	private static function at( \DateTimeImmutable $day, Schedule $s ): int {
		$time  = $day->setTime( $s->hour, $s->minute );
		$ts    = $time->getTimestamp();
		$shift = $time->setTimestamp( $ts - 2 * self::HOUR )->getOffset() - $time->getOffset();
		if ( $shift > 0 && $time->setTimestamp( $ts - $shift )->format( 'Y-m-d H:i' ) === $time->format( 'Y-m-d H:i' ) ) {
			return $ts - $shift;
		}

		return $ts;
	}

	private static function local( int $time, \DateTimeZone $tz ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( $tz );
	}

	/**
	 * Days since 1970-01-01 of the local calendar date (DST never shifts it).
	 *
	 * @param \DateTimeImmutable $local Local time.
	 */
	private static function dayNumber( \DateTimeImmutable $local ): int {
		return intdiv( ( new \DateTimeImmutable( $local->format( 'Y-m-d' ), new \DateTimeZone( 'UTC' ) ) )->getTimestamp(), self::DAY );
	}

	/**
	 * Counting starts at the anchor; a missing (0) or future anchor counts from now.
	 *
	 * @param Schedule $s   Schedule.
	 * @param int      $now Unix time.
	 */
	private static function anchor( Schedule $s, int $now ): int {
		return $s->anchor > 0 && $s->anchor <= $now ? $s->anchor : $now;
	}
}

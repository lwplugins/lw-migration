<?php
/**
 * The overview's day chart.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Days are the site's calendar days (the time zone of the site's settings,
 * never UTC): today and the days before it, oldest first; a day without a
 * backup is there with no bars. A day is covered when a backup was taken on
 * it (a failed run does not cover it).
 */
final class OverviewHistory {

	public const WINDOW = 30;

	public const COVERAGE = 14;

	/**
	 * @param array<int, array<string, mixed>> $items OverviewBackups::collect().
	 * @param int                              $now   Unix time.
	 * @param \DateTimeZone                    $tz    The site's time zone.
	 * @return array<int, array{day: string, backups: array<int, array{size: int|null, state: string, manual: bool}>}>
	 */
	public static function days( array $items, int $now, \DateTimeZone $tz ): array {
		$days = [];
		foreach ( self::calendar( $now, $tz, self::WINDOW ) as $day ) {
			$days[ $day ] = [];
		}
		foreach ( array_reverse( $items ) as $item ) { // Oldest first within a day too.
			$day = self::day( (int) $item['time'], $tz );
			if ( isset( $days[ $day ] ) ) {
				$days[ $day ][] = [
					'size'   => $item['size'],
					'state'  => $item['state'],
					'manual' => $item['manual'],
				];
			}
		}
		$list = [];
		foreach ( $days as $day => $backups ) {
			$list[] = [
				'day'     => (string) $day, // A numeric-looking key never turns into an int.
				'backups' => $backups,
			];
		}

		return $list;
	}

	/**
	 * @param array<int, array<string, mixed>> $items OverviewBackups::collect().
	 * @param int                              $now   Unix time.
	 * @param \DateTimeZone                    $tz    The site's time zone.
	 * @return array{window: int, covered: int, of: int}
	 */
	public static function coverage( array $items, int $now, \DateTimeZone $tz ): array {
		$recent  = array_fill_keys( self::calendar( $now, $tz, self::COVERAGE ), false );
		$covered = 0;
		foreach ( $items as $item ) {
			$day = self::day( (int) $item['time'], $tz );
			if ( OverviewBackups::FAILED !== $item['state'] && false === ( $recent[ $day ] ?? true ) ) {
				$recent[ $day ] = true;
				++$covered;
			}
		}

		return [
			'window'  => self::WINDOW,
			'covered' => $covered,
			'of'      => self::COVERAGE,
		];
	}

	/**
	 * @param int           $now   Unix time.
	 * @param \DateTimeZone $tz    Time zone.
	 * @param int           $count Days, today included.
	 * @return array<int, string> Y-m-d, oldest first.
	 */
	private static function calendar( int $now, \DateTimeZone $tz, int $count ): array {
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 12, 0 ); // Noon: a DST change never skips a day.
		$days  = [];
		for ( $back = $count - 1; $back >= 0; --$back ) {
			$days[] = $today->modify( '-' . $back . ' days' )->format( 'Y-m-d' );
		}

		return $days;
	}

	/**
	 * @param int           $time Unix time.
	 * @param \DateTimeZone $tz   Time zone.
	 */
	private static function day( int $time, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( '@' . $time ) )->setTimezone( $tz )->format( 'Y-m-d' );
	}
}

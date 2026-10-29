<?php
/**
 * Backup file names.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Schedules\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Generates names and validates names coming from users (CLI, REST).
 */
final class BackupName {

	private const SAFETY_PREFIX = 'pre-import-';

	private const SAFETY_PATTERN = '/^pre-import-\d{8}-\d{6}\.wpress\z/';

	private const PRE_RESET_PREFIX = 'pre-reset-';

	private const PRE_RESET_PATTERN = '/^pre-reset-\d{8}-\d{6}\.wpress\z/';

	private const SITE_TIME_PATTERN = '/-(\d{8}-\d{6})-\d{3}\.wpress\z/';

	private const SCHEDULE_TIME_PATTERN = '/-(\d{8}-\d{6})-\d{3}-([a-f0-9]{8})\.wpress\z/';

	private const TIME_FORMAT = 'Ymd-His';

	/**
	 * The start of every backup name of a site, and the name of its folder at a destination.
	 *
	 * @param string $homeUrl Site address.
	 */
	public static function siteSlug( string $homeUrl ): string {
		$host = (string) parse_url( $homeUrl, PHP_URL_HOST );
		$path = (string) parse_url( $homeUrl, PHP_URL_PATH );
		$slug = strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $host . $path ), '-' ) );
		if ( '' === $slug ) {
			$slug = 'site';
		} elseif ( str_starts_with( $slug, rtrim( self::SAFETY_PREFIX, '-' ) ) || str_starts_with( $slug, rtrim( self::PRE_RESET_PREFIX, '-' ) ) ) {
			$slug = 'site-' . $slug;
		}

		return rtrim( substr( $slug, 0, 100 ), '-' );
	}

	public static function forSite( string $homeUrl, int $time, int $suffix ): string {
		$slug = self::siteSlug( $homeUrl );

		return sprintf( '%s-%s-%03d.wpress', $slug, gmdate( self::TIME_FORMAT, $time ), abs( $suffix ) % 1000 );
	}

	/**
	 * A backup of a schedule: the site name with the schedule id as suffix (spec 17).
	 *
	 * @param string $homeUrl    Site address.
	 * @param int    $time       Unix time.
	 * @param int    $suffix     0-999 (taken modulo 1000).
	 * @param string $scheduleId Schedule id (8 hex digits).
	 * @throws \InvalidArgumentException On a malformed schedule id.
	 */
	public static function forSchedule( string $homeUrl, int $time, int $suffix, string $scheduleId ): string {
		if ( 1 !== preg_match( Schedule::ID_PATTERN, $scheduleId ) ) {
			throw new \InvalidArgumentException( 'Invalid schedule id.' );
		}

		return substr( self::forSite( $homeUrl, $time, $suffix ), 0, -strlen( '.wpress' ) ) . '-' . $scheduleId . '.wpress';
	}

	/**
	 * The schedule id of a name forSchedule() wrote, or null.
	 *
	 * @param string $name File name.
	 */
	public static function scheduleOf( string $name ): ?string {
		return 1 === preg_match( self::SCHEDULE_TIME_PATTERN, $name, $match ) ? $match[2] : null;
	}

	public static function safety( int $time ): string {
		return self::SAFETY_PREFIX . gmdate( self::TIME_FORMAT, $time ) . '.wpress';
	}

	/**
	 * The backup taken before a reset: never pruned, like a safety backup.
	 *
	 * @param int $time Unix time.
	 */
	public static function preReset( int $time ): string {
		return self::PRE_RESET_PREFIX . gmdate( self::TIME_FORMAT, $time ) . '.wpress';
	}

	/**
	 * Exactly the format preReset() writes.
	 *
	 * @param string $name File name.
	 */
	public static function isPreReset( string $name ): bool {
		return 1 === preg_match( self::PRE_RESET_PATTERN, $name );
	}

	/**
	 * Exactly the format safety() or preReset() writes: a backup taken before
	 * an import or a reset, which retention never prunes.
	 *
	 * @param string $name File name.
	 */
	public static function isSafety( string $name ): bool {
		return 1 === preg_match( self::SAFETY_PATTERN, $name ) || self::isPreReset( $name );
	}

	/**
	 * The UTC time written into a forSite() or forSchedule() name, or null when
	 * the name has none.
	 *
	 * @param string $name File name.
	 */
	public static function timeOf( string $name ): ?int {
		if ( 1 !== preg_match( self::SCHEDULE_TIME_PATTERN, $name, $match ) && 1 !== preg_match( self::SITE_TIME_PATTERN, $name, $match ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!' . self::TIME_FORMAT, $match[1], new \DateTimeZone( 'UTC' ) );

		return false !== $date && $date->format( self::TIME_FORMAT ) === $match[1] ? $date->getTimestamp() : null;
	}

	/**
	 * A plain file name: no folder parts, control characters or leading dot; ends in .wpress.
	 *
	 * @param string $name File name from a user (CLI, REST).
	 */
	public static function isValid( string $name ): bool {
		return '' !== $name
			&& strlen( $name ) <= 255
			&& ! str_starts_with( $name, '.' )
			&& 1 !== preg_match( '#[\x00-\x1f\x7f/\\\\]#', $name )
			&& Reader::hasArchiveExtension( $name );
	}

	/**
	 * A name forSite() or forSchedule() wrote for this site (its slug, a real
	 * time stamp): retention on a shared cloud folder never touches another
	 * site's backups or a file copied in by hand (plan D7).
	 *
	 * @param string $name    File name.
	 * @param string $homeUrl This site's address.
	 */
	public static function isOfSite( string $name, string $homeUrl ): bool {
		$slug = substr( self::forSite( $homeUrl, 0, 0 ), 0, -strlen( '-19700101-000000-000.wpress' ) );

		return 1 === preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d{8}-\d{6}-\d{3}(?:-[a-f0-9]{8})?\.wpress\z/', $name ) && null !== self::timeOf( $name );
	}
}

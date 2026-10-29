<?php
/**
 * Database-wide reservation of an import run id.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The four-character run id (lwtXXXX_) is shared by every site in the
 * database, while each site registers its runs in its own options. Before an
 * import uses an id it creates lwyXXXX_lwm_run without IF NOT EXISTS: the
 * CREATE is atomic across all sites, so only one can hold the id. The marker
 * sits in the run's lwy namespace, so every site's "prefix in use" check sees
 * it; TableSwap never renames or drops it. It goes last: only once the run
 * has no other table and the site has forgotten it. The marker carries the
 * reserving job's random token as its table comment, so a job that died
 * between reserving and registering can tell its own marker from another
 * site's on replay.
 */
final class RunMarker {

	public const SUFFIX = 'lwm_run';

	private const PATTERN = '/^lwy[a-z0-9]{4}_lwm_run\z/';

	private const TOKEN_PATTERN = '/^[0-9a-f]{16}\z/';

	/** MySQL/MariaDB ER_TABLE_EXISTS_ERROR. */
	private const TABLE_EXISTS = 1050;

	/**
	 * @param string $tmpPrefix Temporary prefix (lwtXXXX_).
	 */
	public static function name( string $tmpPrefix ): string {
		return 'lwy' . substr( $tmpPrefix, 3, 5 ) . self::SUFFIX;
	}

	/**
	 * @param string $table Table name.
	 */
	public static function is( string $table ): bool {
		return 1 === preg_match( self::PATTERN, $table );
	}

	/** A random token for reserve() (16 hex characters). */
	public static function newToken(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * @param Connection $db        Database.
	 * @param string     $tmpPrefix Temporary prefix (lwtXXXX_).
	 * @param string     $token     newToken() of the reserving job, kept as the table comment ('' = none).
	 * @return bool False when any site already holds the id.
	 * @throws QueryException On any other database error.
	 */
	public static function reserve( Connection $db, string $tmpPrefix, string $token = '' ): bool {
		$comment = 1 === preg_match( self::TOKEN_PATTERN, $token ) ? " COMMENT='" . $token . "'" : '';
		try {
			$db->execute( 'CREATE TABLE ' . DumpFormat::quoteIdentifier( self::name( $tmpPrefix ) ) . ' (id TINYINT NOT NULL PRIMARY KEY)' . $comment );
		} catch ( QueryException $e ) {
			if ( self::TABLE_EXISTS === $e->errno() ) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	/**
	 * Whether the run's marker exists and carries $token (reserved by that job).
	 *
	 * @param Connection $db        Database.
	 * @param string     $tmpPrefix Temporary prefix (lwtXXXX_).
	 * @param string     $token     Token given to reserve().
	 * @throws QueryException When the table list cannot be read.
	 */
	public static function heldWith( Connection $db, string $tmpPrefix, string $token ): bool {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return false;
		}
		$rows = $db->query( "SELECT TABLE_COMMENT AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->escape( self::name( $tmpPrefix ) ) . "'" );

		return isset( $rows[0] ) && (string) ( $rows[0]['c'] ?? '' ) === $token;
	}

	/**
	 * Drops the marker of a run with no other table left, after $forget removed
	 * the run from the site's registry (in this order: a marker dropped first
	 * would free the id while this site still treats it as its own).
	 *
	 * @param Connection $db        Database.
	 * @param string     $tmpPrefix Temporary prefix (lwtXXXX_).
	 * @param \Closure   $forget    fn( string $runId ): bool.
	 * @return bool Whether the run was forgotten and its marker dropped.
	 * @throws QueryException When the tables cannot be listed or the marker cannot be dropped.
	 */
	public static function releaseIfUnused( Connection $db, string $tmpPrefix, \Closure $forget ): bool {
		$prefixes = OrphanTables::runPrefixes( $tmpPrefix );
		foreach ( $db->query( 'SHOW FULL TABLES' ) as $row ) {
			$name = (string) array_values( $row )[0]; // Base tables and views.
			foreach ( $prefixes as $prefix ) {
				if ( 0 === stripos( $name, $prefix ) && ! self::is( $name ) ) {
					return false; // Case-insensitive: errs on keeping the reservation.
				}
			}
		}
		if ( true !== $forget( OrphanTables::runId( $tmpPrefix ) ) ) {
			return false;
		}
		self::drop( $db, $tmpPrefix );

		return true;
	}

	/**
	 * Drops the marker (a run this site never registered, or one just forgotten).
	 *
	 * @param Connection $db        Database.
	 * @param string     $tmpPrefix Temporary prefix (lwtXXXX_).
	 */
	public static function drop( Connection $db, string $tmpPrefix ): void {
		$db->execute( 'DROP TABLE IF EXISTS ' . DumpFormat::quoteIdentifier( self::name( $tmpPrefix ) ) );
	}
}

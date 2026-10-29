<?php
/**
 * Runs work with an empty session sql_mode, then restores the caller's.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Dumps and imports need sql_mode = '' (zero dates, lax inserts); the
 * connection is shared with the host application, so its mode comes back
 * afterwards, also when the work throws.
 */
final class SessionMode {

	/**
	 * @param Connection        $db   Connection.
	 * @param callable(): mixed $work Work to run.
	 * @return mixed What $work returned.
	 * @throws \Throwable Whatever $work threw (the mode is restored first, best effort).
	 */
	public static function relaxed( Connection $db, callable $work ): mixed {
		$rows  = $db->query( 'SELECT @@SESSION.sql_mode AS m' );
		$saved = isset( $rows[0]['m'] ) ? (string) $rows[0]['m'] : null;
		$db->execute( "SET SESSION sql_mode = ''" );
		try {
			$result = $work();
		} catch ( \Throwable $e ) {
			try {
				self::restore( $db, $saved );
			} catch ( DatabaseException $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the original error is the one to report.
				unset( $ignored );
			}
			throw $e;
		}
		self::restore( $db, $saved );

		return $result;
	}

	private static function restore( Connection $db, ?string $saved ): void {
		if ( null !== $saved ) {
			$db->execute( "SET SESSION sql_mode = '" . SqlEscape::escape( $saved ) . "'" );
		}
	}
}

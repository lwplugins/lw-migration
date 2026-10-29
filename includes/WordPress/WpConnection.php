<?php
/**
 * The database connection WordPress already opened.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\MysqliConnection;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps $wpdb's mysqli link; no second connection is opened. The dumper and
 * importer check its charset and relax its sql_mode themselves.
 */
final class WpConnection {

	/**
	 * @throws DatabaseException When $wpdb has no single open mysqli link (missing,
	 *                           spread over several servers, or replaced by a db.php drop-in).
	 */
	public static function fromWpdb(): Connection {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		$read = is_object( $wpdb ) && method_exists( $wpdb, '__get' );
		$dbh  = $read ? $wpdb->__get( 'dbh' ) : null;
		if ( null === $dbh || property_exists( $wpdb, 'dbhs' ) ) {
			throw new DatabaseException( 'The site uses a database drop-in that spreads queries over several servers (or has no open connection); export/import needs a single database connection.' );
		}
		if ( ! $dbh instanceof \mysqli ) {
			throw new DatabaseException( 'LW Migration needs the standard MySQL connection of WordPress, but a database drop-in (db.php) replaced it.' );
		}
		$name = (string) $wpdb->__get( 'dbname' );
		if ( '' === $name && defined( 'DB_NAME' ) ) {
			$name = (string) DB_NAME;
		}

		return new MysqliConnection( $dbh, defined( 'DB_HOST' ) ? (string) DB_HOST : '', $name );
	}
}

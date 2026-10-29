<?php
/**
 * Base class for tests that need a real MySQL/MariaDB (bin/test-db.sh).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration;

use LightweightPlugins\Migration\Database\MysqliConnection;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase {

	private static ?MysqliConnection $connection = null;

	protected function setUp(): void {
		if ( false === getenv( 'LWM_DB_HOST' ) ) {
			$this->markTestSkipped( 'Run bin/test-db.sh to execute integration tests.' );
		}
		$this->dropEverything();
	}

	protected static function db(): MysqliConnection {
		if ( null === self::$connection ) {
			mysqli_report( MYSQLI_REPORT_OFF );
			$link = mysqli_init();
			if ( false === $link ) {
				throw new \RuntimeException( 'mysqli_init() failed.' );
			}
			$ok = $link->real_connect(
				(string) getenv( 'LWM_DB_HOST' ),
				(string) getenv( 'LWM_DB_USER' ),
				(string) getenv( 'LWM_DB_PASS' ),
				(string) getenv( 'LWM_DB_NAME' ),
				(int) getenv( 'LWM_DB_PORT' )
			);
			if ( ! $ok ) {
				throw new \RuntimeException( 'Cannot connect to the test database: ' . $link->connect_error );
			}
			$link->set_charset( 'utf8mb4' );
			self::$connection = new MysqliConnection( $link, (string) getenv( 'LWM_DB_HOST' ), (string) getenv( 'LWM_DB_NAME' ) );
		}

		return self::$connection;
	}

	protected function exec( string ...$sql ): void {
		foreach ( $sql as $statement ) {
			self::db()->execute( $statement );
		}
	}

	private function dropEverything(): void {
		$db = self::db();
		$db->execute( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $db->query( 'SHOW FULL TABLES' ) as $row ) {
			$values = array_values( $row );
			$kind   = 'VIEW' === $values[1] ? 'VIEW' : 'TABLE';
			$db->execute( sprintf( 'DROP %s IF EXISTS `%s`', $kind, str_replace( '`', '``', (string) $values[0] ) ) );
		}
		$db->execute( 'SET FOREIGN_KEY_CHECKS=1' );
	}
}

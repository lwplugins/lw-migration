<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\WordPress;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\MysqliConnection;
use LightweightPlugins\Migration\WordPress\WpConnection;
use PHPUnit\Framework\TestCase;

final class WpConnectionTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_refuses_a_replaced_database_driver(): void {
		$GLOBALS['wpdb'] = new class() {
			public function __get( string $name ) {
				return 'dbh' === $name ? new \stdClass() : null;
			}
		};
		$this->expectException( DatabaseException::class );
		WpConnection::fromWpdb();
	}

	public function test_refuses_a_missing_wpdb(): void {
		$this->expectException( DatabaseException::class );
		WpConnection::fromWpdb();
	}

	public function test_refuses_a_multi_server_wpdb(): void {
		$GLOBALS['wpdb'] = new class( mysqli_init() ) {
			/** @var array<string, \mysqli> */
			public array $dbhs = [];

			public function __construct( private \mysqli|false $link ) {
			}

			public function __get( string $name ) {
				return 'dbh' === $name ? $this->link : null;
			}
		};
		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( 'spreads queries over several servers' );
		WpConnection::fromWpdb();
	}

	public function test_refuses_a_wpdb_without_an_open_link(): void {
		$GLOBALS['wpdb'] = new class() {
			public function __get( string $name ) {
				return null;
			}
		};
		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( 'has no open connection' );
		WpConnection::fromWpdb();
	}

	public function test_wraps_the_mysqli_link_of_wpdb(): void {
		$link            = mysqli_init();
		$GLOBALS['wpdb'] = new class( $link ) {
			public function __construct( private \mysqli|false $link ) {
			}

			public function __get( string $name ) {
				return [
					'dbh'    => $this->link,
					'dbname' => 'site_db',
				][ $name ] ?? null;
			}
		};

		$db = WpConnection::fromWpdb();

		$this->assertInstanceOf( MysqliConnection::class, $db );
		$this->assertSame( 'site_db', $db->databaseName() );
	}
}

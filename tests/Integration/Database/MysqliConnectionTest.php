<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\MysqliConnection;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;

final class MysqliConnectionTest extends DatabaseTestCase {

	public function test_query_execute_and_null_values(): void {
		$this->exec( 'CREATE TABLE t (id INT PRIMARY KEY, v VARCHAR(10) NULL)', "INSERT INTO t VALUES (1,'a'),(2,NULL)" );
		$rows = self::db()->query( 'SELECT id, v FROM t ORDER BY id' );
		$this->assertSame( [ [ 'id' => '1', 'v' => 'a' ], [ 'id' => '2', 'v' => null ] ], $rows );
	}

	public function test_errors_throw_with_errno(): void {
		try {
			self::db()->execute( 'SELEC 1' );
			$this->fail( 'No exception' );
		} catch ( QueryException $e ) {
			$this->assertSame( 1064, $e->errno() );
			$this->assertSame( 'SELEC 1', $e->sql() );
		}
	}

	public function test_stream_delivers_rows_in_order(): void {
		$this->exec( 'CREATE TABLE t (id INT PRIMARY KEY, v VARCHAR(10) NULL)', "INSERT INTO t VALUES (1,'a'),(2,NULL),(3,'c')" );
		$seen  = [];
		$count = self::db()->stream( 'SELECT id, v FROM t ORDER BY id', static function ( array $row ) use ( &$seen ): void {
			$seen[] = $row;
		} );
		$this->assertSame( 3, $count );
		$this->assertSame( [ [ 'id' => '1', 'v' => 'a' ], [ 'id' => '2', 'v' => null ], [ 'id' => '3', 'v' => 'c' ] ], $seen );
		$this->assertSame( 0, self::db()->stream( 'SELECT 1 FROM DUAL WHERE 0', static function ( array $row ): void {
		} ) );
		// The link is usable again afterwards.
		$this->assertSame( '3', self::db()->query( 'SELECT COUNT(*) AS c FROM t' )[0]['c'] );
	}

	public function test_stream_error_throws_query_exception(): void {
		$this->expectException( QueryException::class );
		self::db()->stream( 'SELEC 1', static function ( array $row ): void {
		} );
	}

	public function test_server_info(): void {
		$db = self::db();
		$this->assertMatchesRegularExpression( '/\d+\.\d+\.\d+/', $db->serverVersion() );
		$this->assertGreaterThan( 1000, (int) $db->variable( 'max_allowed_packet' ) );
		$this->assertNull( $db->variable( 'no_such_variable_xyz' ) );
		$this->assertSame( (string) getenv( 'LWM_DB_NAME' ), $db->databaseName() );
		$this->assertSame( "a\\'b", $db->escape( "a'b" ) );
	}

	public function test_empty_result_is_empty_array(): void {
		$this->assertSame( [], self::db()->query( 'SELECT 1 FROM DUAL WHERE 0' ) );
	}

	public function test_errors_throw_in_exception_report_mode(): void {
		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
		try {
			$link = new \mysqli(
				(string) getenv( 'LWM_DB_HOST' ),
				(string) getenv( 'LWM_DB_USER' ),
				(string) getenv( 'LWM_DB_PASS' ),
				(string) getenv( 'LWM_DB_NAME' ),
				(int) getenv( 'LWM_DB_PORT' )
			);
			$conn = new MysqliConnection( $link, (string) getenv( 'LWM_DB_HOST' ), (string) getenv( 'LWM_DB_NAME' ) );
			try {
				$conn->execute( 'SELEC 1' );
				$this->fail( 'No exception' );
			} catch ( QueryException $e ) {
				$this->assertSame( 1064, $e->errno() );
				$this->assertSame( 'SELEC 1', $e->sql() );
			}
			$link->close();
		} finally {
			mysqli_report( MYSQLI_REPORT_OFF );
		}
	}
}

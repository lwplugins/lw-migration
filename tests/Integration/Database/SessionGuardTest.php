<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\ConnectionGuard;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Database\ViewReferences;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Issue #14 against a real server: the session checks, and views that read
 * another database, in the form the server itself prints them.
 */
final class SessionGuardTest extends DatabaseTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		$this->exec( 'SET NAMES utf8mb4', "SET SESSION sql_mode = DEFAULT" );
		TempDir::remove( $this->dir );
	}

	public function test_an_unsafe_client_charset_alone_stops_the_import(): void {
		$path = $this->dir . '/database.sql';
		file_put_contents( $path, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int) ENGINE=InnoDB;\n" );
		$this->exec( 'SET character_set_client = gbk' );
		$this->assertSame( 'utf8mb4', self::db()->query( 'SELECT @@character_set_connection AS cs' )[0]['cs'] );
		$plan = new ImportPlan( 'wp_', 'lwt1a2b_', PrefixRules::importPairs( 'wp_' ), new Replacer( [] ), new SchemaFixer( self::db()->serverVersion() ), $this->dir . '/views.sql', 1048576 );

		try {
			( new Importer( self::db(), new StatementReader( $path ), $plan ) )->run( new ImportCursor(), new NoDeadline() );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertStringContainsString( '(character_set_client)', $e->getMessage() );
		}
		$this->exec( 'SET NAMES utf8mb4' );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwt1a2b\\_%'" ) );
	}

	public function test_no_backslash_escapes_is_seen_on_a_real_session(): void {
		$this->exec( "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'" );

		$this->expectException( DatabaseException::class );
		ConnectionGuard::assertSqlMode( self::db() );
	}

	public function test_the_server_prints_a_same_database_view_without_another_database(): void {
		$this->exec( 'CREATE TABLE wp_t (a INT)', 'CREATE VIEW wp_v AS SELECT t.a FROM wp_t t' );
		$create = (string) self::db()->query( 'SHOW CREATE VIEW `wp_v`' )[0]['Create View'];

		$this->assertNull( ViewReferences::otherDatabase( $create, self::db()->databaseName() ) );
	}

	public function test_the_server_prints_a_cross_database_view_with_its_database(): void {
		$this->exec( 'CREATE VIEW wp_v AS SELECT t.TABLE_NAME FROM information_schema.TABLES t' );
		$create = (string) self::db()->query( 'SHOW CREATE VIEW `wp_v`' )[0]['Create View'];

		$this->assertSame( 'information_schema', ViewReferences::otherDatabase( $create, self::db()->databaseName() ) );
	}

	public function test_a_deferred_view_reading_another_database_is_not_created(): void {
		$this->exec( 'CREATE TABLE wp_t (a INT)' );
		$path = $this->dir . '/v.sql';
		file_put_contents( $path, "CREATE VIEW `wp_mine` AS select `wp_t`.`a` AS `a` from `wp_t`;\nCREATE VIEW `wp_leak` AS select `t`.`TABLE_NAME` AS `n` from `information_schema`.`TABLES` `t`;\n" );

		$warnings = ( new TableSwap( self::db(), 'lwt1a2b_', 'wp_' ) )->applyDeferred( $path );

		$views = array_map( static fn( array $row ): string => (string) array_values( $row )[0], self::db()->query( "SHOW FULL TABLES WHERE `Table_type` = 'VIEW'" ) );
		$this->assertSame( [ 'wp_mine' ], $views );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( '`wp_leak`', $warnings[0] );
	}
}

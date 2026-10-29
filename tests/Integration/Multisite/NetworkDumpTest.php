<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Multisite;

use LightweightPlugins\Migration\Database\DumpCursor;
use LightweightPlugins\Migration\Database\Dumper;
use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Database\TableLister;
use LightweightPlugins\Migration\Multisite\ExportScope;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * A network dump through Dumper + DumpOptions built from an ExportScope (decision 6).
 */
final class NetworkDumpTest extends DatabaseTestCase {

	/** Rows and table names of a single-site dump of the seed, written by the dumper before network dumps existed. */
	private const SINGLE_SITE = __DIR__ . '/../../Fixtures/sql/network-seed-single-site.txt';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		$this->exec( ...NetworkTables::create( 'wp_', [ 1, 2, 3 ] ) );
		$this->exec( ...NetworkTables::seed( 'wp_' ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_a_sites_export_of_blog_2_dumps_its_tables_and_its_users_only(): void {
		$sql = NetworkTables::dump( self::db(), new ExportScope( 'wp_', [ 1, 2, 3 ], [ 2 ] ), $this->dir . '/db.sql', true, true );

		$this->assertSame(
			[ 'SERVMASK_PREFIX_2_commentmeta', 'SERVMASK_PREFIX_2_comments', 'SERVMASK_PREFIX_2_options', 'SERVMASK_PREFIX_2_postmeta', 'SERVMASK_PREFIX_2_posts', 'SERVMASK_PREFIX_mainsite_blogmeta', 'SERVMASK_PREFIX_mainsite_blogs', 'SERVMASK_PREFIX_mainsite_registration_log', 'SERVMASK_PREFIX_mainsite_signups', 'SERVMASK_PREFIX_mainsite_site', 'SERVMASK_PREFIX_mainsite_sitemeta', 'SERVMASK_PREFIX_mainsite_usermeta', 'SERVMASK_PREFIX_mainsite_users' ],
			NetworkTables::createdTables( $sql )
		);
		$this->assertStringContainsString( "INSERT INTO `SERVMASK_PREFIX_mainsite_users` VALUES (2,'editor2',", $sql );
		$this->assertStringNotContainsString( "'author3'", $sql );
		$this->assertStringNotContainsString( "VALUES (1,'admin',", $sql );
		$this->assertStringContainsString( ",2,'SERVMASK_PREFIX_2_capabilities',", $sql );
		$this->assertStringContainsString( ",2,'nickname','editor2');", $sql );
		$this->assertStringNotContainsString( 'SERVMASK_PREFIX_mainsite_capabilities', $sql, 'Without the main site its keys stay home.' );
		$this->assertStringContainsString( "'SERVMASK_PREFIX_2_user_roles','a:0:{}'", $sql );
		$this->assertStringContainsString( "INSERT INTO `SERVMASK_PREFIX_2_comments` VALUES (1,0,'1','Nice');", $sql );
		$this->assertStringNotContainsString( "'Buy'", $sql );
		$this->assertStringNotContainsString( 'of_spam', $sql );
		$this->assertStringNotContainsString( 'Post 2 revision', $sql );
		$this->assertStringNotContainsString( 'of_revision', $sql );
		$this->assertStringContainsString( "'active_plugins','a:0:{}'", $sql );
		$this->assertStringNotContainsString( 'lw_migration_lock', $sql );
		$this->assertStringNotContainsString( 'ai1wm_status', $sql );
		$this->assertStringNotContainsString( 'active_sitewide_plugins', $sql );
		$this->assertStringContainsString( "'site_admins'", $sql );
		$this->assertSame( 3, substr_count( $sql, 'INSERT INTO `SERVMASK_PREFIX_mainsite_blogs`' ) );
	}

	public function test_a_sites_export_with_the_main_site_keeps_main_keys_and_filters_every_options_table(): void {
		foreach ( [ 'wp_options', 'wp_3_options' ] as $table ) {
			$this->exec( "INSERT INTO `{$table}` (option_name, option_value) VALUES ('_transient_lw_migration_t','s'),('_transient_timeout_lw_migration_t','1')" );
		}
		$sql = NetworkTables::dump( self::db(), new ExportScope( 'wp_', [ 1, 2, 3 ], [ 1, 3 ] ), $this->dir . '/db.sql' );

		$tables = NetworkTables::createdTables( $sql );
		$this->assertContains( 'SERVMASK_PREFIX_basesite_options', $tables );
		$this->assertContains( 'SERVMASK_PREFIX_3_posts', $tables );
		$this->assertContains( 'SERVMASK_PREFIX_mainsite_users', $tables );
		$this->assertNotContains( 'SERVMASK_PREFIX_2_posts', $tables );
		$this->assertStringContainsString( ",1,'SERVMASK_PREFIX_basesite_capabilities',", $sql );
		$this->assertStringContainsString( ",3,'SERVMASK_PREFIX_3_capabilities',", $sql );
		$this->assertStringNotContainsString( "'editor2'", $sql );
		$this->assertStringContainsString( "'SERVMASK_PREFIX_basesite_user_roles'", $sql );
		$this->assertSame( 2, substr_count( $sql, "'active_plugins','a:0:{}'" ) );
		$this->assertStringNotContainsString( 'lw_migration_lock', $sql, 'Our options leave every exported options table.' );
		$this->assertStringNotContainsString( 'lw_migration_t', $sql, 'Our transients too.' );
		$this->assertStringNotContainsString( 'ai1wm_status', $sql );
	}

	public function test_a_network_export_dumps_every_table_with_the_plain_placeholder(): void {
		$sql = NetworkTables::dump( self::db(), new ExportScope( 'wp_', [ 1, 2, 3 ], null ), $this->dir . '/db.sql' );

		$tables = NetworkTables::createdTables( $sql );
		$this->assertCount( 23, $tables );
		$this->assertContains( 'SERVMASK_PREFIX_users', $tables );
		$this->assertContains( 'SERVMASK_PREFIX_options', $tables );
		$this->assertContains( 'SERVMASK_PREFIX_3_posts', $tables );
		$this->assertStringContainsString( ",2,'SERVMASK_PREFIX_2_capabilities',", $sql );
		$this->assertStringContainsString( ",1,'SERVMASK_PREFIX_capabilities',", $sql );
		foreach ( [ 'admin', 'editor2', 'author3' ] as $login ) {
			$this->assertStringContainsString( "'{$login}'", $sql );
		}
		$this->assertStringContainsString( 'Post 2 revision', $sql );
		$this->assertStringContainsString( "'Buy'", $sql );
		$this->assertSame( 3, substr_count( $sql, "'active_plugins','a:0:{}'" ) );
		$this->assertStringNotContainsString( 'lw_migration_lock', $sql );
		$this->assertStringNotContainsString( 'active_sitewide_plugins', $sql );
	}

	public function test_a_single_site_dump_is_unchanged(): void {
		$path   = $this->dir . '/single.sql';
		$listed = ( new TableLister( self::db() ) )->list( [ 'wp_' ] );
		$dumper = new Dumper( self::db(), new DumpOptions( 'wp_', true, true, DumpOptions::STATUS_OPTIONS, [ 'lw_migration_' ] ), $listed['tables'], [], $path );
		$this->assertTrue( $dumper->run( new DumpCursor(), new NoDeadline() ) );

		$this->assertSame( (string) file_get_contents( self::SINGLE_SITE ), self::rowsAndNames( (string) file_get_contents( $path ) ) );
	}

	/**
	 * The server-independent part of a dump: every line but the header and the CREATE TABLE bodies.
	 *
	 * @param string $sql Dump.
	 * @return string
	 */
	public static function rowsAndNames( string $sql ): string {
		$out = [];
		foreach ( explode( "\n", $sql ) as $line ) {
			if ( 1 === preg_match( '/^CREATE TABLE `[^`]+`/', $line, $m ) ) {
				$out[] = $m[0];
			} elseif ( 1 === preg_match( '/^(INSERT|DROP|START|COMMIT)\b/', $line ) ) {
				$out[] = $line;
			}
		}

		return implode( "\n", $out ) . "\n";
	}
}

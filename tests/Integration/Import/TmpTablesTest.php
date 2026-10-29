<?php
/**
 * Writes into the temporary tables on a real MySQL/MariaDB: live tables are only read.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Import;

use LightweightPlugins\Migration\Import\TmpTables;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\DyingConnection;

final class TmpTablesTest extends DatabaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$this->exec( ...WpCoreTables::create( 'lwtab12_' ) );
		$this->exec(
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://live.test'),('home','https://live.test'),('lw_migration_lock','L'),('_transient_lw_migration_job_secret_x','S'),('lw_migration_x','keep')",
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('siteurl','https://old.test'),('lw_migration_backup_labels','archive'),('blogname','Archive')",
			"INSERT INTO wp_users (ID, user_login, user_pass) VALUES (1,'admin','\$P\$live')",
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'session_tokens','live-session'),(1,'wp_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(1,'wp_user_level','10'),(1,'nickname','live')"
		);
	}

	private function tables(): TmpTables {
		return new TmpTables( self::db(), 'lwtab12_', 'wp_' );
	}

	/** @return array<string, string> */
	private function options( string $table ): array {
		$out = [];
		foreach ( self::db()->query( "SELECT option_name, option_value FROM `{$table}`" ) as $row ) {
			$out[ (string) $row['option_name'] ] = (string) $row['option_value'];
		}
		ksort( $out );
		return $out;
	}

	/** @return array<string, string> */
	private function meta( int $userId ): array {
		$meta = [];
		foreach ( self::db()->query( "SELECT meta_key, meta_value FROM lwtab12_usermeta WHERE user_id = {$userId}" ) as $row ) {
			$meta[ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}
		ksort( $meta );
		return $meta;
	}

	/** @return array<string, array<int, array<string, string|null>>> Every live row, for before/after comparison. */
	private function liveSnapshot(): array {
		$out = [];
		foreach ( [ 'wp_options' => 'option_id', 'wp_users' => 'ID', 'wp_usermeta' => 'umeta_id' ] as $table => $key ) {
			$out[ $table ] = self::db()->query( "SELECT * FROM `{$table}` ORDER BY {$key}" );
		}
		return $out;
	}

	public function test_options_are_copied_and_set_in_the_temporary_table_only(): void {
		$t = $this->tables();
		$this->assertTrue( $t->has( 'options' ) );
		$this->assertFalse( $t->has( 'posts' ) );

		$t->copyOptions( [ 'siteurl', 'home', 'upload_path' ] );
		$t->copyOptionPrefixes( [ 'lw_migration_', '_transient_lw_migration_' ] );
		$t->setOption( 'active_plugins', 'a:0:{}' );
		$t->setOption( 'active_plugins', 'a:1:{i:0;s:1:"x";}' );

		$this->assertSame(
			[
				'_transient_lw_migration_job_secret_x' => 'S',
				'active_plugins'                       => 'a:1:{i:0;s:1:"x";}',
				'blogname'                             => 'Archive',
				'home'                                 => 'https://live.test',
				'lw_migration_lock'                    => 'L',
				'lw_migration_x'                       => 'keep',
				'siteurl'                              => 'https://live.test',
			],
			$this->options( 'lwtab12_options' )
		);
		$this->assertArrayNotHasKey( 'active_plugins', $this->options( 'wp_options' ) );
	}

	public function test_admin_with_the_same_login_gets_the_live_password_and_session(): void {
		$this->exec(
			"INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (5,'admin','\$P\$archive'),(6,'bob','\$P\$bob')",
			"INSERT INTO lwtab12_usermeta (user_id, meta_key, meta_value) VALUES (5,'session_tokens','old'),(5,'wp_capabilities','a:1:{s:6:\"editor\";b:1;}'),(5,'nickname','archive')"
		);
		$this->assertSame( 5, $this->tables()->keepAdmin( 1 ) );
		$this->assertSame( 5, $this->tables()->keepAdmin( 1 ), 'Idempotent.' );

		$this->assertSame( [ [ 'user_pass' => '$P$live' ] ], self::db()->query( 'SELECT user_pass FROM lwtab12_users WHERE ID = 5' ) );
		$this->assertSame( [ 'nickname' => 'archive', 'session_tokens' => 'live-session', 'wp_capabilities' => 'a:1:{s:13:"administrator";b:1;}', 'wp_user_level' => '10' ], $this->meta( 5 ) );
		$this->assertSame( [ [ 'user_pass' => '$P$bob' ] ], self::db()->query( 'SELECT user_pass FROM lwtab12_users WHERE ID = 6' ) );
	}

	public function test_admin_missing_from_the_archive_is_added(): void {
		$this->exec( "INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (1,'bob','\$P\$bob')" );
		$id = $this->tables()->keepAdmin( 1 );

		$this->assertNotNull( $id );
		$this->assertNotSame( 1, $id );
		$this->assertSame( [ [ 'user_login' => 'admin', 'user_pass' => '$P$live' ] ], self::db()->query( "SELECT user_login, user_pass FROM lwtab12_users WHERE ID = {$id}" ) );
		$this->assertCount( 4, self::db()->query( "SELECT * FROM lwtab12_usermeta WHERE user_id = {$id}" ) );
		$this->assertSame( $id, $this->tables()->keepAdmin( 1 ), 'Idempotent: the added admin is found by login.' );
		$this->assertCount( 2, self::db()->query( 'SELECT * FROM lwtab12_users' ) );
		$this->assertCount( 4, self::db()->query( "SELECT * FROM lwtab12_usermeta WHERE user_id = {$id}" ) );
		$this->assertNull( $this->tables()->keepAdmin( 999 ) );
	}

	public function test_live_tables_are_never_written(): void {
		$this->exec( "INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (5,'admin','\$P\$archive')" );
		$before = $this->liveSnapshot();

		$t = $this->tables();
		$t->copyOptions( [ 'siteurl', 'home' ] );
		$t->copyOptionPrefixes( [ 'lw_migration_' ] );
		$t->setOption( 'siteurl', 'https://changed.test' );
		$t->setOption( 'template', 'x' );
		$t->keepAdmin( 1 );
		$this->exec( 'DELETE FROM lwtab12_users' );
		$t->keepAdmin( 1 );

		$this->assertSame( $before, $this->liveSnapshot() );
	}

	public function test_like_wildcards_in_prefixes_match_literally(): void {
		$this->exec(
			"INSERT INTO wp_options (option_name, option_value) VALUES ('lwXmigrationXlive','no'),('lw%any','no')",
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('lwXmigrationXarchive','archive')"
		);
		$this->tables()->copyOptionPrefixes( [ 'lw_migration_' ] );

		$options = $this->options( 'lwtab12_options' );
		$this->assertArrayNotHasKey( 'lwXmigrationXlive', $options );
		$this->assertArrayNotHasKey( 'lw%any', $options );
		$this->assertSame( 'archive', $options['lwXmigrationXarchive'], 'A non-matching archive option is not deleted.' );
		$this->assertSame( 'keep', $options['lw_migration_x'] );
	}

	public function test_hostile_names_and_values_are_escaped_and_round_trip_exactly(): void {
		$name  = "evil' OR '1'='1";
		$value = 'a:2:{s:3:"k\'\\";s:5:"' . "\0\n\r\x1a\"" . '";i:0;s:2:"%_";}';
		$t     = $this->tables();
		$t->setOption( $name, $value );
		$t->setOption( $name, $value );
		$t->copyOptions( [ "x') OR ('1'='1" ] );
		$t->copyOptionPrefixes( [ "%' OR '1'='1" ] );

		$options = $this->options( 'lwtab12_options' );
		$this->assertSame( $value, $options[ $name ] );
		$this->assertSame( [ 'blogname', $name, 'lw_migration_backup_labels', 'siteurl' ], array_keys( $options ) );
		$this->assertSame( 'https://old.test', $options['siteurl'], 'Injected conditions neither copied nor deleted anything.' );
	}

	public function test_empty_lists_change_nothing(): void {
		$t = $this->tables();
		$t->copyOptions( [] );
		$t->copyOptionPrefixes( [] );

		$this->assertSame( [ 'blogname' => 'Archive', 'lw_migration_backup_labels' => 'archive', 'siteurl' => 'https://old.test' ], $this->options( 'lwtab12_options' ) );
	}

	public function test_a_crash_between_delete_and_insert_loses_nothing_and_replays(): void {
		$dying = new DyingConnection( self::db(), 'INSERT' );
		$dying->armAfter( 'DELETE' );
		try {
			( new TmpTables( $dying, 'lwtab12_', 'wp_' ) )->copyOptions( [ 'siteurl' ] );
			$this->fail( 'The simulated crash did not happen.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Connection lost.', $e->getMessage() );
		}
		$this->assertSame( 'https://old.test', $this->options( 'lwtab12_options' )['siteurl'], 'The DELETE was rolled back.' );

		$this->tables()->copyOptions( [ 'siteurl' ] );
		$this->assertSame( 'https://live.test', $this->options( 'lwtab12_options' )['siteurl'] );
	}

	public function test_a_crash_while_adding_the_admin_replays_with_all_meta(): void {
		$dying = new DyingConnection( self::db(), 'INSERT INTO `lwtab12_usermeta`' );
		$dying->armAfter( 'INSERT INTO `lwtab12_users`' );
		try {
			( new TmpTables( $dying, 'lwtab12_', 'wp_' ) )->keepAdmin( 1 );
			$this->fail( 'The simulated crash did not happen.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Connection lost.', $e->getMessage() );
		}
		$this->assertSame( [], self::db()->query( 'SELECT ID FROM lwtab12_users' ), 'The user INSERT was rolled back.' );

		$id = (int) $this->tables()->keepAdmin( 1 );
		$this->assertSame( [ 'nickname' => 'live', 'session_tokens' => 'live-session', 'wp_capabilities' => 'a:1:{s:13:"administrator";b:1;}', 'wp_user_level' => '10' ], $this->meta( $id ) );
	}

	public function test_a_myisam_replay_after_a_partial_add_recovers_the_missing_meta(): void {
		$this->exec( 'DROP TABLE lwtab12_options, lwtab12_users, lwtab12_usermeta', ...WpCoreTables::create( 'lwtab12_', 'MyISAM' ) );
		$this->exec( "INSERT INTO lwtab12_usermeta (user_id, meta_key, meta_value) VALUES (1,NULL,'null-key')" );
		$dying = new DyingConnection( self::db(), 'INSERT INTO `lwtab12_usermeta`' );
		$dying->armAfter( 'INSERT INTO `lwtab12_users`' );
		try {
			( new TmpTables( $dying, 'lwtab12_', 'wp_' ) )->keepAdmin( 1 );
			$this->fail( 'The simulated crash did not happen.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Connection lost.', $e->getMessage() );
		}
		$this->assertCount( 1, self::db()->query( 'SELECT ID FROM lwtab12_users' ), 'MyISAM keeps the user row despite the rollback.' );

		$id = (int) $this->tables()->keepAdmin( 1 );
		$this->assertSame( $id, $this->tables()->keepAdmin( 1 ) );
		$this->assertSame( [ 'nickname' => 'live', 'session_tokens' => 'live-session', 'wp_capabilities' => 'a:1:{s:13:"administrator";b:1;}', 'wp_user_level' => '10' ], $this->meta( $id ) );
		$this->assertCount( 4, self::db()->query( "SELECT umeta_id FROM lwtab12_usermeta WHERE user_id = {$id}" ), 'Replays add no duplicates.' );
	}

	public function test_live_meta_with_a_null_key_is_not_duplicated_on_replay(): void {
		$this->exec(
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,NULL,'odd')",
			"INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (5,'admin','\$P\$archive')"
		);
		$this->tables()->keepAdmin( 1 );
		$this->tables()->keepAdmin( 1 );

		$this->assertCount( 1, self::db()->query( 'SELECT umeta_id FROM lwtab12_usermeta WHERE user_id = 5 AND meta_key IS NULL' ) );
	}

	public function test_users_sharing_the_admins_email_or_nicename_are_listed(): void {
		$this->exec(
			"UPDATE wp_users SET user_email = 'a@live.test', user_nicename = 'admin' WHERE ID = 1",
			"INSERT INTO lwtab12_users (ID, user_login, user_email, user_nicename) VALUES (5,'owner','A@live.test','owner'),(6,'bob','bob@old.test','admin'),(7,'eve','','')"
		);
		$id = (int) $this->tables()->keepAdmin( 1 );

		$this->assertSame(
			[
				'owner' => [ 'user_email' ],
				'bob'   => [ 'user_nicename' ],
			],
			$this->tables()->sharedIdentity( $id )
		);
		$this->exec( "UPDATE lwtab12_users SET user_email = '', user_nicename = '' WHERE ID = {$id}" );
		$this->assertSame( [], $this->tables()->sharedIdentity( $id ), 'Empty values never clash.' );
		$this->assertSame( [], $this->tables()->sharedIdentity( 999 ) );
	}

	public function test_a_subsites_options_table_is_named_by_its_suffix_on_both_sides(): void {
		$this->exec(
			WpCoreTables::create( 'wp_2_' )[0],
			WpCoreTables::create( 'lwtab12_2_' )[0],
			"INSERT INTO wp_2_options (option_name, option_value) VALUES ('siteurl','https://live.test/b2'),('upload_path','')",
			"INSERT INTO lwtab12_2_options (option_name, option_value) VALUES ('siteurl','https://old.test/b2'),('upload_path','wp-content/blogs.dir/2/files')"
		);
		$before = $this->liveSnapshot();
		$t      = $this->tables();

		$t->copyOptions( [ 'siteurl', 'upload_path' ], '2_options' );
		$t->setOption( 'template', 'child', '2_options' );

		$this->assertSame( [ 'siteurl' => 'https://live.test/b2', 'template' => 'child', 'upload_path' => '' ], $this->options( 'lwtab12_2_options' ) );
		$this->assertSame( 'https://old.test', $this->options( 'lwtab12_options' )['siteurl'], 'The main site\'s temporary options are untouched.' );
		$this->assertSame( [ 'siteurl' => 'https://live.test/b2', 'upload_path' => '' ], $this->options( 'wp_2_options' ) );
		$this->assertSame( $before, $this->liveSnapshot() );
	}

	public function test_network_meta_is_copied_read_and_set_in_the_temporary_table_only(): void {
		$create = static fn( string $p ): string => "CREATE TABLE `{$p}sitemeta` (meta_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id BIGINT NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)";
		$this->exec(
			$create( 'wp_' ),
			$create( 'lwtab12_' ),
			"INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1,'ms_files_rewriting','1'),(1,'site_name','Live'),(2,'ms_files_rewriting','0')",
			"INSERT INTO lwtab12_sitemeta (site_id, meta_key, meta_value) VALUES (1,'ms_files_rewriting','0'),(1,'site_name','Archive'),(1,'site_admins','x')"
		);
		$live = self::db()->query( 'SELECT * FROM wp_sitemeta ORDER BY meta_id' );
		$t    = $this->tables();

		$t->copySiteMeta( 1, [ 'ms_files_rewriting', 'not_live' ] );
		$t->copySiteMeta( 1, [ 'ms_files_rewriting' ] );
		$t->setSiteMeta( 1, 'site_admins', "a:1:{i:0;s:4:\"bo'ss\";}" );
		$t->setSiteMeta( 1, 'site_admins', 'a:1:{i:0;s:4:"boss";}' );
		$t->copySiteMeta( 1, [] );

		$this->assertSame( '1', $t->siteMeta( 1, 'ms_files_rewriting' ) );
		$this->assertSame( 'Archive', $t->siteMeta( 1, 'site_name' ), 'Only the named keys are carried over.' );
		$this->assertSame( 'a:1:{i:0;s:4:"boss";}', $t->siteMeta( 1, 'site_admins' ) );
		$this->assertNull( $t->siteMeta( 1, 'not_live' ) );
		$this->assertNull( $t->siteMeta( 2, 'ms_files_rewriting' ), 'Another network\'s rows are not copied.' );
		$this->assertSame( [ '1' ], array_column( self::db()->query( "SELECT site_id FROM lwtab12_sitemeta WHERE meta_key = 'site_admins'" ), 'site_id' ), 'Replaced by key, once.' );
		$this->assertSame( $live, self::db()->query( 'SELECT * FROM wp_sitemeta ORDER BY meta_id' ), 'Live network meta untouched.' );
	}

	/**
	 * Ruling R16: a new site never takes an id whose tables or files may still exist.
	 */
	public function test_new_ids_of_a_temporary_table_start_above_the_live_and_archive_ones(): void {
		$create = static fn( string $p ): string => "CREATE TABLE `{$p}blogs` (blog_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(200) NOT NULL DEFAULT '')";
		$this->exec( $create( 'wp_' ), $create( 'lwtab12_' ), "INSERT INTO wp_blogs VALUES (1,'a'),(2,'b'),(4,'d')", "INSERT INTO lwtab12_blogs VALUES (1,'a'),(3,'c')" );
		$next = function (): int {
			$this->exec( "INSERT INTO lwtab12_blogs (domain) VALUES ('new')" );
			$id = (int) self::db()->query( "SELECT MAX(blog_id) AS m FROM lwtab12_blogs WHERE domain = 'new'" )[0]['m'];
			$this->exec( "DELETE FROM lwtab12_blogs WHERE domain = 'new'" );

			return $id;
		};

		$this->tables()->raiseAutoIncrement( 'blogs', 'blog_id' );
		$this->assertSame( 5, $next(), 'Above the live site 4 the archive does not have.' );

		$this->exec( 'DELETE FROM wp_blogs WHERE blog_id = 4', 'ALTER TABLE wp_blogs AUTO_INCREMENT = 40', "INSERT INTO lwtab12_blogs VALUES (9,'i')" );
		$this->tables()->raiseAutoIncrement( 'blogs', 'blog_id' );
		$this->assertSame( 40, $next(), 'A live counter already raised by an earlier restore is kept.' );

		$this->exec( 'ALTER TABLE wp_blogs AUTO_INCREMENT = 1', "INSERT INTO lwtab12_blogs VALUES (60,'z')" );
		$this->tables()->raiseAutoIncrement( 'blogs', 'blog_id' );
		$this->assertSame( 61, $next(), 'Above the archive\'s own highest id.' );
		$this->assertSame( [ '1', '2' ], array_column( self::db()->query( 'SELECT blog_id FROM wp_blogs ORDER BY blog_id' ), 'blog_id' ) );
	}

	public function test_a_table_suffix_or_column_that_is_not_an_identifier_is_refused(): void {
		foreach ( [ fn() => $this->tables()->setOption( 'x', 'y', 'options`; DROP TABLE wp_options; --' ), fn() => $this->tables()->copyOptions( [ 'x' ], '2_options WHERE 1' ), fn() => $this->tables()->raiseAutoIncrement( 'blogs', 'blog_id`' ) ] as $call ) {
			try {
				$call();
				$this->fail( 'An invalid name was accepted.' );
			} catch ( \LightweightPlugins\Migration\Database\DatabaseException $e ) {
				$this->assertStringContainsString( 'Invalid', $e->getMessage() );
			}
		}
		$this->assertTrue( $this->tables()->has( 'options' ) );
		$this->assertNotSame( [], self::db()->query( "SHOW TABLES LIKE 'wp\\_options'" ) );
	}

	public function test_a_network_is_moved_to_another_id_once_in_the_temporary_tables_only(): void {
		$tables = static fn( string $p ): array => [
			"CREATE TABLE `{$p}site` (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(200) NOT NULL DEFAULT '')",
			"CREATE TABLE `{$p}blogs` (blog_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id BIGINT NOT NULL DEFAULT 0)",
			"CREATE TABLE `{$p}sitemeta` (meta_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, site_id BIGINT NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)",
		];
		$this->exec(
			...$tables( 'wp_' ),
			...$tables( 'lwtab12_' ),
			...[
				"INSERT INTO wp_site VALUES (1,'live')",
				"INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (1,'site_name','Live')",
				"INSERT INTO lwtab12_site VALUES (1,'stale'),(2,'archive')",
				'INSERT INTO lwtab12_blogs VALUES (1,2),(2,2)',
				"INSERT INTO lwtab12_sitemeta (site_id, meta_key, meta_value) VALUES (1,'stale','x'),(2,'site_name','Archive')",
			]
		);
		$this->assertNull( $this->tables()->networkId(), 'Not one network row.' );
		$this->exec( 'DELETE FROM lwtab12_site WHERE id = 1' );
		$this->assertSame( 2, $this->tables()->networkId() );

		$this->tables()->moveNetwork( 2, 1 );
		$this->tables()->moveNetwork( 2, 1 );
		$this->tables()->moveNetwork( 1, 1 );

		$this->assertSame( 1, $this->tables()->networkId() );
		$this->assertSame( [ [ 'id' => '1', 'domain' => 'archive' ] ], self::db()->query( 'SELECT id, domain FROM lwtab12_site' ) );
		$this->assertSame( [ '1', '1' ], array_column( self::db()->query( 'SELECT site_id FROM lwtab12_blogs ORDER BY blog_id' ), 'site_id' ) );
		$this->assertSame( [ [ 'site_id' => '1', 'meta_key' => 'site_name', 'meta_value' => 'Archive' ] ], self::db()->query( 'SELECT site_id, meta_key, meta_value FROM lwtab12_sitemeta' ), 'A replay never deletes the moved rows.' );
		$this->assertSame( [ [ 'id' => '1', 'domain' => 'live' ] ], self::db()->query( 'SELECT id, domain FROM wp_site' ) );
		$this->assertSame( 'Live', (string) self::db()->query( 'SELECT meta_value FROM wp_sitemeta' )[0]['meta_value'] );
	}
}

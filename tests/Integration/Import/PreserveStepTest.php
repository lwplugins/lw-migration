<?php
/**
 * The preserve step on a real MySQL/MariaDB: what this site keeps goes into the temporary tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\Steps\PreserveStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class PreserveStepTest extends DatabaseTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		$this->dir = TempDir::create();
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$this->exec( ...WpCoreTables::create( 'lwtab12_' ) );
		$this->exec(
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://new.test'),('home','https://new.test'),('upload_path','files'),('lw_migration_lock','L'),('_transient_lw_migration_job_secret_x','S'),('template','live-parent'),('stylesheet','live-child')",
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('siteurl','https://elsewhere.test'),('home','https://elsewhere.test'),('lw_migration_backup_labels','from-archive'),('active_plugins','a:0:{}'),('template',''),('stylesheet','')",
			"INSERT INTO wp_users (ID, user_login, user_pass, user_email, user_nicename) VALUES (1,'admin','\$P\$live','admin@new.test','admin')",
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'session_tokens','live-session'),(1,'wp_capabilities','admin-caps'),(1,'wp_user_level','10')",
			"INSERT INTO lwtab12_users (ID, user_login, user_pass) VALUES (5,'admin','\$P\$archive'),(6,'bob','\$P\$bob')"
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @param string             $stylesheet Archive stylesheet.
	 * @param array<int, string> $plugins    Archive plugins.
	 * @param string             $home       This site's home URL.
	 */
	private function job( string $stylesheet, array $plugins = [ 'sample/sample.php', 'wps-hide-login/wps-hide-login.php', 'missing/missing.php' ], string $home = 'https://new.test' ): Job {
		$job                              = ImportJobFixture::make( new JobStore( $this->dir . '/jobs' ), $this->dir, '/unused.wpress' );
		$job->state['package']            = [
			'Plugins'    => $plugins,
			'Template'   => 'twentytwentyfive',
			'Stylesheet' => $stylesheet,
		];
		$job->state['hasDatabase']        = true;
		$job->state['tmpPrefix']          = 'lwtab12_';
		$job->state['admin']              = [
			'id'    => 1,
			'login' => 'admin',
		];
		$job->state['site']['template']   = 'live-parent';
		$job->state['site']['stylesheet'] = 'live-child';
		$job->state['site']['homeUrl']    = $home;
		return $job;
	}

	private function step(): PreserveStep {
		return new PreserveStep(
			static fn() => self::db(),
			static fn(): array => [
				'lw-migration/lw-migration.php'                   => '1.0.0',
				'sample/sample.php'                               => '1.0',
				'wps-hide-login/wps-hide-login.php'               => '1.9',
				'really-simple-ssl/rlrsssl-really-simple-ssl.php' => '9.0',
				'lw-migration-copy/lw-migration.php'              => '1.0.0',
				'revslider/revslider.php'                         => 'dev',
				'revslider-old/revslider.php'                     => '5.0',
			],
			static fn( string $slug ): bool => 'twentytwentyfive' === $slug
		);
	}

	private function tmpOption( string $name ): ?string {
		$rows = self::db()->query( "SELECT option_value FROM lwtab12_options WHERE option_name = '{$name}'" );
		return [] === $rows ? null : (string) $rows[0]['option_value'];
	}

	public function test_preserves_site_address_options_and_admin_session(): void {
		$job = $this->job( 'twentytwentyfive' );
		$this->assertSame( 'Kept this site\'s address, settings and your login.', $this->step()->run( $job, new NoDeadline() )->message() );
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone(), 'Safe to run again.' );
		$this->assertTrue( $job->state['loginKept'], 'JobView tells the browser the login was kept.' );

		$this->assertSame( 'https://new.test', $this->tmpOption( 'siteurl' ) );
		$this->assertSame( 'https://new.test', $this->tmpOption( 'home' ) );
		$this->assertSame( 'files', $this->tmpOption( 'upload_path' ) );
		$this->assertSame( 'L', $this->tmpOption( 'lw_migration_lock' ) );
		$this->assertSame( 'S', $this->tmpOption( '_transient_lw_migration_job_secret_x' ) );
		$this->assertNull( $this->tmpOption( 'lw_migration_backup_labels' ), "The archive's own plugin settings do not replace this site's." );
		$this->assertSame( serialize( [ 'lw-migration/lw-migration.php', 'sample/sample.php' ] ), $this->tmpOption( 'active_plugins' ) );
		$this->assertSame( 'twentytwentyfive', $this->tmpOption( 'template' ) );
		$this->assertSame( 'twentytwentyfive', $this->tmpOption( 'stylesheet' ) );
		$this->assertSame(
			[
				'wps-hide-login/wps-hide-login.php' => 'lockout',
				'missing/missing.php'               => 'missing',
			],
			$job->state['plugins']['removed']
		);

		$this->assertSame( [ [ 'user_pass' => '$P$live' ] ], self::db()->query( 'SELECT user_pass FROM lwtab12_users WHERE ID = 5' ) );
		$this->assertSame( [ [ 'meta_value' => 'live-session' ] ], self::db()->query( "SELECT meta_value FROM lwtab12_usermeta WHERE user_id = 5 AND meta_key = 'session_tokens'" ) );
		$this->assertSame( [ [ 'option_value' => 'L' ] ], self::db()->query( "SELECT option_value FROM wp_options WHERE option_name = 'lw_migration_lock'" ), 'Live tables untouched.' );
		$this->assertSame( [ [ 'user_pass' => '$P$live' ] ], self::db()->query( 'SELECT user_pass FROM wp_users WHERE ID = 1' ) );

		$this->assertSame( array_values( array_unique( $job->warnings ) ), $job->warnings, 'A second run adds no duplicate warnings.' );
		$this->assertCount( 2, $job->warnings );
		$warnings = implode( "\n", $job->warnings );
		$this->assertStringContainsString( 'wps-hide-login/wps-hide-login.php', $warnings );
		$this->assertStringContainsString( 'missing/missing.php', $warnings );
	}

	public function test_a_missing_theme_keeps_the_current_one(): void {
		$job = $this->job( 'child-not-installed' );
		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( 'live-parent', $this->tmpOption( 'template' ) );
		$this->assertSame( 'live-child', $this->tmpOption( 'stylesheet' ) );
		$this->assertStringContainsString( 'child-not-installed', implode( ' ', $job->warnings ) );
	}

	public function test_every_removal_reason_is_reported_and_https_follows_the_home_url(): void {
		$plugins = [ 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'lw-migration-copy/lw-migration.php', 'revslider/revslider.php', 'revslider-old/revslider.php', 'sample/sample.php' ];
		$job     = $this->job( 'twentytwentyfive', $plugins, 'http://new.test' );
		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame(
			[
				'really-simple-ssl/rlrsssl-really-simple-ssl.php' => 'ssl',
				'lw-migration-copy/lw-migration.php'              => 'duplicate',
				'revslider/revslider.php'                         => 'unknown-version',
				'revslider-old/revslider.php'                     => 'outdated',
			],
			$job->state['plugins']['removed']
		);
		$this->assertCount( 4, $job->warnings );
		foreach ( array_keys( $job->state['plugins']['removed'] ) as $i => $plugin ) {
			$this->assertStringContainsString( $plugin, $job->warnings[ $i ] );
		}
		$this->assertCount( 4, array_unique( array_map( static fn( string $w, string $p ): string => str_replace( $p, '', $w ), $job->warnings, array_keys( $job->state['plugins']['removed'] ) ) ), 'One message per reason.' );

		$https = $this->job( 'twentytwentyfive', [ 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ] );
		$this->step()->run( $https, new NoDeadline() );
		$this->assertSame( [], $https->state['plugins']['removed'], 'An https home keeps HTTPS-only plugins active.' );
	}

	public function test_an_added_admin_sharing_an_archive_users_email_or_nicename_is_warned(): void {
		$this->exec(
			'DELETE FROM lwtab12_users',
			"INSERT INTO lwtab12_users (ID, user_login, user_pass, user_email, user_nicename) VALUES (5,'owner','\$P\$o','admin@new.test','owner'),(6,'bob','\$P\$b','bob@old.test','admin')"
		);
		$job = $this->job( 'twentytwentyfive', [] );
		$this->step()->run( $job, new NoDeadline() );

		$rows = self::db()->query( "SELECT ID, user_pass FROM lwtab12_users WHERE user_login = 'admin'" );
		$this->assertCount( 1, $rows );
		$id = (int) $rows[0]['ID'];
		$this->assertGreaterThan( 6, $id );
		$this->assertSame( [ [ 'meta_value' => 'live-session' ] ], self::db()->query( "SELECT meta_value FROM lwtab12_usermeta WHERE user_id = {$id} AND meta_key = 'session_tokens'" ) );
		$warnings = implode( "\n", $job->warnings );
		$this->assertStringContainsString( 'owner', $warnings );
		$this->assertStringContainsString( 'bob', $warnings );
		$this->assertCount( 2, $job->warnings );
	}

	public function test_without_a_usable_admin_the_user_is_told(): void {
		$job = $this->job( 'twentytwentyfive', [] );
		unset( $job->state['admin'] );
		$this->assertSame( 'Kept this site\'s address and settings.', $this->step()->run( $job, new NoDeadline() )->message(), 'No login was kept, so none is claimed.' );
		$this->assertFalse( $job->state['loginKept'] );
		$this->assertCount( 1, $job->warnings );
		$this->assertSame( [ [ 'user_pass' => '$P$archive' ] ], self::db()->query( 'SELECT user_pass FROM lwtab12_users WHERE ID = 5' ) );

		$gone = $this->job( 'twentytwentyfive', [] );
		$this->exec( 'DELETE FROM wp_users' );
		$this->assertSame( 'Kept this site\'s address and settings.', $this->step()->run( $gone, new NoDeadline() )->message() );
		$this->assertCount( 1, $gone->warnings );
		$this->assertStringContainsString( 'admin', $gone->warnings[0] );
		$this->assertFalse( $gone->state['loginKept'] );
	}

	public function test_works_under_no_backslash_escapes_and_restores_the_session_mode(): void {
		$this->exec(
			"INSERT INTO wp_options (option_name, option_value) VALUES ('lw_migration_it''s','Q\\\\uote')",
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('lwXmigration_other','archive')",
			"SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'"
		);
		$this->step()->run( $this->job( 'twentytwentyfive', [] ), new NoDeadline() );
		$mode = (string) self::db()->query( 'SELECT @@SESSION.sql_mode AS m' )[0]['m'];
		$this->exec( "SET SESSION sql_mode = ''" );

		$this->assertSame( 'NO_BACKSLASH_ESCAPES', $mode );
		$this->assertSame( 'L', $this->tmpOption( 'lw_migration_lock' ) );
		$this->assertSame( 'Q\\uote', $this->tmpOption( "lw_migration_it''s" ) );
		$this->assertSame( 'archive', $this->tmpOption( 'lwXmigration_other' ), 'The prefix is matched literally.' );
	}

	public function test_without_an_options_table_only_a_warning_is_given(): void {
		$this->exec( 'DROP TABLE lwtab12_options' );
		$job = $this->job( 'twentytwentyfive' );
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
		$this->assertCount( 1, $job->warnings );
		$this->assertArrayNotHasKey( 'plugins', $job->state );
		$this->assertArrayNotHasKey( 'loginKept', $job->state, 'Unknown: the admin step did not run.' );
	}

	public function test_an_archive_without_a_database_keeps_this_sites_users(): void {
		$job                         = $this->job( 'twentytwentyfive' );
		$job->state['hasDatabase']   = false;
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
		$this->assertTrue( $job->state['loginKept'] );
		$this->assertArrayNotHasKey( 'plugins', $job->state, 'Nothing was written.' );
	}
}

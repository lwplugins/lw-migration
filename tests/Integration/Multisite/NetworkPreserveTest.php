<?php
/**
 * What each site and the network keep before the swap, on a real MySQL/MariaDB:
 * reference-made archives imported into temporary tables next to a live
 * four-site network (NetworkTables), then NetworkPreserve / PreserveStep.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Multisite;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\SessionMode;
use LightweightPlugins\Migration\Import\PluginMessages;
use LightweightPlugins\Migration\Import\PluginPolicy;
use LightweightPlugins\Migration\Import\Steps\PreserveStep;
use LightweightPlugins\Migration\Import\TmpTables;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\NetworkPreserve;
use LightweightPlugins\Migration\Multisite\SiteRoles;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class NetworkPreserveTest extends DatabaseTestCase {

	private const TMP = 'lwtab12_';

	private const OWN = 'lw-migration/lw-migration.php';

	private const NET_PLUGIN = 'lwm-net-sample/lwm-net-sample.php';

	private const SITE_PLUGIN = 'lwm-site-sample/lwm-site-sample.php';

	private const NOW = 1700000000;

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		$this->dir = TempDir::create();
		$this->exec( ...NetworkTables::create( 'wp_', [ 1, 2, 3, 4 ] ) );
		$this->exec( ...NetworkTables::seed( 'wp_' ) );
		$this->exec(
			"INSERT INTO wp_blogs (blog_id, site_id, domain, path) VALUES (4,1,'n.test','/b4/')",
			"INSERT INTO wp_sitemeta VALUES (4,1,'ms_files_rewriting','1')",
			"UPDATE wp_users SET user_login = 'boss', user_pass = '\$P\$boss' WHERE ID = 1"
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_the_importing_admin_stays_a_super_admin(): void {
		$network = $this->wholeNetwork();
		$job     = $this->job();

		$this->relaxed( $this->preserve( $network ), $job, [ 'id' => 1, 'login' => 'boss' ] );
		$this->assertSame( serialize( [ 'admin', 'boss' ] ), $this->tables()->siteMeta( 1, 'site_admins' ), 'The archive\'s super admins plus the importing one, as a list.' );

		$this->relaxed( $this->preserve( $network ), $job, [ 'id' => 1, 'login' => 'admin' ] );
		$this->assertSame( serialize( [ 'admin' ] ), $this->tables()->siteMeta( 1, 'site_admins' ), 'Listed once.' );

		$this->relaxed( $this->preserve( $network ), $job, null );
		$this->assertSame( serialize( [ 'admin' ] ), $this->tables()->siteMeta( 1, 'site_admins' ), 'Without a kept login only the archive\'s list.' );
	}

	public function test_an_empty_super_admin_list_is_never_written(): void {
		$network = $this->wholeNetwork();
		$this->exec( "DELETE FROM lwtab12_sitemeta WHERE meta_key = 'site_admins'", "INSERT INTO lwtab12_sitemeta (site_id, meta_key, meta_value) VALUES (1,'site_admins','archive-row')" );

		$this->relaxed( $this->preserve( new NetworkImport( $network->kind, $network->map, $network->target, $network->plugins, [] ) ), $this->job(), null );

		$this->assertSame( 'archive-row', $this->tables()->siteMeta( 1, 'site_admins' ) );
	}

	public function test_this_plugin_stays_network_active(): void {
		$job = $this->job();
		$this->relaxed( $this->preserve( $this->wholeNetwork() ), $job, null );

		$this->assertSame( serialize( [ self::OWN => self::NOW, self::NET_PLUGIN => self::NOW ] ), $this->tables()->siteMeta( 1, 'active_sitewide_plugins' ) );
		$this->assertSame( [], $job->warnings );

		$missing = $this->job();
		$this->relaxed( $this->preserve( $this->wholeNetwork(), [ self::OWN => '1.0.0' ] ), $missing, null );

		$this->assertSame( serialize( [ self::OWN => self::NOW ] ), $this->tables()->siteMeta( 1, 'active_sitewide_plugins' ) );
		$this->assertContains( PluginMessages::removed( self::NET_PLUGIN, PluginPolicy::MISSING ), $missing->warnings );
	}

	public function test_each_subsite_gets_its_plugins_theme_and_upload_paths(): void {
		$network = $this->wholeNetwork( [ self::SITE_PLUGIN, self::OWN ] );
		$this->exec( "UPDATE lwtab12_2_options SET option_value = 'stale' WHERE option_name IN ('upload_path','upload_url_path')" );
		$main = $this->checksum( 'lwtab12_options' );
		$job  = $this->job();

		$this->relaxed( $this->preserve( $network ), $job, null );

		$this->assertSame( '', $this->option( 2, 'upload_path' ) );
		$this->assertSame( '', $this->option( 2, 'upload_url_path' ) );
		$this->assertSame( serialize( [ self::SITE_PLUGIN ] ), $this->option( 2, 'active_plugins' ), 'This plugin is network-active, never site-active.' );
		$this->assertSame( serialize( [] ), $this->option( 3, 'active_plugins' ) );
		foreach ( [ 2, 3 ] as $id ) {
			$this->assertSame( 'lwm-ref-theme', $this->option( $id, 'template' ) );
			$this->assertSame( 'lwm-ref-theme', $this->option( $id, 'stylesheet' ) );
		}
		$this->assertSame( $main, $this->checksum( 'lwtab12_options' ), 'The main site is kept by the single-site part of the step.' );
		$this->assertSame( [], $job->warnings );

		$noTheme = $this->job();
		$this->relaxed( $this->preserve( $this->wholeNetwork(), null, false ), $noTheme, null );

		$this->assertSame( 'live-parent', $this->option( 2, 'template' ) );
		$this->assertSame( 'live-child', $this->option( 2, 'stylesheet' ) );
		$this->assertCount( 2, $noTheme->warnings, 'One per site.' );
		$this->assertStringContainsString( 'lwm-ref-theme', $noTheme->warnings[0] );
		$this->assertStringContainsString( 'live-child', $noTheme->warnings[0] );
		$this->assertStringContainsString( 'b2.new.test', $noTheme->warnings[0] );
	}

	public function test_ms_files_rewriting_of_the_network_is_kept(): void {
		$this->wholeNetwork();
		$this->exec( "DELETE FROM lwtab12_sitemeta WHERE meta_key = 'ms_files_rewriting'", "INSERT INTO lwtab12_sitemeta (site_id, meta_key, meta_value) VALUES (1,'ms_files_rewriting','0')" );

		$this->relaxed( $this->preserve( $this->wholeNetwork() ), $this->job(), null );

		$this->assertSame( '1', $this->tables()->siteMeta( 1, 'ms_files_rewriting' ) );
	}

	public function test_sites_missing_from_the_archive_are_reported(): void {
		$job                    = $this->job();
		$job->state['unlisted'] = [ [ 'id' => 4, 'url' => 'http://n.test/b4' ] ];
		$preserve               = $this->preserve( $this->wholeNetwork() );

		$this->relaxed( $preserve, $job, null );
		$this->relaxed( $preserve, $job, null );

		$this->assertCount( 1, $job->warnings, 'Once, also after a replay.' );
		$this->assertSame( 'These sites are not in the archive: http://n.test/b4. They stay on the network marked as deleted (Network Admin > Sites), with their tables and files kept; new sites will not reuse their IDs.', $job->warnings[0] );
		$kept = self::db()->query( "SELECT blog_id, site_id, domain, path, deleted, registered, last_updated FROM lwtab12_blogs WHERE blog_id = 4" );
		$this->assertCount( 1, $kept, 'Final review I2: the left-out site comes into the swapped-in table once, also after a replay.' );
		$this->assertSame( [ '4', '1', 'n.test', '/b4/', '1' ], [ (string) $kept[0]['blog_id'], (string) $kept[0]['site_id'], (string) $kept[0]['domain'], (string) $kept[0]['path'], (string) $kept[0]['deleted'] ] );
		$live = self::db()->query( 'SELECT registered FROM wp_blogs WHERE blog_id = 4' );
		$this->assertSame( (string) $live[0]['registered'], (string) $kept[0]['registered'], 'Its registration date stays.' );
		$this->assertNotSame( '0000-00-00 00:00:00', (string) $kept[0]['last_updated'], 'Marked as changed now.' );
		$this->exec( "INSERT INTO lwtab12_blogs (site_id, domain, path) VALUES (1,'new.test','/later/')" );
		$this->assertSame( '5', (string) self::db()->query( "SELECT blog_id FROM lwtab12_blogs WHERE path = '/later/'" )[0]['blog_id'], 'Ruling R16: a site added later never takes id 4 and its leftover tables.' );
		$this->assertSame( [ [ 'blog_id' => '4' ] ], self::db()->query( 'SELECT blog_id FROM wp_blogs WHERE blog_id = 4' ), 'Reported, never dropped.' );
	}

	/**
	 * Review flag (site identity collision): a left-out live site at the
	 * address of an archive site (letter case and a trailing slash aside)
	 * never shadows it: it is kept under a new, unique path, marked deleted,
	 * and named; the archive site keeps its id and address.
	 */
	public function test_a_left_out_site_at_an_archive_sites_address_is_moved_aside(): void {
		$this->wholeNetwork();
		$archive = self::db()->query( 'SELECT domain, path FROM lwtab12_blogs WHERE blog_id = 2' )[0];
		$domain  = strtoupper( (string) $archive['domain'] );
		$path    = rtrim( strtoupper( (string) $archive['path'] ), '/' );
		$this->exec( "INSERT INTO wp_blogs (blog_id, site_id, domain, path) VALUES (5,1,'" . addslashes( $domain ) . "','" . addslashes( $path ) . "')" );
		$job                    = $this->job();
		$job->state['unlisted'] = [
			[ 'id' => 4, 'url' => 'http://n.test/b4' ],
			[ 'id' => 5, 'url' => 'http://dup.test/b2' ],
		];
		$preserve               = $this->preserve( $this->wholeNetwork() );

		$this->relaxed( $preserve, $job, null );
		$this->relaxed( $preserve, $job, null );

		$rows = [];
		foreach ( self::db()->query( 'SELECT blog_id, domain, path, deleted FROM lwtab12_blogs WHERE blog_id IN (2,4,5) ORDER BY blog_id' ) as $row ) {
			$rows[ (int) $row['blog_id'] ] = [ (string) $row['domain'], (string) $row['path'], (string) $row['deleted'] ];
		}
		$this->assertSame( [ (string) $archive['domain'], (string) $archive['path'], '0' ], $rows[2], 'The archive site keeps its address.' );
		$this->assertSame( [ 'n.test', '/b4/', '1' ], $rows[4], 'A left-out site at its own address keeps it.' );
		$this->assertSame( [ $domain, $path . '/lwm-left-out-5/', '1' ], $rows[5], 'Moved aside under a unique path, marked deleted.' );
		$this->assertCount( 2, $job->warnings, 'Each once, also after a replay.' );
		$this->assertSame( 'These sites are not in the archive, but the archive has a site at their address, so they were kept under a new address: http://dup.test/b2 -> ' . $domain . $path . '/lwm-left-out-5/', $job->warnings[1] );
	}

	public function test_a_site_without_an_options_table_is_warned_and_the_others_are_kept(): void {
		$this->wholeNetwork();
		$this->exec( 'DROP TABLE lwtab12_3_options' );
		$job = $this->job();

		$this->relaxed( $this->preserve( $this->wholeNetwork() ), $job, null );

		$this->assertCount( 1, $job->warnings );
		$this->assertStringContainsString( 'b3.new.test', $job->warnings[0] );
		$this->assertSame( 'lwm-ref-theme', $this->option( 2, 'template' ) );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwtab12\\_3\\_options'" ), 'Nothing is created for it.' );
	}

	public function test_live_tables_are_never_written(): void {
		$before = $this->liveChecksums();
		$job    = $this->job();

		$this->relaxed( $this->preserve( $this->wholeNetwork() ), $job, [ 'id' => 1, 'login' => 'boss' ] );

		$this->assertSame( $before, $this->liveChecksums() );
	}

	public function test_a_subsite_keeps_its_new_address_and_upload_paths(): void {
		$network = $this->sitesNetwork();
		$before  = $this->liveChecksums();
		$job     = $this->job();

		$this->relaxed( $this->preserve( $network ), $job, null );

		$this->assertSame( $before, $this->liveChecksums(), 'Live tables are only read.' );
		$this->assertSame( 'http://new.test/shop', $this->option( 7, 'siteurl' ) );
		$this->assertSame( 'http://new.test/shop/', $this->option( 7, 'home' ) );
		$this->assertSame( 'wp-content/uploads/sites/7x', $this->option( 7, 'upload_path' ) );
		$this->assertSame( 'http://cdn.test/7', $this->option( 7, 'upload_url_path' ) );
		$this->assertSame( serialize( [ self::SITE_PLUGIN ] ), $this->option( 7, 'active_plugins' ) );
		$this->assertSame( 'lwm-ref-theme', $this->option( 7, 'stylesheet' ) );
		$this->assertFalse( $this->hasTable( 'lwtab12_sitemeta' ), 'Network plugins are merged after the swap, not written here.' );
	}

	/**
	 * Final review I1: plugins and themes the network did not have before the
	 * import are restored on disk but never activated; one warning names them.
	 */
	public function test_archive_code_the_network_did_not_have_is_restored_but_not_activated(): void {
		$network                    = $this->sitesNetwork();
		$job                        = $this->job();
		$job->state['networkCode']  = [
			'plugins' => [ 'lw-migration' ],
			'themes'  => [ 'live-parent', 'live-child' ],
		];
		$preserve                   = $this->preserve( $network );

		$this->relaxed( $preserve, $job, null );
		$this->relaxed( $preserve, $job, null );

		$this->assertSame( serialize( [] ), $this->option( 7, 'active_plugins' ), 'The archive\'s new plugin stays inactive.' );
		$this->assertSame( 'live-parent', $this->option( 7, 'template' ), 'The archive\'s new theme is not used.' );
		$this->assertSame( 'live-child', $this->option( 7, 'stylesheet' ) );
		$this->assertCount( 1, $job->warnings, 'One warning, also after a replay.' );
		$this->assertSame( 'Restored but not activated, because the network did not have them before this import: ' . self::SITE_PLUGIN . ', ' . self::NET_PLUGIN . ', lwm-ref-theme. Activate them in Network Admin if you trust the archive.', $job->warnings[0] );
	}

	public function test_archive_code_the_network_already_had_is_activated(): void {
		$network                   = $this->sitesNetwork();
		$job                       = $this->job();
		$job->state['networkCode'] = [
			'plugins' => [ 'lw-migration', 'lwm-site-sample', 'lwm-net-sample' ],
			'themes'  => [ 'lwm-ref-theme' ],
		];

		$this->relaxed( $this->preserve( $network ), $job, null );

		$this->assertSame( serialize( [ self::SITE_PLUGIN ] ), $this->option( 7, 'active_plugins' ) );
		$this->assertSame( 'lwm-ref-theme', $this->option( 7, 'template' ) );
		$this->assertSame( 'lwm-ref-theme', $this->option( 7, 'stylesheet' ) );
		$this->assertSame( [], $job->warnings );
	}

	public function test_a_whole_network_activates_its_own_code_as_before(): void {
		$job                       = $this->job();
		$job->state['networkCode'] = [
			'plugins' => [],
			'themes'  => [],
		];

		$this->relaxed( $this->preserve( $this->wholeNetwork( [ self::SITE_PLUGIN ] ) ), $job, null );

		$this->assertSame( serialize( [ self::SITE_PLUGIN ] ), $this->option( 2, 'active_plugins' ) );
		$this->assertSame( 'lwm-ref-theme', $this->option( 2, 'stylesheet' ) );
		$this->assertStringContainsString( self::NET_PLUGIN, (string) $this->tables()->siteMeta( 1, 'active_sitewide_plugins' ) );
	}

	public function test_the_main_site_tables_are_never_written(): void {
		$job    = $this->sitesJob();
		$staged = $this->checksums( 'lwtab12\\_lwmsrc\\_%' );

		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertSame( 'Kept each site\'s address and settings.', $result->message() );
		$this->assertTrue( $job->state['loginKept'], 'Users are merged after the swap; this network\'s users stay.' );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwtab12\\_options'" ), 'No temporary options table of the main site.' );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwtab12\\_sitemeta'" ) );
		$this->assertNotSame( [], $staged );
		$this->assertSame( $staged, $this->checksums( 'lwtab12\\_lwmsrc\\_%' ), 'The staged network tables are left for the user merge.' );
		$this->assertSame( 'http://new.test/shop', $this->option( 7, 'siteurl' ) );
	}

	public function test_the_preserve_step_keeps_the_main_site_then_the_network_for_a_network_archive(): void {
		$job = $this->networkJob();

		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertSame( 'Kept this site\'s address, settings and your login.', $result->message() );
		$this->assertTrue( $job->state['loginKept'] );
		$this->assertSame( 'http://n.test/1', $this->option( 1, 'siteurl' ), 'The main site keeps its address.' );
		$this->assertSame( 'x', $this->option( 1, 'lw_migration_lock' ), 'Our lock is carried over into the main site\'s options.' );
		$this->assertSame( [ [ 'user_pass' => '$P$boss' ] ], self::db()->query( "SELECT user_pass FROM lwtab12_users WHERE user_login = 'boss'" ), 'The importing admin keeps a usable account.' );
		$this->assertSame( [ [ 'meta_value' => 'a:1:{s:6:"editor";b:1;}' ] ], self::db()->query( "SELECT m.meta_value FROM lwtab12_usermeta AS m JOIN lwtab12_users AS u ON u.ID = m.user_id WHERE u.user_login = 'boss' AND m.meta_key = 'wp_capabilities'" ), 'The live role on the main site comes along.' );
		$this->assertSame( serialize( [ 'admin', 'boss' ] ), $this->tables()->siteMeta( 1, 'site_admins' ) );
		$this->assertStringContainsString( self::OWN, (string) $this->tables()->siteMeta( 1, 'active_sitewide_plugins' ) );
		$this->assertSame( 'lwm-ref-theme', $this->option( 2, 'template' ) );
	}

	/**
	 * Review I1: an archive of network 2 restored into network 1 ends up wholly under network 1.
	 */
	public function test_an_archive_of_another_network_id_is_moved_to_this_network(): void {
		$this->wholeNetwork();
		$siteName = (string) self::db()->query( "SELECT meta_value FROM lwtab12_sitemeta WHERE meta_key = 'site_name'" )[0]['meta_value'];
		$this->exec(
			'UPDATE lwtab12_site SET id = 2',
			'UPDATE lwtab12_blogs SET site_id = 2',
			'UPDATE lwtab12_sitemeta SET site_id = 2',
			"INSERT INTO lwtab12_sitemeta (site_id, meta_key, meta_value) VALUES (1,'stale','x')"
		);
		$preserve = $this->preserve( $this->wholeNetwork() );

		$this->relaxed( $preserve, $this->job(), [ 'id' => 1, 'login' => 'boss' ] );
		$this->relaxed( $preserve, $this->job(), [ 'id' => 1, 'login' => 'boss' ] );

		$this->assertSame( [ '1' ], array_column( self::db()->query( 'SELECT id FROM lwtab12_site' ), 'id' ) );
		$this->assertSame( [ '1' ], array_values( array_unique( array_column( self::db()->query( 'SELECT site_id FROM lwtab12_blogs' ), 'site_id' ) ) ) );
		$this->assertSame( [ '1' ], array_values( array_unique( array_column( self::db()->query( 'SELECT site_id FROM lwtab12_sitemeta' ), 'site_id' ) ) ) );
		$this->assertSame( $siteName, $this->tables()->siteMeta( 1, 'site_name' ), 'The archive\'s network settings come along.' );
		$this->assertNull( $this->tables()->siteMeta( 1, 'stale' ), 'Rows already under this id are replaced by the archive\'s.' );
		$this->assertSame( serialize( [ 'admin', 'boss' ] ), $this->tables()->siteMeta( 1, 'site_admins' ) );
		$this->assertSame( '1', $this->tables()->siteMeta( 1, 'ms_files_rewriting' ) );
	}

	/**
	 * Review M1: the importing admin's login exists in the archive with another password.
	 */
	public function test_the_importing_admin_keeps_password_session_and_role_when_the_archive_has_the_same_login(): void {
		$this->exec(
			"UPDATE wp_users SET user_login = 'admin' WHERE ID = 1",
			"UPDATE wp_usermeta SET meta_value = 'a:1:{s:13:\"administrator\";b:1;}' WHERE user_id = 1 AND meta_key = 'wp_capabilities'",
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1,'session_tokens','live-session')"
		);
		$job = $this->stepJob( $this->wholeNetwork(), 'network.wpress', [ 'id' => 1, 'login' => 'admin' ] );
		$this->exec( "UPDATE lwtab12_users SET user_pass = '\$P\$archive' WHERE user_login = 'admin'" );

		$this->step()->run( $job, new NoDeadline() );

		$rows = self::db()->query( "SELECT ID, user_pass FROM lwtab12_users WHERE user_login = 'admin'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '$P$boss', (string) $rows[0]['user_pass'], 'The live password, not the archive\'s.' );
		$id   = (int) $rows[0]['ID'];
		$meta = [];
		foreach ( self::db()->query( "SELECT meta_key, meta_value FROM lwtab12_usermeta WHERE user_id = {$id} AND meta_key IN ('wp_capabilities','session_tokens')" ) as $row ) {
			$meta[ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}
		ksort( $meta );
		$this->assertSame( [ 'session_tokens' => 'live-session', 'wp_capabilities' => 'a:1:{s:13:"administrator";b:1;}' ], $meta );
		$this->assertSame( serialize( [ 'admin' ] ), $this->tables()->siteMeta( 1, 'site_admins' ) );
		$this->assertTrue( $job->state['loginKept'] );
	}

	public function test_a_lost_login_is_not_made_a_super_admin(): void {
		$job = $this->networkJob();
		$this->exec( 'DELETE FROM wp_users WHERE ID = 1' );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertFalse( $job->state['loginKept'] );
		$this->assertSame( serialize( [ 'admin' ] ), $this->tables()->siteMeta( 1, 'site_admins' ) );
	}

	/**
	 * @param array<int, string>|null $site2Plugins Plugins of archive site 2 instead of the archive's.
	 */
	private function wholeNetwork( ?array $site2Plugins = null ): NetworkImport {
		$data = json_decode( ArchiveImport::entry( 'network.wpress', 'multisite.json' ), true );
		if ( null !== $site2Plugins ) {
			$data['Sites'][1]['Plugins'] = $site2Plugins;
		}
		$m       = MultisiteConfig::fromJson( (string) json_encode( $data ) );
		$to      = self::target( true );
		$network = new NetworkImport( ArchiveKind::NETWORK, BlogMap::plan( ArchiveKind::NETWORK, $m->entries(), 'http://net-n.test', $to ), $to, $m->plugins(), $m->admins() );
		if ( ! $this->hasTable( 'lwtab12_users' ) ) {
			ArchiveImport::run( self::db(), 'network.wpress', $network, $this->dir, self::TMP );
		}

		return $network;
	}

	public function test_a_restored_sites_roles_lose_every_network_right(): void {
		$network = $this->sitesNetwork();
		$roles   = serialize(
			[
				'administrator' => [
					'name'         => 'Administrator',
					'capabilities' => [
						'edit_users'           => true,
						'manage_network_users' => true,
					],
				],
			]
		);
		$this->exec( "INSERT INTO lwtab12_7_options (option_name, option_value) VALUES ('wp_7_user_roles','" . addslashes( $roles ) . "') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );
		$job = $this->job();

		$this->relaxed( $this->preserve( $network ), $job, null );

		$this->assertSame( [ 'administrator' => [ 'name' => 'Administrator', 'capabilities' => [ 'edit_users' => true ] ] ], unserialize( (string) $this->option( 7, 'wp_7_user_roles' ) ) );
		$this->assertStringContainsString( 'rights over the whole network were removed', implode( "\n", $job->warnings ) );
	}

	public function test_a_restored_sites_unreadable_roles_become_the_default_roles(): void {
		$network = $this->sitesNetwork();
		$this->exec( "INSERT INTO lwtab12_7_options (option_name, option_value) VALUES ('wp_7_user_roles','O:8:\"stdClass\":0:{}') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );

		$this->relaxed( $this->preserve( $network ), $this->job(), null );

		$this->assertSame( SiteRoles::CORE_DEFAULT, $this->option( 7, 'wp_7_user_roles' ) );
	}

	public function test_two_rows_of_a_restored_sites_roles_become_the_default_roles_once(): void {
		$network = $this->sitesNetwork();
		$clean   = serialize( [ 'editor' => [ 'name' => 'Editor', 'capabilities' => [ 'read' => true ] ] ] );
		$evil    = serialize( [ 'editor' => [ 'name' => 'Editor', 'capabilities' => [ 'manage_network_users' => true ] ] ] );
		$this->dropUniqueOptionName( 'lwtab12_7_options' );
		$this->exec(
			"DELETE FROM lwtab12_7_options WHERE option_name = 'wp_7_user_roles'",
			"INSERT INTO lwtab12_7_options (option_name, option_value) VALUES ('wp_7_user_roles','" . addslashes( $clean ) . "'),('wp_7_user_roles','" . addslashes( $evil ) . "')"
		);

		$this->relaxed( $this->preserve( $network ), $this->job(), null );

		$this->assertSame( [ SiteRoles::CORE_DEFAULT ], $this->roleRows() );
	}

	public function test_case_variants_of_a_restored_sites_roles_become_the_default_roles(): void {
		$network = $this->sitesNetwork();
		$this->dropUniqueOptionName( 'lwtab12_7_options' );
		$this->exec(
			'ALTER TABLE lwtab12_7_options CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
			"DELETE FROM lwtab12_7_options WHERE LOWER(option_name) = 'wp_7_user_roles'",
			"INSERT INTO lwtab12_7_options (option_name, option_value) VALUES ('wp_7_user_roles','a:0:{}'),('WP_7_User_Roles ','a:0:{}')"
		);

		$this->relaxed( $this->preserve( $network ), $this->job(), null );

		$this->assertSame( [ SiteRoles::CORE_DEFAULT ], $this->roleRows() );
		$this->assertSame( SiteRoles::CORE_DEFAULT, $this->option( 7, 'wp_7_user_roles' ) );
	}

	public function test_a_restored_site_without_roles_gets_the_default_roles(): void {
		$network = $this->sitesNetwork();
		$this->exec( "DELETE FROM lwtab12_7_options WHERE option_name = 'wp_7_user_roles'" );

		$this->relaxed( $this->preserve( $network ), $this->job(), null );

		$this->assertSame( SiteRoles::CORE_DEFAULT, $this->option( 7, 'wp_7_user_roles' ) );
	}

	public function test_look_alike_names_of_a_restored_sites_roles_in_a_unicode_table_become_the_default_roles(): void {
		$network = $this->sitesNetwork();
		$evil    = addslashes( serialize( [ 'editor' => [ 'name' => 'Editor', 'capabilities' => [ 'manage_network_users' => true ] ] ] ) );
		$clean   = addslashes( serialize( [ 'editor' => [ 'name' => 'Editor', 'capabilities' => [ 'read' => true ] ] ] ) );
		$this->dropUniqueOptionName( 'lwtab12_7_options' );
		$this->exec(
			'ALTER TABLE lwtab12_7_options CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
			"DELETE FROM lwtab12_7_options WHERE option_name LIKE 'wp\\_7\\_user\\_roles%'",
			"INSERT INTO lwtab12_7_options (option_name, option_value, autoload) VALUES ('wp_7_user_roles','{$clean}','no'),"
			. "('wp_7_user_roles\u{00A0}','{$evil}','no'),('wp_7_user_roles\u{3000}','{$evil}','no'),('\u{FF57}\u{FF50}_7_user_roles','{$evil}','no'),('wp_7_user_roles\0','{$evil}','no')"
		);

		$this->relaxed( $this->preserve( $network ), $this->job(), null );

		$rows = self::db()->query( "SELECT HEX(option_name) AS n, option_value AS v, autoload AS a FROM lwtab12_7_options WHERE option_name = 'wp_7_user_roles' OR option_name LIKE 'wp\\_7\\_user\\_roles%'" );
		$this->assertSame( [ [ 'n' => strtoupper( bin2hex( 'wp_7_user_roles' ) ), 'v' => SiteRoles::CORE_DEFAULT, 'a' => 'yes' ] ], $rows, 'One exact, autoloaded row of the default roles is left.' );
	}

	public function test_clean_roles_are_rewritten_as_one_exact_autoloaded_row(): void {
		$network = $this->sitesNetwork();
		$clean   = serialize( [ 'editor' => [ 'name' => 'Editor', 'capabilities' => [ 'read' => true ] ] ] );
		$this->exec(
			"DELETE FROM lwtab12_7_options WHERE option_name = 'wp_7_user_roles'",
			"INSERT INTO lwtab12_7_options (option_name, option_value, autoload) VALUES ('wp_7_user_roles','" . addslashes( $clean ) . "','no')"
		);

		$this->relaxed( $this->preserve( $network ), $this->job(), null );

		$this->assertSame( [ [ 'v' => $clean, 'a' => 'yes' ] ], self::db()->query( "SELECT option_value AS v, autoload AS a FROM lwtab12_7_options WHERE option_name = 'wp_7_user_roles'" ) );
	}

	/** @return array<int, string> Every user_roles row of the temporary site 7, in any letter case. */
	private function roleRows(): array {
		return array_map( static fn( array $r ): string => (string) $r['option_value'], self::db()->query( "SELECT option_value FROM lwtab12_7_options WHERE LOWER(TRIM(option_name)) = 'wp_7_user_roles'" ) );
	}

	private function dropUniqueOptionName( string $table ): void {
		foreach ( self::db()->query( "SHOW INDEX FROM `{$table}` WHERE Column_name = 'option_name' AND Non_unique = 0" ) as $index ) {
			$this->exec( "ALTER TABLE `{$table}` DROP INDEX `" . $index['Key_name'] . '`' );
		}
	}

	private function sitesNetwork(): NetworkImport {
		if ( ! $this->hasTable( 'wp_7_options' ) ) {
			$this->exec(
				WpCoreTables::create( 'wp_7_' )[0],
				"INSERT INTO wp_blogs (blog_id, site_id, domain, path) VALUES (7,1,'new.test','/shop/')",
				"INSERT INTO wp_7_options (option_name, option_value) VALUES ('siteurl','http://new.test/shop'),('home','http://new.test/shop/'),('upload_path','wp-content/uploads/sites/7x'),('upload_url_path','http://cdn.test/7')"
			);
		}
		$m       = MultisiteConfig::fromJson( ArchiveImport::entry( 'sites-2.wpress', 'multisite.json' ) );
		$url     = 'http://new.test/shop';
		$map     = BlogMap::fromArray(
			[
				[
					'old'           => $m->entries()[0]->state(),
					'newId'         => 7,
					'url'           => $url,
					'created'       => true,
					'newSiteUrl'    => $url,
					'newHomeUrl'    => $url,
					'newUploadsUrl' => 'http://new.test/wp-content/uploads/sites/7/',
				],
			]
		);
		$network = new NetworkImport( ArchiveKind::SITES, $map, self::target( false ), $m->plugins(), $m->admins() );
		if ( ! $this->hasTable( 'lwtab12_7_options' ) ) {
			ArchiveImport::run( self::db(), 'sites-2.wpress', $network, $this->dir, self::TMP );
		}

		return $network;
	}

	/**
	 * @param array<string, string>|null $installed Installed plugins (null: this one and both samples).
	 * @param bool                       $theme     The archive's theme is installed.
	 */
	private function preserve( NetworkImport $network, ?array $installed = null, bool $theme = true ): NetworkPreserve {
		$installed = $installed ?? self::installed();

		return new NetworkPreserve(
			$this->tables(),
			$network,
			SiteFixture::make( $this->dir . '/root', [ 'homeUrl' => 'http://new.test', 'template' => 'live-parent', 'stylesheet' => 'live-child' ] ),
			static fn(): array => $installed,
			static fn( string $slug ): bool => $theme && 'lwm-ref-theme' === $slug,
			static fn(): int => self::NOW
		);
	}

	/**
	 * Runs the way PreserveStep does: with an empty session sql_mode (the archive's
	 * zero-date column defaults would otherwise refuse the ALTER of ruling R16).
	 *
	 * @param NetworkPreserve           $preserve Under test.
	 * @param Job                       $job      Import job.
	 * @param array<string, mixed>|null $admin    The importing admin.
	 */
	private function relaxed( NetworkPreserve $preserve, Job $job, ?array $admin ): void {
		SessionMode::relaxed( self::db(), static fn() => $preserve->run( $job, $admin ) );
	}

	private function step(): PreserveStep {
		return new PreserveStep( static fn() => self::db(), static fn(): array => self::installed(), static fn( string $slug ): bool => 'lwm-ref-theme' === $slug );
	}

	/** @return array<string, string> */
	private static function installed(): array {
		return [
			self::OWN         => '1.0.0',
			self::NET_PLUGIN  => '1.0',
			self::SITE_PLUGIN => '1.0',
		];
	}

	private function tables(): TmpTables {
		return new TmpTables( self::db(), self::TMP, 'wp_' );
	}

	private function job(): Job {
		$job                    = ImportJobFixture::make( new JobStore( $this->dir . '/jobs' ), $this->dir, '/unused.wpress' );
		$job->state['unlisted'] = [];

		return $job;
	}

	private function networkJob(): Job {
		$network = $this->wholeNetwork();

		return $this->stepJob( $network, 'network.wpress', [ 'id' => 1, 'login' => 'boss' ] );
	}

	private function sitesJob(): Job {
		return $this->stepJob( $this->sitesNetwork(), 'sites-2.wpress', [ 'id' => 1, 'login' => 'boss' ] );
	}

	/**
	 * @param NetworkImport        $network The restore.
	 * @param string               $file    Fixture archive.
	 * @param array<string, mixed> $admin   The importing admin.
	 */
	private function stepJob( NetworkImport $network, string $file, array $admin ): Job {
		$job                       = $this->job();
		$job->state['package']     = (array) json_decode( ArchiveImport::entry( $file, 'package.json' ), true );
		$job->state['hasDatabase'] = true;
		$job->state['tmpPrefix']   = self::TMP;
		$job->state['admin']       = $admin;
		$job->state['target']      = $network->target->toArray();
		$job->state['kind']        = $network->kind;
		$job->state['blogs']       = $network->map->toArray();
		$job->state['multisite']   = [
			'network' => ArchiveKind::NETWORK === $network->kind,
			'plugins' => $network->plugins,
			'admins'  => $network->admins,
		];

		return $job;
	}

	private static function target( bool $subdomain ): TargetNetwork {
		return TargetNetwork::fromArray(
			[
				'subdomain'  => $subdomain,
				'domain'     => 'new.test',
				'path'       => '/',
				'scheme'     => 'http',
				'uploadsDir' => '/srv/new/wp-content/uploads',
				'uploadsUrl' => 'http://new.test/wp-content/uploads',
				'basePrefix' => 'wp_',
			]
		);
	}

	private function option( int $blogId, string $name ): ?string {
		$table = 1 === $blogId ? 'lwtab12_options' : 'lwtab12_' . $blogId . '_options';
		$rows  = self::db()->query( "SELECT option_value FROM `{$table}` WHERE option_name = '{$name}'" );

		return [] === $rows ? null : (string) $rows[0]['option_value'];
	}

	private function hasTable( string $name ): bool {
		return [] !== self::db()->query( "SHOW TABLES LIKE '" . addcslashes( $name, '_' ) . "'" );
	}

	private function checksum( string $table ): string {
		return (string) ( self::db()->query( 'CHECKSUM TABLE `' . $table . '`' )[0]['Checksum'] ?? '' );
	}

	/** @return array<string, string> Table => checksum. */
	private function checksums( string $like ): array {
		$sums = [];
		foreach ( self::db()->query( "SHOW TABLES LIKE '" . $like . "'" ) as $row ) {
			$table          = (string) array_values( $row )[0];
			$sums[ $table ] = $this->checksum( $table );
		}

		return $sums;
	}

	/** @return array<string, string> Live table => checksum. */
	private function liveChecksums(): array {
		return $this->checksums( 'wp\\_%' );
	}
}

<?php
/**
 * Tests for Permission.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\DownloadToken;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Schedules\Loopback;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class PermissionTest extends MonkeyTestCase {

	/**
	 * Transients by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	public function test_a_logged_in_user_with_the_capability_passes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		$this->assertTrue( Permission::check() );
	}

	public function test_logged_out_is_refused(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\expect( 'current_user_can' )->never();

		$this->assertFalse( Permission::check() );
	}

	public function test_without_the_capability_is_refused(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( Permission::check() );
	}

	public function test_the_capability_is_filterable_but_never_empty(): void {
		Filters\expectApplied( 'lw_migration_capability' )->times( 3 )->andReturn( 'export', '', [ 'x' ] );

		$this->assertSame( 'export', Permission::capability() );
		$this->assertSame( 'manage_options', Permission::capability() );
		$this->assertSame( 'manage_options', Permission::capability() );
	}

	public function test_on_a_network_the_default_capability_is_the_network_one(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network_options' )->andReturn( false );

		$this->assertSame( 'manage_network_options', Permission::capability() );
		$this->assertFalse( Permission::check(), 'A subsite admin without the network capability is refused.' );
	}

	public function test_on_a_network_the_filter_still_decides(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Filters\expectApplied( 'lw_migration_capability' )->twice()->with( 'manage_network_options' )->andReturn( 'export', '' );

		$this->assertSame( 'export', Permission::capability() );
		$this->assertSame( 'manage_network_options', Permission::capability(), 'An empty value falls back to the network default.' );
	}

	public function test_the_main_site_of_a_network_passes_a_super_admin(): void {
		$this->network( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network_options' )->andReturn( true );

		$this->assertTrue( Permission::check() );
	}

	#[RunInSeparateProcess]
	public function test_a_subsite_request_is_refused_on_a_network(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		$this->network( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_network_options' )->andReturn( true );

		$error = Permission::check();

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'lw_migration_main_site', $error->get_error_code() );
		$this->assertSame( [ 'status' => 403 ], $error->get_error_data() );
	}

	public function test_a_subsite_admin_reaches_nothing(): void {
		foreach ( [ true, false ] as $mainSite ) {
			$this->network( $mainSite );
			Functions\when( 'is_user_logged_in' )->justReturn( true );
			Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'manage_options' === $cap );
			Functions\when( 'user_can' )->alias( static fn( int $id, string $cap ): bool => 'manage_options' === $cap );
			Functions\when( 'wp_validate_auth_cookie' )->justReturn( 7 );

			$this->assertFalse( Permission::check(), 'A site administrator without manage_network_options is refused.' );
			$this->assertNull( Permission::downloadTarget( str_repeat( 'a', 64 ) ) );
		}
	}

	public function test_the_loopback_opens_only_on_the_main_site(): void {
		FakeWpdb::install();
		$token = (string) ( new Loopback() )->issue( '0123456789abcdef', time() );
		$body  = [
			'job'   => '0123456789abcdef',
			'token' => $token,
		];

		$this->network( false );
		$this->assertFalse( Permission::loopbackOpens( $body ), 'Refused on a subsite, before the token row is read.' );

		$this->network( true );
		$this->assertTrue( Permission::loopbackOpens( $body ), 'The token was not used up by the refusal.' );
	}

	public function test_the_oauth_callback_needs_a_valid_login_cookie_with_the_capability(): void {
		Functions\when( 'wp_validate_auth_cookie' )->justReturn( false );
		$this->assertFalse( Permission::oauthCallback() );
		$this->assertNull( Permission::cookieUser() );

		Functions\when( 'wp_validate_auth_cookie' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertFalse( Permission::oauthCallback() );

		Functions\when( 'user_can' )->justReturn( true );
		$this->assertTrue( Permission::oauthCallback() );
		$this->assertSame( 7, Permission::cookieUser() );
	}

	public function test_the_oauth_callback_needs_the_main_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( false );
		Functions\expect( 'wp_validate_auth_cookie' )->never();

		$this->assertFalse( Permission::oauthCallback() );
	}

	public function test_the_download_link_needs_the_main_site(): void {
		$this->tokenStore();
		$this->network( true );
		Functions\when( 'wp_validate_auth_cookie' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( true );
		$token = ( new DownloadToken() )->issue( 7, Backup::OWN, 'site.wpress' );

		$this->network( false );
		Functions\expect( 'get_transient' )->never();
		$this->assertNull( Permission::downloadTarget( $token ) );
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'token' )->andReturn( $token );
		$this->assertFalse( Permission::download( $request ) );
	}

	public function test_a_download_on_the_main_site_of_a_network_opens(): void {
		$this->tokenStore();
		$this->network( true );
		Functions\when( 'wp_validate_auth_cookie' )->justReturn( 7 );
		Functions\expect( 'user_can' )->once()->with( 7, 'manage_network_options' )->andReturn( true );
		$token = ( new DownloadToken() )->issue( 7, Backup::OWN, 'site.wpress' );

		$this->assertSame(
			[
				'folder' => Backup::OWN,
				'name'   => 'site.wpress',
			],
			Permission::downloadTarget( $token )
		);
	}

	public function test_a_download_names_the_backup_of_a_valid_token_for_the_cookie_user(): void {
		$this->tokenStore();
		Functions\expect( 'wp_validate_auth_cookie' )->once()->with( '', 'logged_in' )->andReturn( 7 );
		Functions\expect( 'user_can' )->once()->with( 7, 'manage_options' )->andReturn( true );
		$token = ( new DownloadToken() )->issue( 7, Backup::OWN, 'site.wpress' );

		$this->assertSame(
			[
				'folder' => Backup::OWN,
				'name'   => 'site.wpress',
			],
			Permission::downloadTarget( $token )
		);
	}

	public function test_a_download_without_a_valid_login_cookie_is_refused(): void {
		$this->tokenStore();
		$token = ( new DownloadToken() )->issue( 7, Backup::OWN, 'site.wpress' );

		Functions\when( 'wp_validate_auth_cookie' )->justReturn( false );
		Functions\expect( 'user_can' )->never();
		$this->assertNull( Permission::downloadTarget( $token ) );
	}

	public function test_a_download_for_a_user_without_the_capability_is_refused(): void {
		$this->tokenStore();
		$token = ( new DownloadToken() )->issue( 7, Backup::OWN, 'site.wpress' );

		Functions\when( 'wp_validate_auth_cookie' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\expect( 'get_transient' )->never();
		$this->assertNull( Permission::downloadTarget( $token ) );
	}

	public function test_a_download_with_a_token_of_another_user_or_a_made_up_token_is_refused(): void {
		$this->tokenStore();
		$token = ( new DownloadToken() )->issue( 7, Backup::OWN, 'site.wpress' );
		Functions\when( 'user_can' )->justReturn( true );

		Functions\when( 'wp_validate_auth_cookie' )->justReturn( 8 );
		$this->assertNull( Permission::downloadTarget( $token ) );

		Functions\when( 'wp_validate_auth_cookie' )->justReturn( 7 );
		$this->assertNull( Permission::downloadTarget( str_repeat( 'a', 64 ) ) );
		$this->assertNull( Permission::downloadTarget( '' ) );
	}

	public function test_the_download_check_reads_only_the_token_parameter(): void {
		$this->tokenStore();
		Functions\when( 'wp_validate_auth_cookie' )->justReturn( 7 );
		Functions\when( 'user_can' )->justReturn( true );
		$token = ( new DownloadToken() )->issue( 7, Backup::EXTERNAL, 'old.wpress' );

		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'token' )->andReturn( $token, [ $token ] );

		$this->assertTrue( Permission::download( $request ) );
		$this->assertFalse( Permission::download( $request ) );
	}

	public function test_the_loopback_opens_once_with_its_token_and_never_without_one(): void {
		FakeWpdb::install();
		$token = (string) ( new Loopback() )->issue( '0123456789abcdef', time() );

		$this->assertFalse( Permission::loopbackOpens( null ) );
		$this->assertFalse( Permission::loopbackOpens( [ 'job' => '0123456789abcdef' ] ) );
		$this->assertFalse( Permission::loopbackOpens( [ 'job' => '0123456789abcdef', 'token' => 5 ] ) );
		$this->assertTrue( Permission::loopbackOpens( [ 'job' => '0123456789abcdef', 'token' => $token ] ) );
		$this->assertFalse( Permission::loopbackOpens( [ 'job' => '0123456789abcdef', 'token' => $token ] ), 'Used up.' );
	}

	public function test_a_malformed_loopback_body_is_refused_before_the_token_is_read(): void {
		$db = \Mockery::mock();
		$db->shouldNotReceive( 'get_var', 'prepare', 'query' );
		$GLOBALS['wpdb'] = $db;
		$job             = '0123456789abcdef';
		$token           = str_repeat( 'a', 32 );
		try {
			foreach ( [
				null,
				'job',
				[],
				[ 'token' => $token ],
				[ 'job' => $job ],
				[ 'job' => $job, 'token' => [] ],
				[ 'job' => $job, 'token' => 123 ],
				[ 'job' => $job, 'token' => null ],
				[ 'job' => 5, 'token' => $token ],
				[ 'job' => [ $job ], 'token' => $token ],
			] as $body ) {
				$this->assertFalse( Permission::loopbackOpens( $body ), (string) json_encode( $body ) );
			}
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_the_loopback_reads_only_the_json_body(): void {
		FakeWpdb::install();
		$token   = (string) ( new Loopback() )->issue( '0123456789abcdef', time() );
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldNotReceive( 'get_param', 'get_params', 'get_query_params', 'get_body_params', 'get_url_params' );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			null,
			[
				'job'   => '0123456789abcdef',
				'token' => $token,
			]
		);

		$this->assertFalse( Permission::loopback( $request ), 'Without a JSON body (a query string or a form) it stays shut.' );
		$this->assertTrue( Permission::loopback( $request ) );
	}

	/**
	 * Core's Allow header (rest_send_allow_header, on rest_post_dispatch) calls
	 * the permission callback again, also for a request it never dispatched
	 * (an OPTIONS request with {job, token}): that pass must not use the token up.
	 */
	public function test_the_allow_header_pass_never_uses_the_loopback_token(): void {
		FakeWpdb::install();
		$token   = (string) ( new Loopback() )->issue( '0123456789abcdef', time() );
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn(
			[
				'job'   => '0123456789abcdef',
				'token' => $token,
			]
		);
		Functions\when( 'doing_filter' )->alias( static fn( $hook = null ) => 'rest_post_dispatch' === $hook );

		$this->assertFalse( Permission::loopback( $request ) );

		Functions\when( 'doing_filter' )->justReturn( false );
		$this->assertTrue( Permission::loopback( $request ), 'The token is still there for the dispatch.' );
	}

	#[RunInSeparateProcess]
	public function test_every_share_refusal_is_the_same_404(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		$name = 'site-20261009-120000-001.wpress';
		$db   = FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		mkdir( WP_CONTENT_DIR . '/lw-migration-0123456789ab/backups', 0755, true );
		file_put_contents( WP_CONTENT_DIR . '/lw-migration-0123456789ab/backups/' . $name, 'backup' );
		try {
			$path    = WP_CONTENT_DIR . '/lw-migration-0123456789ab/backups/' . $name;
			$print   = Fingerprint::of( $path );
			$shares  = new Shares();
			$live    = $shares->create( Backup::OWN, $name, 1, time(), 3600, $print );
			$expired = $shares->create( Backup::OWN, $name, 1, time() - 1000, 300, $print );
			$revoked = $shares->create( Backup::OWN, $name, 1, time(), 3600, $print );
			$gone    = $shares->create( Backup::OWN, 'gone-20261009-120000-001.wpress', 1, time(), 3600, $print );
			$other   = $shares->create( Backup::EXTERNAL, $name, 1, time(), 3600, $print );
			$legacy  = $shares->create( Backup::OWN, $name, 1, time(), 3600 );
			$changed = $shares->create( Backup::OWN, $name, 1, time(), 3600, str_repeat( '0', 32 ) );
			$shares->revoke( $revoked['id'] );
			$cases = [
				'no parameter' => [ null, true ],
				'an array'     => [ [ $live['token'] ], true ],
				'malformed'    => [ substr( $live['token'], 1 ), true ],
				'upper case'   => [ strtoupper( $live['token'] ), true ],
				'unknown'      => [ str_repeat( 'a', 32 ), true ],
				'expired'      => [ $expired['token'], true ],
				'revoked'      => [ $revoked['token'], true ],
				'backup gone'  => [ $gone['token'], true ],
				'other folder' => [ $other['token'], true ],
				'no fingerprint (made before they were stored)' => [ $legacy['token'], true ],
				'another file under the name' => [ $changed['token'], true ],
				'subsite'      => [ $live['token'], false ],
			];
			$refusal = Permission::shareRefusal();
			$this->assertSame( 'lw_migration_share_unavailable', $refusal->get_error_code() );
			$this->assertSame( 'This link is not available.', $refusal->get_error_message() );
			$this->assertSame( [ 'status' => 404 ], $refusal->get_error_data() );
			$before          = $db->rows;
			$db->beforeWrite = function (): void {
				$this->fail( 'A refusal writes nothing.' );
			};
			foreach ( $cases as $label => [ $token, $mainSite ] ) {
				foreach ( [ true, false ] as $admin ) {
					$this->network( $mainSite );
					Functions\when( 'is_user_logged_in' )->justReturn( $admin );
					Functions\when( 'current_user_can' )->justReturn( $admin );
					Functions\when( 'wp_validate_auth_cookie' )->justReturn( $admin ? 1 : false );
					$error = Permission::share( $this->shareRequest( $token ) );
					$this->assertInstanceOf( \WP_Error::class, $error, $label );
					$this->assertSame( [ $refusal->get_error_code(), $refusal->get_error_message(), $refusal->get_error_data() ], [ $error->get_error_code(), $error->get_error_message(), $error->get_error_data() ], $label );
				}
			}
			$this->assertSame( $before, $db->rows, 'No row changed.' );

			$this->network( true );
			$this->assertTrue( Permission::share( $this->shareRequest( $live['token'] ) ) );
			$target = Permission::shareTarget( $live['token'] );
			$this->assertSame( $live['id'], $target['id'] ?? null );
			$this->assertSame( $name, $target['backup']->name ?? null );
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}
	}

	public function test_a_share_without_a_stored_storage_folder_is_refused_without_naming_one(): void {
		$db              = FakeWpdb::install();
		$link            = ( new Shares() )->create( Backup::OWN, 'site-20261009-120000-001.wpress', 1, time(), 3600, str_repeat( 'a', 32 ) );
		$before          = $db->rows;
		$db->beforeWrite = function (): void {
			$this->fail( 'The storage name is never picked by a link.' );
		};

		$this->assertNull( Permission::shareTarget( $link['token'] ) );
		$this->assertSame( $before, $db->rows );
	}

	/**
	 * @param mixed $token The share parameter (null = none).
	 */
	private function shareRequest( $token ): \WP_REST_Request {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'share' )->andReturn( $token );

		return $request;
	}

	/**
	 * A network whose current blog is (or is not) the main site of the main network.
	 */
	private function network( bool $mainSite ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
	}

	/**
	 * Transients in memory, one login session, a fixed salt.
	 */
	private function tokenStore(): void {
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );
		Functions\when( 'wp_get_session_token' )->justReturn( 'session-a' );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ): bool {
				$this->store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->store[ $key ] ?? false );
		Functions\when( 'delete_transient' )->justReturn( true );
	}
}

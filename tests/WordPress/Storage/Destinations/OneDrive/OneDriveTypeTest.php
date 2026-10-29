<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OneDrive;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthView;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Pkce;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\OneDriveType;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class OneDriveTypeTest extends MonkeyTestCase {

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => [ '20.190.160.1' ] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	public function test_settings_default_to_the_common_tenant_and_the_app_folder_and_refuse_junk(): void {
		$type = new OneDriveType();

		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'c', 'tenant' => 'common', 'access' => 'appfolder' ], $type->settings( [ 'clientId' => 'c' ] ) );
		$this->assertSame( 'contoso.onmicrosoft.com', $type->settings( [ 'clientId' => 'c', 'tenant' => ' Contoso.onmicrosoft.com ', 'access' => 'files' ] )['tenant'] );
		foreach ( [ [ 'tenant' => 'a b' ], [ 'tenant' => '../x' ], [ 'tenant' => 'contoso..com' ], [ 'tenant' => '-a.com' ], [ 'tenant' => [ 'x' ] ], [ 'access' => 'everything' ] ] as $bad ) {
			try {
				$type->settings( [ 'clientId' => 'c' ] + $bad );
				$this->fail( 'Refused.' );
			} catch ( FieldException $e ) {
				$this->assertSame( array_key_first( $bad ), $e->field );
			}
		}
	}

	public function test_the_access_choice_picks_the_scope_and_the_tenant_the_endpoint(): void {
		$provider = ( new OneDriveType() )->provider( [ 'tenant' => 'organizations', 'access' => 'files' ] );

		$this->assertStringContainsString( 'Files.ReadWrite', $provider->scope() );
		$this->assertStringNotContainsString( 'AppFolder', $provider->scope() );
		$this->assertStringContainsString( '/organizations/oauth2/v2.0/token', $provider->tokenEndpoint );
		$this->assertStringContainsString( 'Files.ReadWrite.AppFolder', ( new OneDriveType() )->provider( [] )->scope() );
	}

	/**
	 * Task 5 carry: a stored tenant that does not fit (an older or damaged
	 * profile) reads as common, so the destination list never fails on it.
	 */
	public function test_a_stored_tenant_that_does_not_fit_reads_as_common(): void {
		$type = new OneDriveType();

		$this->assertStringContainsString( '/common/oauth2/v2.0/token', $type->provider( [ 'tenant' => '../evil' ] )->tokenEndpoint );
		$this->assertStringContainsString( '/contoso.com/oauth2/v2.0/token', $type->provider( [ 'tenant' => 'Contoso.COM' ] )->tokenEndpoint );
		$this->assertStringContainsString( 'AppFolder', $type->provider( [ 'access' => 7 ] )->scope() );
		$this->assertSame( 'microsoft', OAuthView::schema( $type, [ 'tenant' => '../evil' ], 'https://site.test/wp-json/lw-migration/v1/oauth/callback' )['provider'] );
	}

	/**
	 * Task 10 ruling: tokens from another authority or consent never refresh
	 * against the new one.
	 */
	public function test_a_tenant_or_permission_change_is_another_grant(): void {
		$type = new OneDriveType();

		$this->assertTrue( $type->sameGrant( [ 'clientId' => 'c' ], [ 'clientId' => 'c', 'tenant' => 'COMMON', 'access' => 'appfolder' ] ), 'Defaults are the same.' );
		$this->assertFalse( $type->sameGrant( [ 'tenant' => 'common' ], [ 'tenant' => 'organizations' ] ) );
		$this->assertFalse( $type->sameGrant( [ 'access' => 'appfolder' ], [ 'access' => 'files' ] ) );
	}

	public function test_connect_touches_the_app_folder_and_names_the_account_from_the_id_token(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"id":"approot-id","name":"LW Migration"}' ) ] );
		$jwt      = Pkce::encode( '{}' ) . '.' . Pkce::encode( '{"preferred_username":"admin@contoso.com"}' ) . '.s';

		$connection = ( new OneDriveType() )->connect( OneDriveDestinationTest::http( $recorded, $this->curl ), new TokenSet( 'a', 1, 'r', $jwt ), new Profile( 'abcd1234', 'onedrive', 'D' ) );

		$this->assertSame( [ 'account' => 'admin@contoso.com', 'folder' => 'wphu-org' ], $connection );
		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/special/approot', $recorded->requests[0][0] );
		$this->assertSame( 'Bearer eyJ0', $recorded->requests[0][1]['headers']['Authorization'] );
	}

	public function test_a_reconnect_keeps_the_pinned_folder(): void {
		Functions\when( 'home_url' )->justReturn( 'https://new-site.example' );
		$profile             = new Profile( 'abcd1234', 'onedrive', 'D' );
		$profile->connection = [ 'account' => 'a', 'folder' => 'old-site' ];
		$recorded            = new RecordedHttp( [ RecordedHttp::answer( 200, '{"id":"approot-id"}' ) ] );

		$connection = ( new OneDriveType() )->connect( OneDriveDestinationTest::http( $recorded, $this->curl ), new TokenSet( 'a', 1, 'r' ), $profile );

		$this->assertSame( 'old-site', $connection['folder'] );
	}

	public function test_connect_without_a_label_names_a_microsoft_account_and_a_refusal_is_classified(): void {
		$plain = new RecordedHttp( [ RecordedHttp::answer( 200, '{"id":"approot-id"}' ) ] );
		$this->assertSame( [ 'account' => 'Microsoft account', 'folder' => 'wphu-org' ], ( new OneDriveType() )->connect( OneDriveDestinationTest::http( $plain, $this->curl ), new TokenSet( 'a', 1, 'r' ), new Profile( 'abcd1234', 'onedrive', 'D' ) ) );

		try {
			( new OneDriveType() )->connect( OneDriveDestinationTest::http( new RecordedHttp( [ RecordedHttp::answer( 403, '{"error":{"code":"accessDenied","message":"Secret text"}}' ) ] ), $this->curl ), new TokenSet( 'a', 1, 'r' ), new Profile( 'abcd1234', 'onedrive', 'D' ) );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::DENIED, $e->reason );
			$this->assertStringNotContainsString( 'Secret', $e->getMessage() );
		}
	}

	public function test_it_is_the_onedrive_type_with_a_tenant_and_a_permission_field(): void {
		$type = new OneDriveType();
		$keys = array_map( static fn( $field ): string => $field->key, $type->fields() );

		$this->assertSame( 'onedrive', $type->key() );
		$this->assertSame( 'OneDrive', $type->label() );
		$this->assertSame( [ 'ownApp', 'clientId', 'clientSecret', 'tenant', 'access', 'account' ], $keys );
		$this->assertCount( 4, $type->steps() );
		$this->assertStringStartsWith( 'https://learn.microsoft.com/', $type->consoleUrl() );
	}

	public function test_the_lw_app_stores_no_tenant_or_permission_and_signs_in_common_with_the_app_folder(): void {
		$type   = new OneDriveType();
		$fields = [];
		foreach ( $type->fields() as $field ) {
			$fields[ $field->key ] = $field;
		}

		$this->assertSame( [ 'ownApp' => false ], $type->settings( [ 'ownApp' => false, 'tenant' => 'organizations', 'access' => 'files' ] ) );
		$this->assertSame( 'common appfolder', $type->grantMark( [ 'ownApp' => false ] ) );
		$this->assertStringContainsString( '/common/oauth2/v2.0/', $type->provider( [ 'ownApp' => false ] )->authorizeEndpoint );
		$this->assertSame( "\0lw-relay:microsoft\0common appfolder", $type->clientKey( [ 'ownApp' => false ] ) );
		$this->assertSame( [ 'ownApp' => [ true ] ], $fields['tenant']->when );
		$this->assertSame( [ 'ownApp' => [ true ] ], $fields['access']->when );
	}

	public function test_the_where_line_names_the_site_folder_pinned_or_run_time(): void {
		$profile             = new Profile( 'abcd1234', 'onedrive', 'D' );
		$profile->connection = [ 'account' => 'a', 'folder' => 'wphu-org' ];
		$this->assertSame( 'OneDrive · wphu-org', ( new OneDriveType() )->describe( $profile ) );

		Functions\when( 'home_url' )->justReturn( 'https://wphu.org/blog' );
		$profile->connection = [ 'account' => 'a' ];
		$this->assertSame( 'OneDrive · wphu-org-blog', ( new OneDriveType() )->describe( $profile ) );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Dropbox;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\DropboxType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DropboxTypeTest extends MonkeyTestCase {

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => [ '162.125.1.20' ] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/**
	 * The call takes no argument: its JSON is null (with an empty POST,
	 * cURL would label the body as a form, which an RPC call does not take).
	 */
	public function test_connect_reads_the_account_email(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"account_id":"dbid:AA","email":"office@example.com","email_verified":true}' ) ] );

		$connection = ( new DropboxType() )->connect( DropboxDestinationTest::http( $recorded, $this->curl ), new TokenSet( 'a', 1, 'r', '', 'dbid:AA' ), new Profile( 'abcd1234', 'dropbox', 'D' ) );

		$this->assertSame( [ 'account' => 'office@example.com', 'folder' => 'wphu-org' ], $connection );
		[ $url, $args ] = $recorded->requests[0];
		$this->assertSame( 'https://api.dropboxapi.com/2/users/get_current_account', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 'null', $args['body'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'Bearer sl.b', $args['headers']['Authorization'] );
	}

	public function test_a_reconnect_keeps_the_pinned_folder(): void {
		Functions\when( 'home_url' )->justReturn( 'https://new-site.example' );
		$profile             = new Profile( 'abcd1234', 'dropbox', 'D' );
		$profile->connection = [ 'account' => 'a', 'folder' => 'old-site' ];
		$recorded            = new RecordedHttp( [ RecordedHttp::answer( 200, '{"email":"office@example.com"}' ) ] );

		$connection = ( new DropboxType() )->connect( DropboxDestinationTest::http( $recorded, $this->curl ), new TokenSet( 'a', 1, 'r' ), $profile );

		$this->assertSame( 'old-site', $connection['folder'] );
	}

	public function test_connect_without_an_email_names_dropbox_and_a_refusal_is_classified(): void {
		$plain = new RecordedHttp( [ RecordedHttp::answer( 200, '{"account_id":"dbid:AA","email":7}' ) ] );
		$this->assertSame( [ 'account' => 'Dropbox', 'folder' => 'wphu-org' ], ( new DropboxType() )->connect( DropboxDestinationTest::http( $plain, $this->curl ), new TokenSet( 'a', 1, 'r' ), new Profile( 'abcd1234', 'dropbox', 'D' ) ) );

		try {
			( new DropboxType() )->connect( DropboxDestinationTest::http( new RecordedHttp( [ RecordedHttp::answer( 403, '{"error_summary":"Secret text"}' ) ] ), $this->curl ), new TokenSet( 'a', 1, 'r' ), new Profile( 'abcd1234', 'dropbox', 'D' ) );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::DENIED, $e->reason );
			$this->assertStringNotContainsString( 'Secret', $e->getMessage() );
		}
	}

	public function test_it_is_the_dropbox_type_with_four_steps(): void {
		$type = new DropboxType();

		$this->assertSame( 'dropbox', $type->key() );
		$this->assertSame( 'Dropbox', $type->label() );
		$this->assertSame( 'dropbox', $type->provider( [] )->key );
		$this->assertCount( 4, $type->steps() );
		$this->assertStringContainsString( 'App folder', implode( ' ', $type->steps() ) );
		$this->assertStringStartsWith( 'https://www.dropbox.com/developers/apps', $type->consoleUrl() );
	}

	public function test_the_where_line_names_the_site_folder_pinned_or_run_time(): void {
		$profile             = new Profile( 'abcd1234', 'dropbox', 'D' );
		$profile->connection = [ 'account' => 'a', 'folder' => 'wphu-org' ];
		$this->assertSame( 'Dropbox · wphu-org', ( new DropboxType() )->describe( $profile ) );

		Functions\when( 'home_url' )->justReturn( 'https://wphu.org/blog' );
		$profile->connection = [ 'account' => 'a' ];
		$this->assertSame( 'Dropbox · wphu-org-blog', ( new DropboxType() )->describe( $profile ) );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\GoogleDrive;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\GoogleDrive\GoogleDriveDestination;
use LightweightPlugins\Migration\Storage\Destinations\GoogleDrive\GoogleDriveType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class GoogleDriveTypeTest extends MonkeyTestCase {

	private const NOW = 1_800_000_000;

	private FakeWpdb $db;

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => [ '142.250.1.95' ] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	public function test_connect_reads_the_account_and_finds_the_folder(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"user":{"displayName":"Office","emailAddress":"office@example.com"}}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"parent-7"}]}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"folder-7"}]}' ),
			]
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );

		$connection = ( new GoogleDriveType() )->connect( GoogleDriveDestinationTest::http( $recorded, $this->curl ), new TokenSet( 'a', 1, 'r' ), new Profile( 'abcd1234', 'gdrive', 'D' ) );

		$this->assertSame( [ 'account' => 'office@example.com', 'folderId' => 'folder-7', 'folder' => 'wphu-org' ], $connection );
		$this->assertSame( 'https://www.googleapis.com/drive/v3/about?fields=user(displayName,emailAddress)', $recorded->requests[0][0] );
		$this->assertSame( 'Bearer ya29', $recorded->requests[0][1]['headers']['Authorization'] );
	}

	public function test_a_reconnect_keeps_the_pinned_folder(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://new-site.example' );
		$profile             = new Profile( 'abcd1234', 'gdrive', 'D' );
		$profile->connection = [ 'account' => 'a', 'folder' => 'old-site' ];
		$recorded            = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"user":{"emailAddress":"office@example.com"}}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"parent-7"}]}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"old-folder"}]}' ),
			]
		);

		$connection = ( new GoogleDriveType() )->connect( GoogleDriveDestinationTest::http( $recorded, $this->curl ), new TokenSet( 'a', 1, 'r' ), $profile );

		$this->assertSame( 'old-site', $connection['folder'] );
		$this->assertSame( 'old-folder', $connection['folderId'] );
		$this->assertStringContainsString( 'old-site', rawurldecode( $recorded->requests[2][0] ) );
		$this->assertStringNotContainsString( 'new-site', rawurldecode( $recorded->requests[2][0] ) );
	}

	public function test_connect_names_the_account_by_its_display_name_without_an_address(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"user":{"displayName":"Office"}}' ), RecordedHttp::answer( 200, '{"files":[{"id":"parent-8"}]}' ), RecordedHttp::answer( 200, '{"files":[]}' ), RecordedHttp::answer( 200, '{"id":"folder-8"}' ) ] );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );

		$connection = ( new GoogleDriveType() )->connect( GoogleDriveDestinationTest::http( $recorded, $this->curl ), new TokenSet( 'a', 1, 'r' ), new Profile( 'abcd1234', 'gdrive', 'D' ) );

		$this->assertSame( [ 'account' => 'Office', 'folderId' => 'folder-8', 'folder' => 'wphu-org' ], $connection );
	}

	public function test_it_is_the_gdrive_type_with_google_rules_and_five_steps(): void {
		$type = new GoogleDriveType();

		$this->assertSame( 'gdrive', $type->key() );
		$this->assertSame( 'Google Drive', $type->label() );
		$this->assertSame( 'google', $type->provider( [] )->key );
		$this->assertCount( 5, $type->steps() );
		$this->assertStringContainsString( 'In production', implode( ' ', $type->steps() ) );
		$this->assertStringContainsString( 'drive.file', implode( ' ', $type->steps() ) );
		$this->assertStringStartsWith( 'https://console.cloud.google.com/', $type->consoleUrl() );
	}

	/**
	 * P-C1 / Task 7 m3: a folder found again is stored through
	 * ProfileRepository::remember(), bound to the connection the destination
	 * was made for — never by saving a profile read before the request.
	 */
	public function test_a_folder_found_again_is_remembered_for_its_connection_only(): void {
		$vault   = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$profile = new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] );
		$repo    = new ProfileRepository( static fn(): int => self::NOW );
		$repo->save( $profile );
		$repo->connect(
			'abcd1234',
			[
				'account'     => 'a',
				'client'      => ( new OAuthState() )->clientMark( 'cid' ),
				'id'          => TokenKeeper::connectionId( str_repeat( 'a', 32 ) ),
				'connectedAt' => self::NOW - 1000,
				'folderId'    => 'old-folder',
				'folder'      => 'wphu-org',
			],
			static fn( array $settings ): bool => 'cid' === ( $settings['clientId'] ?? null )
		);
		$secrets  = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => ( self::NOW + 3600 ) . ':ya29', TokenKeeper::GRANT => str_repeat( 'a', 32 ) ];
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 404 ),
				RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"new-folder"}]}' ),
				RecordedHttp::answer( 200, '{"files":[]}' ),
				RecordedHttp::answer( 200, '', [ 'Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=u1' ] ),
			]
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		$type     = new GoogleDriveType( $vault, null, $this->curl->wrap( $recorded->send() ), static fn(): int => self::NOW );

		$drive = $type->make( $repo->find( 'abcd1234' ), $secrets );
		$drive->startUpload( 'site-20261012-030000-123.wpress', 10 );

		$this->assertInstanceOf( GoogleDriveDestination::class, $drive );
		$this->assertSame( 'new-folder', $repo->find( 'abcd1234' )->connection['folderId'] );
		$this->assertStringContainsString( 'new-folder', (string) $this->db->value( Options::DESTINATIONS ) );
	}

	public function test_a_folder_found_after_a_reconnect_is_not_written_into_the_new_connection(): void {
		$vault   = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$repo    = new ProfileRepository( static fn(): int => self::NOW );
		$profile = new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] );
		$repo->save( $profile );
		$old = [
			'account'     => 'a',
			'client'      => ( new OAuthState() )->clientMark( 'cid' ),
			'id'          => TokenKeeper::connectionId( str_repeat( 'a', 32 ) ),
			'connectedAt' => self::NOW - 1000,
			'folderId'    => 'old-folder',
				'folder'      => 'wphu-org',
		];
		$repo->connect( 'abcd1234', $old, static fn( array $settings ): bool => 'cid' === ( $settings['clientId'] ?? null ) );
		$opened   = $repo->find( 'abcd1234' );
		$secrets  = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => ( self::NOW + 3600 ) . ':ya29', TokenKeeper::GRANT => str_repeat( 'a', 32 ) ];
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 404 ),
				static function () use ( $repo, $old ): array {
					$repo->connect( 'abcd1234', [ 'id' => TokenKeeper::connectionId( str_repeat( 'b', 32 ) ), 'folderId' => 'their-folder' ] + $old, static fn( array $settings ): bool => 'cid' === ( $settings['clientId'] ?? null ) );
					return RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' );
				},
				RecordedHttp::answer( 200, '{"files":[{"id":"new-folder"}]}' ),
				RecordedHttp::answer( 200, '{"files":[]}' ),
				RecordedHttp::answer( 200, '', [ 'Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=u1' ] ),
			]
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		$type     = new GoogleDriveType( $vault, null, $this->curl->wrap( $recorded->send() ), static fn(): int => self::NOW );

		$type->make( $opened, $secrets )->startUpload( 'site-20261012-030000-123.wpress', 10 );

		$this->assertSame( 'their-folder', $repo->find( 'abcd1234' )->connection['folderId'] );
	}

	public function test_the_where_line_names_the_pinned_folder_inside_lw_migration(): void {
		$profile             = new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] );
		$profile->connection = [ 'account' => 'a', 'folder' => 'wphu-org' ];

		$this->assertSame( 'Google Drive · LW Migration/wphu-org', ( new GoogleDriveType() )->describe( $profile ) );
	}

	public function test_the_where_line_of_a_profile_without_a_folder_uses_the_run_time_slug(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org/blog' );
		$profile             = new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] );
		$profile->connection = [ 'account' => 'a' ];

		$this->assertSame( 'Google Drive · LW Migration/wphu-org-blog', ( new GoogleDriveType() )->describe( $profile ) );
		$this->assertSame( 'Not connected yet', ( new GoogleDriveType() )->describe( new Profile( 'abcd1234', 'gdrive', 'Drive' ) ) );
	}

	/**
	 * @param array<string, string> $extra Connection keys besides the base ones.
	 * @return array{0: ProfileRepository, 1: GoogleDriveType, 2: RecordedHttp}
	 */
	private function connectedRepo( array $extra, RecordedHttp $recorded ): array {
		$vault = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$repo  = new ProfileRepository( static fn(): int => self::NOW );
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] ) );
		$repo->connect(
			'abcd1234',
			[
				'account'     => 'a',
				'client'      => ( new OAuthState() )->clientMark( 'cid' ),
				'id'          => TokenKeeper::connectionId( str_repeat( 'a', 32 ) ),
				'connectedAt' => self::NOW - 1000,
			] + $extra,
			static fn( array $settings ): bool => 'cid' === ( $settings['clientId'] ?? null )
		);

		return [ $repo, new GoogleDriveType( $vault, null, $this->curl->wrap( $recorded->send() ), static fn(): int => self::NOW ), $recorded ];
	}

	public function test_a_profile_connected_before_site_folders_never_uses_its_stored_root(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		$recorded            = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"site-1"}]}' ),
				RecordedHttp::answer( 200, '{"files":[]}' ),
				RecordedHttp::answer( 200, '', [ 'Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=u1' ] ),
			]
		);
		[ $repo, $type ]     = $this->connectedRepo( [ 'folderId' => 'old-root' ], $recorded );
		$secrets             = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => ( self::NOW + 3600 ) . ':ya29', TokenKeeper::GRANT => str_repeat( 'a', 32 ) ];
		$drive               = $type->make( $repo->find( 'abcd1234' ), $secrets );

		$this->assertSame( [], $drive->list(), 'No folder yet: nothing is listed, the root is not asked.' );
		$this->assertSame( [], $recorded->requests );
		$drive->startUpload( 'site-20261012-030000-123.wpress', 10 );

		foreach ( $recorded->requests as $request ) {
			$this->assertStringNotContainsString( 'old-root', rawurldecode( $request[0] ) );
		}
		$this->assertStringContainsString( "key='lwMigration' and value='backups'", rawurldecode( $recorded->requests[0][0] ) );
		$this->assertStringContainsString( "'parent-1' in parents and appProperties has { key='lwMigrationSite' and value='wphu-org' }", rawurldecode( $recorded->requests[1][0] ) );
		$this->assertStringContainsString( "'site-1' in parents", rawurldecode( $recorded->requests[2][0] ) );
		$connection = $repo->find( 'abcd1234' )->connection;
		$this->assertSame( 'site-1', $connection['folderId'] );
		$this->assertSame( 'wphu-org', $connection['folder'] );
	}

	public function test_a_profile_with_a_pinned_folder_keeps_its_folder_id(): void {
		$recorded        = new RecordedHttp( [ RecordedHttp::answer( 200, '{"files":[]}' ) ] );
		[ $repo, $type ] = $this->connectedRepo( [ 'folderId' => 'site-1', 'folder' => 'wphu-org' ], $recorded );
		$secrets         = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => ( self::NOW + 3600 ) . ':ya29', TokenKeeper::GRANT => str_repeat( 'a', 32 ) ];

		$type->make( $repo->find( 'abcd1234' ), $secrets )->list();

		$this->assertStringContainsString( "'site-1' in parents", rawurldecode( $recorded->requests[0][0] ) );
	}
}

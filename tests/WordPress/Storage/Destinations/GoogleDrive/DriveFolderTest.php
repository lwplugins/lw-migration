<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\GoogleDrive;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\GoogleDrive\DriveFolder;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DriveFolderTest extends MonkeyTestCase {

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => [ '142.250.1.95' ] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
	}

	private function ensure( RecordedHttp $recorded, string $site ): string {
		return DriveFolder::ensure( GoogleDriveDestinationTest::http( $recorded, $this->curl ), $site );
	}

	public function test_it_finds_the_parent_and_then_the_site_folder_inside_it(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ), RecordedHttp::answer( 200, '{"files":[{"id":"site-1"},{"id":"site-2"}]}' ) ] );

		$this->assertSame( 'site-1', $this->ensure( $recorded, 'wphu-org' ) );

		$this->assertCount( 2, $recorded->requests );
		$parent = rawurldecode( $recorded->requests[0][0] );
		$site   = rawurldecode( $recorded->requests[1][0] );
		$this->assertStringContainsString( "appProperties has { key='lwMigration' and value='backups' }", $parent );
		$this->assertStringContainsString( "mimeType = 'application/vnd.google-apps.folder' and 'parent-1' in parents and appProperties has { key='lwMigrationSite' and value='wphu-org' } and trashed = false", $site );
		$this->assertStringContainsString( 'orderBy=createdTime', $site );
	}

	public function test_it_creates_the_missing_site_folder_inside_the_parent(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ), RecordedHttp::answer( 200, '{"files":[]}' ), RecordedHttp::answer( 200, '{"id":"site-9"}' ) ] );

		$this->assertSame( 'site-9', $this->ensure( $recorded, 'wphu-org' ) );

		$this->assertSame( 'POST', $recorded->requests[2][1]['method'] );
		$this->assertSame(
			[
				'name'          => 'wphu-org',
				'mimeType'      => 'application/vnd.google-apps.folder',
				'parents'       => [ 'parent-1' ],
				'appProperties' => [ 'lwMigration' => 'site', 'lwMigrationSite' => 'wphu-org' ],
			],
			json_decode( $recorded->requests[2][1]['body'], true )
		);
	}

	public function test_it_creates_both_when_neither_exists_in_order(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"files":[]}' ),
				RecordedHttp::answer( 200, '{"id":"parent-5"}' ),
				RecordedHttp::answer( 200, '{"files":[]}' ),
				RecordedHttp::answer( 200, '{"id":"site-5"}' ),
			]
		);

		$this->assertSame( 'site-5', $this->ensure( $recorded, 'wphu-org' ) );

		$this->assertCount( 4, $recorded->requests );
		$this->assertSame( [ 'GET', 'POST', 'GET', 'POST' ], array_map( static fn( array $r ): string => (string) ( $r[1]['method'] ?? '' ), $recorded->requests ) );
		$this->assertSame( 'LW Migration', json_decode( $recorded->requests[1][1]['body'], true )['name'] );
		$this->assertStringContainsString( "'parent-5' in parents", rawurldecode( $recorded->requests[2][0] ) );
		$this->assertSame( [ 'parent-5' ], json_decode( $recorded->requests[3][1]['body'], true )['parents'] );
	}

	public function test_two_sites_under_one_parent_are_searched_apart(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"files":[{"id":"p"}]}' ), RecordedHttp::answer( 200, '{"files":[{"id":"a"}]}' ), RecordedHttp::answer( 200, '{"files":[{"id":"p"}]}' ), RecordedHttp::answer( 200, '{"files":[{"id":"b"}]}' ) ] );

		$this->assertSame( 'a', $this->ensure( $recorded, 'one-example' ) );
		$this->assertSame( 'b', $this->ensure( $recorded, 'two-example' ) );

		$this->assertStringContainsString( "value='one-example'", rawurldecode( $recorded->requests[1][0] ) );
		$this->assertStringContainsString( "value='two-example'", rawurldecode( $recorded->requests[3][0] ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function badSites(): array {
		return [ 'empty' => [ '' ], 'quote' => [ "a'b" ], 'upper' => [ 'Site' ], 'slash' => [ 'a/b' ], 'leading dash' => [ '-a' ], 'too long' => [ str_repeat( 'a', 101 ) ] ];
	}

	/**
	 * @dataProvider badSites
	 */
	public function test_a_bad_site_is_invalid_before_any_request( string $site ): void {
		$recorded = new RecordedHttp( [] );

		try {
			$this->ensure( $recorded, $site );
			$this->fail( 'Expected a PermanentFailure.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $recorded->requests );
	}
}

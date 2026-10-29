<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\DavFolder;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavAddress;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavClient;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DavFolderTest extends MonkeyTestCase {

	private const LISTING = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
		. '<d:response><d:href>/remote.php/dav/files/alice/b/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/files/alice/b/site-20261012-030000-123.wpress</d:href><d:propstat><d:prop><d:getcontentlength>42</d:getcontentlength><d:getlastmodified>Mon, 12 Oct 2026 21:41:10 GMT</d:getlastmodified><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/files/alice/b/site-20261012-030000-123.wpress.part</d:href><d:propstat><d:prop><d:getcontentlength>7</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/files/alice/b/notes.txt</d:href><d:propstat><d:prop><d:getcontentlength>3</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/files/alice/b/lw-migration-test-0123456789abcdef.txt</d:href><d:propstat><d:prop><d:getcontentlength>5</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '<d:response><d:href>/remote.php/dav/files/alice/b/sub.wpress/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
		. '</d:multistatus>';

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn(): array => [ '93.184.216.34' ] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function folder( RecordedHttp $http, string $folder = 'b' ): DavFolder {
		$address = WebDavAddress::fromSettings( 'https://cloud.example/remote.php/dav/files/alice/', $folder );

		return new DavFolder( new WebDavClient( 'alice', 'pw', $address->display(), $this->curl->wrap( $http->send() ), static fn(): int => 1760000000 ), $address );
	}

	public function test_ensure_creates_each_missing_level_and_takes_an_existing_one(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 405 ), RecordedHttp::answer( 201 ) ] );

		$this->folder( $http, 'a/b' )->ensure();

		$this->assertSame( [ 'MKCOL', 'MKCOL' ], array_map( static fn( array $r ): string => $r[1]['method'], $http->requests ) );
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/a/b/', $http->requests[1][0] );
	}

	public function test_ensure_passes_a_wrong_password_on(): void {
		$this->expectException( PermanentFailure::class );
		$this->folder( new RecordedHttp( [ RecordedHttp::answer( 401 ) ] ) )->ensure();
	}

	public function test_files_are_the_backups_and_the_test_file_directly_in_the_folder(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207, self::LISTING ) ] );

		$files = $this->folder( $http )->files();

		$this->assertSame( [ 'site-20261012-030000-123.wpress', 'lw-migration-test-0123456789abcdef.txt' ], array_map( static fn( $f ): string => $f->name, $files ) );
		$this->assertSame( [ 42, gmmktime( 21, 41, 10, 10, 12, 2026 ) ], [ $files[0]->size, $files[0]->mtime ] );
		$this->assertSame( '1', $http->requests[0][1]['headers']['Depth'] );
	}

	/** Preflight P-M13: an entry whose parent is not the folder adds no name (a deeper listing, a foreign href). */
	public function test_entries_outside_the_folder_are_left_out(): void {
		$entry   = static fn( string $href ): string => '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop><d:getcontentlength>9</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
		$listing = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
			. $entry( '/remote.php/dav/files/alice/b/old/site-20261001-030000-000.wpress' )
			. $entry( '/remote.php/dav/files/alice/site-20261002-030000-000.wpress' )
			. $entry( '/elsewhere/b/site-20261003-030000-000.wpress' )
			. $entry( '/remote.php/dav/files/alice/b/x%2F..%2Fsite-20261004-030000-000.wpress' )
			. $entry( 'https://cloud.example/remote.php/dav/files/alice/b/site-20261005-030000-000.wpress' )
			. '</d:multistatus>';

		$files = $this->folder( new RecordedHttp( [ RecordedHttp::answer( 207, $listing ) ] ) )->files();

		$this->assertSame( [ 'site-20261005-030000-000.wpress' ], array_map( static fn( $f ): string => $f->name, $files ) );
	}

	public function test_a_folder_that_does_not_exist_yet_has_no_files(): void {
		$this->assertSame( [], $this->folder( new RecordedHttp( [ RecordedHttp::answer( 404 ) ] ) )->files() );
	}

	public function test_an_answer_that_is_not_a_listing_is_a_passing_failure_naming_only_the_server(): void {
		try {
			$this->folder( new RecordedHttp( [ RecordedHttp::answer( 207, '<html>login</html>' ) ] ) )->files();
			$this->fail( 'Thrown.' );
		} catch ( TransientFailure $e ) {
			$this->assertStringContainsString( 'https://cloud.example', $e->getMessage() );
			$this->assertStringNotContainsString( 'remote.php', $e->getMessage() );
		}
	}

	public function test_the_size_of_one_file_or_minus_one_when_it_is_missing(): void {
		$one  = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/alice/b/x.wpress</d:href><d:propstat><d:prop><d:getcontentlength>99</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
		$http = new RecordedHttp( [ RecordedHttp::answer( 207, $one ), RecordedHttp::answer( 404 ), RecordedHttp::answer( 207, $one ) ] );
		$f    = $this->folder( $http );

		$this->assertSame( 99, $f->sizeOf( 'x.wpress' ) );
		$this->assertSame( -1, $f->sizeOf( 'y.wpress' ) );
		$this->assertSame( -1, $f->sizeOf( 'z.wpress' ), 'An answer about another file is no size of this one.' );
		$this->assertSame( '0', $http->requests[0][1]['headers']['Depth'] );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavAddress;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavClient;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavDestination;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Final review I2: the plain MOVE never replaces a file. Backup names are
 * unique per backup, but an imported backup keeps the name its owner gave
 * it, so a file under the name is ours only when it has the backup's size
 * (the rule of FolderDestination::place()).
 */
final class WebDavDestinationMoveTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn(): array => [ '93.184.216.34' ] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function finish( RecordedHttp $http ): \LightweightPlugins\Migration\Storage\Destinations\RemoteFile {
		$address = WebDavAddress::fromSettings( 'https://dav.example/', '' );
		$client  = new WebDavClient( 'alice', 'pw', $address->display(), $this->curl->wrap( $http->send() ), static fn(): int => 1760000000 );
		$dav     = new WebDavDestination( $client, $address, WebDavDestination::PLAIN, static fn(): bool => false );

		return $dav->finishUpload( new UploadSession( 'webdav', self::NAME, 23, 23, 23, self::NAME . '.lwm-part', [ 'mode' => 'webdav' ] ) );
	}

	private static function listing( int $size ): string {
		return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/' . self::NAME . '</d:href><d:propstat><d:prop><d:getcontentlength>' . $size . '</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
	}

	public function test_the_move_asks_never_to_overwrite(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );

		$this->finish( $http );

		$this->assertSame( 'F', $http->requests[0][1]['headers']['Overwrite'] );
	}

	public function test_a_taken_name_holding_this_backups_size_is_this_backup_and_the_part_file_goes(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 412 ), RecordedHttp::answer( 207, self::listing( 23 ) ), RecordedHttp::answer( 204 ) ] );

		$file = $this->finish( $http );

		$this->assertSame( [ self::NAME, 23 ], [ $file->name, $file->size ] );
		$this->assertSame( [ 'MOVE', 'PROPFIND', 'DELETE' ], array_map( static fn( array $r ): string => $r[1]['method'], $http->requests ) );
		$this->assertStringEndsWith( '/' . self::NAME . '.lwm-part', $http->requests[2][0] );
	}

	public function test_another_file_under_the_name_is_never_replaced(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 412 ), RecordedHttp::answer( 207, self::listing( 99 ) ) ] );

		try {
			$this->finish( $http );
			$this->fail( 'Another file has the name.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertSame( 'Another file with this backup\'s name is already on https://dav.example; it was not replaced.', $e->getMessage() );
		}
		$this->assertCount( 2, $http->requests, 'Nothing is deleted.' );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\DavFolder;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\FileSlice;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\NextcloudUpload;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavAddress;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavClient;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NextcloudUploadTest extends MonkeyTestCase {

	private const NAME = 'site-20261012-030000-123.wpress';

	private const MIB = 1048576;

	private int $now = 1760000000;

	private CurlHooks $curl;

	/** @var array<int, string> What the address check resolves every name to. */
	private array $ips = [ '93.184.216.34' ];

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( fn(): array => $this->ips );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function upload( RecordedHttp $http ): NextcloudUpload {
		$address = WebDavAddress::fromSettings( 'https://cloud.example/remote.php/dav/files/alice/', 'b' );
		$client  = new WebDavClient( 'alice', 'pw', $address->display(), $this->curl->wrap( $http->send() ), fn(): int => $this->now );

		return new NextcloudUpload( $client, $address, new DavFolder( $client, $address ) );
	}

	private function session( int $offset = 0, int $size = 25 * self::MIB ): UploadSession {
		return new UploadSession( 'webdav', self::NAME, $size, $offset, NextcloudUpload::chunkFor( $size ), 'lw-migration-0123456789abcdef', [ 'mode' => 'nextcloud', 'touched' => $this->now ] );
	}

	public function test_detects_nextcloud_by_its_status_page_only(): void {
		$nextcloud = '{"installed":true,"maintenance":false,"needsDbUpgrade":false,"version":"35.0.1.1","versionstring":"35.0.1","edition":"","productname":"Nextcloud","extendedSupport":false}';
		$http      = new RecordedHttp( [ RecordedHttp::answer( 200, $nextcloud ), RecordedHttp::answer( 200, '{"installed":true,"productname":"ownCloud"}' ), RecordedHttp::answer( 404 ) ] );
		$upload    = $this->upload( $http );

		$this->assertSame( [ true, false, false ], [ $upload->detect(), $upload->detect(), $upload->detect() ] );
		$this->assertSame( 'https://cloud.example/status.php', $http->requests[0][0] );
	}

	public function test_a_status_page_that_does_not_answer_is_a_passing_failure_not_a_plain_server(): void {
		$this->expectException( TransientFailure::class );
		$this->upload( new RecordedHttp( [ RecordedHttp::answer( 503 ) ] ) )->detect();
	}

	public function test_a_refused_address_is_passed_on_not_taken_for_a_plain_server(): void {
		$http      = new RecordedHttp( [ RecordedHttp::answer( 200, '{}' ) ] );
		$upload    = $this->upload( $http );
		$this->ips = [ '169.254.169.254' ]; // The name resolves elsewhere after it was saved.

		try {
			$upload->detect();
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_chunks_are_at_least_ten_mebibytes_and_never_more_than_ten_thousand(): void {
		$this->assertSame( 10 * self::MIB, NextcloudUpload::chunkFor( 1 ) );
		$this->assertSame( 10 * self::MIB, NextcloudUpload::chunkFor( 100000 * self::MIB ) );
		$this->assertLessThanOrEqual( 10000, (int) ceil( 1099511627776 / NextcloudUpload::chunkFor( 1099511627776 ) ) );
	}

	public function test_starts_an_upload_folder_and_sends_numbered_chunks_with_the_headers(): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/' . self::NAME, str_repeat( 'x', 10 * self::MIB ) );
		$http   = new RecordedHttp( [ RecordedHttp::answer( 201 ), RecordedHttp::answer( 201 ), RecordedHttp::answer( 204 ) ] );
		$upload = $this->upload( $http );

		try {
			$s          = $upload->start( 'webdav', self::NAME, 25 * self::MIB );
			$this->now += 60;
			$next       = $upload->part( $s, new FileSlice( $dir . '/' . self::NAME, 0, 10 * self::MIB ), 10 * self::MIB );
			$again      = $upload->part( $s, new FileSlice( $dir . '/' . self::NAME, 0, 10 * self::MIB ), 10 * self::MIB ); // The same chunk again: Nextcloud answers 204 and replaces it.
		} finally {
			TempDir::remove( $dir );
		}

		$this->assertSame( 'MKCOL', $http->requests[0][1]['method'] );
		$this->assertMatchesRegularExpression( '#^https://cloud\.example/remote\.php/dav/uploads/alice/lw-migration-[0-9a-f]{16}$#', $http->requests[0][0] );
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/b/' . self::NAME, $http->requests[0][1]['headers']['Destination'] );
		$this->assertSame( $http->requests[0][0] . '/00001', $http->requests[1][0] );
		$this->assertSame( (string) ( 25 * self::MIB ), $http->requests[1][1]['headers']['OC-Total-Length'] );
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/b/' . self::NAME, $http->requests[1][1]['headers']['Destination'] );
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/b/' . self::NAME, $http->requests[2][1]['headers']['Destination'] );
		$this->assertSame( 10 * self::MIB, $next->offset );
		$this->assertSame( $this->now, $next->extra['touched'] );
		$this->assertEquals( $next, $again );
		$this->assertSame( [ 'webdav', 10 * self::MIB, 'nextcloud' ], [ $s->type, $s->chunk, $s->extra['mode'] ] );
	}

	public function test_the_second_chunk_is_numbered_from_its_offset(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );

		$this->upload( $http )->part( $this->session( 10 * self::MIB ), 'x', 10 * self::MIB );

		$this->assertStringEndsWith( '/lw-migration-0123456789abcdef/00002', $http->requests[0][0] );
	}

	public function test_a_chunk_off_its_boundary_or_of_another_length_is_refused_before_sending(): void {
		$http   = new RecordedHttp();
		$upload = $this->upload( $http );

		foreach ( [ [ $this->session( 0 ), 5 ], [ $this->session( 3 ), 10 * self::MIB ], [ $this->session( 25 * self::MIB ), 0 ] ] as [ $s, $length ] ) {
			try {
				$upload->part( $s, 'x', $length );
				$this->fail( 'Refused.' );
			} catch ( \LogicException $e ) {
				$this->assertSame( [], $http->requests );
			}
		}
	}

	/**
	 * Final review m4: a chunk gets the time its bytes need at the slowest
	 * expected rate, like the plain PUT (a 105 MiB chunk of a 1 TiB backup).
	 */
	public function test_a_large_chunk_gets_the_time_its_bytes_need(): void {
		$size  = 1099511627776;
		$s     = $this->session( 0, $size );
		$dir   = TempDir::create();
		$file  = fopen( $dir . '/' . self::NAME, 'wb' );
		ftruncate( $file, $s->chunk ); // Sparse: no 105 MiB written.
		fclose( $file );
		$http  = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );

		try {
			$this->upload( $http )->part( $s, new FileSlice( $dir . '/' . self::NAME, 0, $s->chunk ), $s->chunk );
		} finally {
			TempDir::remove( $dir );
		}

		$this->assertSame( 110100480, $s->chunk );
		$this->assertSame( 420, $http->requests[0][1]['timeout'] );
	}

	public function test_finish_moves_the_assembled_file_into_place(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );

		$file = $this->upload( $http )->finish( $this->session( 25 * self::MIB ) );

		$this->assertSame( 'MOVE', $http->requests[0][1]['method'] );
		$this->assertStringEndsWith( '/lw-migration-0123456789abcdef/.file', $http->requests[0][0] );
		$this->assertSame( (string) ( 25 * self::MIB ), $http->requests[0][1]['headers']['OC-Total-Length'] );
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/b/' . self::NAME, $http->requests[0][1]['headers']['Destination'] );
		$this->assertSame( [ self::NAME, 25 * self::MIB, self::NAME ], [ $file->name, $file->size, $file->ref ] );
	}

	public function test_an_unfinished_upload_is_not_moved(): void {
		$http = new RecordedHttp();

		try {
			$this->upload( $http )->finish( $this->session( 10 * self::MIB ) );
			$this->fail( 'Refused.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( [], $http->requests );
		}
	}

	/**
	 * Review Focus 1 (plan D25).
	 */
	public function test_a_folder_idle_for_a_day_is_gone_without_a_request(): void {
		$http       = new RecordedHttp();
		$upload     = $this->upload( $http );
		$s          = $this->session( 10 * self::MIB );
		$this->now += NextcloudUpload::EXPIRY + 1;

		foreach ( [ static fn() => $upload->part( $s, 'x', 10 * self::MIB ), static fn() => $upload->finish( $s->with( 25 * self::MIB, $s->extra ) ) ] as $call ) {
			try {
				$call();
				$this->fail( 'Expired.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
			}
		}
		$this->assertSame( [], $http->requests );
	}

	/**
	 * Review Focus 1: the server removed the folder (verified answer: 404 Sabre\DAV\Exception\NotFound).
	 */
	public function test_a_chunk_answered_404_means_the_folder_is_gone(): void {
		try {
			$this->upload( new RecordedHttp( [ RecordedHttp::answer( 404 ) ] ) )->part( $this->session( 10 * self::MIB ), 'x', 10 * self::MIB );
			$this->fail( 'The folder is gone.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
			$this->assertStringNotContainsString( 'lw-migration-', $e->getMessage() );
		}
	}

	public function test_a_move_that_arrived_before_its_request_died_is_accepted(): void {
		$listed = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/alice/b/' . self::NAME . '</d:href><d:propstat><d:prop><d:getcontentlength>' . ( 25 * self::MIB ) . '</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
		$http   = new RecordedHttp( [ RecordedHttp::answer( 404 ), RecordedHttp::answer( 207, $listed ) ] );

		$file = $this->upload( $http )->finish( $this->session( 25 * self::MIB ) );

		$this->assertSame( 25 * self::MIB, $file->size );
		$this->assertSame( 'PROPFIND', $http->requests[1][1]['method'] );
	}

	/**
	 * Final review I2: the assembled file never replaces another one (412 on
	 * a taken name, Nextcloud 35.0.1 verified in CloudProtocolEdgesE2ETest);
	 * one of this backup's size is this backup, and the upload folder goes.
	 */
	public function test_the_move_never_replaces_another_file_under_the_name(): void {
		$listed = static fn( int $size ): string => '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/alice/b/' . self::NAME . '</d:href><d:propstat><d:prop><d:getcontentlength>' . $size . '</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
		$ours   = new RecordedHttp( [ RecordedHttp::answer( 412 ), RecordedHttp::answer( 207, $listed( 25 * self::MIB ) ), RecordedHttp::answer( 204 ) ] );
		$other  = new RecordedHttp( [ RecordedHttp::answer( 412 ), RecordedHttp::answer( 207, $listed( 99 ) ) ] );

		$this->assertSame( 25 * self::MIB, $this->upload( $ours )->finish( $this->session( 25 * self::MIB ) )->size );
		$this->assertSame( 'F', $ours->requests[0][1]['headers']['Overwrite'] );
		$this->assertSame( [ 'MOVE', 'PROPFIND', 'DELETE' ], array_map( static fn( array $r ): string => $r[1]['method'], $ours->requests ) );
		$this->assertStringEndsWith( '/lw-migration-0123456789abcdef', $ours->requests[2][0] );
		try {
			$this->upload( $other )->finish( $this->session( 25 * self::MIB ) );
			$this->fail( 'Another file has the name.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'it was not replaced', $e->getMessage() );
		}
	}

	public function test_a_move_answered_404_without_the_file_in_place_is_gone(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 404 ), RecordedHttp::answer( 404 ) ] );

		try {
			$this->upload( $http )->finish( $this->session( 25 * self::MIB ) );
			$this->fail( 'Gone.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
	}

	public function test_abort_deletes_the_upload_folder_and_a_missing_one_is_fine(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 204 ), RecordedHttp::answer( 404 ) ] );
		$up   = $this->upload( $http );

		$up->abort( $this->session() );
		$up->abort( $this->session() );

		$this->assertSame( [ 'DELETE', 'DELETE' ], array_map( static fn( array $r ): string => $r[1]['method'], $http->requests ) );
		$this->assertSame( 'https://cloud.example/remote.php/dav/uploads/alice/lw-migration-0123456789abcdef', $http->requests[0][0] );
	}

	/**
	 * Preflight P-I8 (H10): a stored session that is not one of ours.
	 *
	 * @return array<string, array{0: \Closure}>
	 */
	public static function foreign(): array {
		$size  = 25 * self::MIB;
		$chunk = 10 * self::MIB;
		$extra = [
			'mode'    => 'nextcloud',
			'touched' => 1760000000,
		];

		return [
			'another type'           => [ static fn(): UploadSession => new UploadSession( 's3', self::NAME, $size, $chunk, $chunk, 'lw-migration-0123456789abcdef', $extra ) ],
			'the plain webdav mode'  => [ static fn(): UploadSession => new UploadSession( 'webdav', self::NAME, $size, $chunk, $chunk, 'lw-migration-0123456789abcdef', [ 'mode' => 'webdav' ] + $extra ) ],
			'a parent folder id'     => [ static fn(): UploadSession => new UploadSession( 'webdav', self::NAME, $size, $chunk, $chunk, '..', $extra ) ],
			'an id of another shape' => [ static fn(): UploadSession => new UploadSession( 'webdav', self::NAME, $size, $chunk, $chunk, 'lw-migration-0123456789ABCDEF', $extra ) ],
			'an id with a path'      => [ static fn(): UploadSession => new UploadSession( 'webdav', self::NAME, $size, $chunk, $chunk, 'lw-migration-0123456789abcdef/../x', $extra ) ],
			'another chunk size'     => [ static fn(): UploadSession => new UploadSession( 'webdav', self::NAME, $size, 5 * self::MIB, 5 * self::MIB, 'lw-migration-0123456789abcdef', $extra ) ],
			'no touch time'          => [ static fn(): UploadSession => new UploadSession( 'webdav', self::NAME, $size, $chunk, $chunk, 'lw-migration-0123456789abcdef', [ 'mode' => 'nextcloud' ] ) ],
			'a touch time as text'   => [ static fn(): UploadSession => new UploadSession( 'webdav', self::NAME, $size, $chunk, $chunk, 'lw-migration-0123456789abcdef', [ 'mode' => 'nextcloud', 'touched' => '1760000000' ] ) ],
			'not a backup name'      => [ static fn(): UploadSession => new UploadSession( 'webdav', 'notes.txt', $size, $chunk, $chunk, 'lw-migration-0123456789abcdef', $extra ) ],
		];
	}

	/**
	 * @param \Closure $make fn(): UploadSession.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreign' )]
	public function test_a_foreign_session_is_gone_before_any_request_and_abort_sends_nothing( \Closure $make ): void {
		$http     = new RecordedHttp();
		$upload   = $this->upload( $http );
		$s        = $make();
		$complete = $s->with( $s->size, $s->extra );

		foreach ( [ static fn() => $upload->part( $s, 'x', $s->nextLength() ), static fn() => $upload->finish( $complete ) ] as $call ) {
			try {
				$call();
				$this->fail( 'Not ours.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
			}
		}
		$upload->abort( $s );
		$this->assertSame( [], $http->requests );
	}

	public function test_the_probe_file_is_an_upload_of_ours(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );
		$s    = new UploadSession( 'webdav', 'lw-migration-test-0123456789abcdef.txt', 40, 0, NextcloudUpload::chunkFor( 40 ), 'lw-migration-0123456789abcdef', [ 'mode' => 'nextcloud', 'touched' => $this->now ] );

		$next = $this->upload( $http )->part( $s, str_repeat( 'x', 40 ), 40 );

		$this->assertTrue( $next->isComplete() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function notOurs(): array {
		return [
			'another type'    => [ 's3', self::NAME ],
			'not a backup'    => [ 'webdav', 'index.php' ],
			'a parent folder' => [ 'webdav', '..' ],
		];
	}

	/**
	 * @param string $type Session type.
	 * @param string $name File name.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'notOurs' )]
	public function test_start_refuses_another_type_or_a_name_that_is_not_a_backup_before_any_request( string $type, string $name ): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );

		try {
			$this->upload( $http )->start( $type, $name, 25 );
			$this->fail( 'Started.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( [], $http->requests );
		}
	}
}

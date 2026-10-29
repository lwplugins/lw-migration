<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\FileSlice;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\NextcloudUpload;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavAddress;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavClient;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavDestination;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class WebDavDestinationTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private const BYTES = 'abcdefghijklmnopqrstuvw';

	private const NC = '{"installed":true,"productname":"Nextcloud","versionstring":"35.0.1"}';

	private const FILES = 'https://cloud.example/remote.php/dav/files/alice/';

	private int $now = 1760000000;

	private string $dir;

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		FakeOptions::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn(): array => [ '93.184.216.34' ] );
		$this->dir = TempDir::create();
		file_put_contents( $this->dir . '/' . self::NAME, self::BYTES );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function dav( RecordedHttp $http, string $url, string $folder, string $mode, bool $cli = false ): WebDavDestination {
		$address = WebDavAddress::fromSettings( $url, $folder );
		$client  = new WebDavClient( 'alice', 'pw', $address->display(), $this->curl->wrap( $http->send() ), fn(): int => $this->now );

		return new WebDavDestination( $client, $address, $mode, static fn(): bool => $cli );
	}

	/**
	 * @return array<int, string> "METHOD url" per request.
	 */
	private static function calls( RecordedHttp $http ): array {
		return array_map( static fn( array $r ): string => $r[1]['method'] . ' ' . $r[0], $http->requests );
	}

	/**
	 * @param string $path Decoded path of the one file listed.
	 * @param int    $size Its bytes.
	 */
	private static function listing( string $path, int $size ): string {
		return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $path . '</d:href><d:propstat><d:prop><d:getcontentlength>' . $size . '</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
	}

	public function test_a_plain_server_gets_a_streamed_part_file_moved_into_place(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ), RecordedHttp::answer( 201 ), RecordedHttp::answer( 201 ) ] );
		$dav  = $this->dav( $http, 'https://dav.example/webdav/', 'b', WebDavDestination::AUTO );
		$path = $this->dir . '/' . self::NAME;

		$s    = $dav->startUpload( self::NAME, 23 );
		$s    = $dav->uploadFrom( $s, $path );
		$file = $dav->finishUpload( $s );

		$this->assertSame( [ 'MKCOL https://dav.example/webdav/b/', 'PUT https://dav.example/webdav/b/' . self::NAME . '.lwm-part', 'MOVE https://dav.example/webdav/b/' . self::NAME . '.lwm-part' ], self::calls( $http ) );
		$this->assertStringEndsNotWith( '.part', $s->remote, 'Nextcloud and ownCloud refuse to store a file of the ".part" type.' );
		$this->assertEquals( new FileSlice( $path, 0, 23 ), $http->requests[1][1][ WebDavClient::BODY_KEY ] );
		$this->assertSame( 'https://dav.example/webdav/b/' . self::NAME, $http->requests[2][1]['headers']['Destination'] );
		$this->assertSame( 'F', $http->requests[2][1]['headers']['Overwrite'] );
		$this->assertSame( [ 'webdav', 23, 23, 'webdav' ], [ $s->type, $s->chunk, $s->offset, $s->extra['mode'] ] );
		$this->assertSame( self::NAME, $file->ref );
	}

	public function test_auto_mode_uses_nextcloud_chunks_when_its_status_page_says_so(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, self::NC ), RecordedHttp::answer( 201 ), RecordedHttp::answer( 201 ) ] );
		$dav  = $this->dav( $http, self::FILES, '', WebDavDestination::AUTO );

		$s = $dav->uploadChunk( $dav->startUpload( self::NAME, 3 ), 'abc' );

		$calls = self::calls( $http );
		$this->assertSame( 'GET https://cloud.example/status.php', $calls[0] );
		$this->assertStringStartsWith( 'MKCOL https://cloud.example/remote.php/dav/uploads/alice/lw-migration-', $calls[1] );
		$this->assertStringEndsWith( '/00001', $calls[2] );
		$this->assertSame( [ 'webdav', 'nextcloud' ], [ $s->type, $s->extra['mode'] ] );
	}

	public function test_nextcloud_mode_chunks_without_asking_the_status_page(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );

		$s = $this->dav( $http, self::FILES, '', WebDavDestination::NEXTCLOUD )->startUpload( self::NAME, 3 );

		$this->assertStringStartsWith( 'MKCOL https://cloud.example/remote.php/dav/uploads/alice/', self::calls( $http )[0] );
		$this->assertSame( 'nextcloud', $s->extra['mode'] );
	}

	/**
	 * Review Focus 5 (plan D26): refused before anything is sent.
	 */
	public function test_a_large_single_put_is_refused_outside_the_command_line(): void {
		$http = new RecordedHttp();

		try {
			$this->dav( $http, 'https://dav.example/webdav/', '', WebDavDestination::PLAIN )->startUpload( self::NAME, WebDavDestination::SINGLE_PUT + 1 );
			$this->fail( 'Too large for one web request.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::TOO_LARGE, $e->reason );
			$this->assertStringContainsString( 'wp lw-migration upload', $e->getMessage() );
		}
		$this->assertSame( [], $http->requests );

		$cli = new RecordedHttp();
		$s   = $this->dav( $cli, 'https://dav.example/webdav/', '', WebDavDestination::PLAIN, true )->startUpload( self::NAME, WebDavDestination::SINGLE_PUT + 1 );
		$this->assertSame( WebDavDestination::SINGLE_PUT + 1, $s->chunk, 'From the command line one streamed PUT carries any size.' );
	}

	public function test_the_single_request_limit_is_a_filter(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => 'lw_migration_webdav_single_put_bytes' === $hook ? 10 : $value );

		$this->assertSame( 10, WebDavDestination::singlePut() );
		$this->expectException( PermanentFailure::class );
		$this->dav( new RecordedHttp(), 'https://dav.example/', '', WebDavDestination::PLAIN )->startUpload( self::NAME, 23 );
	}

	public function test_only_backups_and_the_test_file_are_uploaded(): void {
		$http = new RecordedHttp();

		$this->expectException( \LogicException::class );
		$this->dav( $http, 'https://dav.example/', '', WebDavDestination::PLAIN )->startUpload( '../index.php', 3 );
	}

	public function test_lists_reads_ranges_deletes_and_aborts(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207, self::listing( '/webdav/' . self::NAME, 23 ) ), RecordedHttp::answer( 206, 'cde', [ 'Content-Range' => 'bytes 2-4/23' ] ), RecordedHttp::answer( 404 ), RecordedHttp::answer( 204 ) ] );
		$dav  = $this->dav( $http, 'https://dav.example/webdav/', '', WebDavDestination::PLAIN );

		$files = $dav->list();
		$this->assertSame( [ self::NAME ], array_map( static fn( RemoteFile $f ): string => $f->name, $files ) );
		$this->assertSame( 'cde', $dav->readRange( $files[0], 2, 3 ) );
		$this->assertSame( 'bytes=2-4', $http->requests[1][1]['headers']['Range'] );
		$this->assertSame( 3, $http->requests[1][1]['limit_response_size'] );
		$dav->delete( $files[0] ); // Already gone: no failure.
		$dav->abort( new UploadSession( 'webdav', self::NAME, 23, 0, 23, self::NAME . '.lwm-part', [ 'mode' => 'webdav' ] ) );
		$this->assertSame( 'DELETE https://dav.example/webdav/' . self::NAME, self::calls( $http )[2] );
		$this->assertSame( 'DELETE https://dav.example/webdav/' . self::NAME . '.lwm-part', self::calls( $http )[3] );
	}

	public function test_a_range_answered_whole_short_or_from_elsewhere_is_refused(): void {
		$file = new RemoteFile( self::NAME, 23, 1, self::NAME );

		try {
			$this->dav( new RecordedHttp( [ RecordedHttp::answer( 200, self::BYTES ) ] ), 'https://dav.example/', '', WebDavDestination::PLAIN )->readRange( $file, 2, 3 );
			$this->fail( 'No ranges.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		try {
			$this->dav( new RecordedHttp( [ RecordedHttp::answer( 206, 'abc', [ 'Content-Range' => 'bytes 0-2/23' ] ) ] ), 'https://dav.example/', '', WebDavDestination::PLAIN )->readRange( $file, 2, 3 );
			$this->fail( 'Another part.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->expectException( TransientFailure::class );
		$this->dav( new RecordedHttp( [ RecordedHttp::answer( 206, 'c' ) ] ), 'https://dav.example/', '', WebDavDestination::PLAIN )->readRange( $file, 2, 3 );
	}

	/**
	 * @return array<string, array{0: RemoteFile}>
	 */
	public static function strangers(): array {
		return [
			'parent folder'        => [ new RemoteFile( '..', 1, 1, '..' ) ],
			'not a backup'         => [ new RemoteFile( 'wp-config.php', 1, 1, 'wp-config.php' ) ],
			'a part file'          => [ new RemoteFile( self::NAME . '.lwm-part', 1, 1, self::NAME . '.lwm-part' ) ],
			'reference elsewhere'  => [ new RemoteFile( self::NAME, 1, 1, '../' . self::NAME ) ],
			'name with a sub path' => [ new RemoteFile( 'x/' . self::NAME, 1, 1, 'x/' . self::NAME ) ],
		];
	}

	/**
	 * Preflight P-I8 (H10): nothing is read or deleted outside the backups and the test file.
	 *
	 * @param RemoteFile $file Not one of the destination's files.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'strangers' )]
	public function test_delete_and_read_refuse_a_file_that_is_not_a_backup_of_the_folder( RemoteFile $file ): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 204 ), RecordedHttp::answer( 206, 'a' ) ] );
		$dav  = $this->dav( $http, 'https://dav.example/webdav/', '', WebDavDestination::PLAIN );

		foreach ( [ static fn() => $dav->delete( $file ), static fn() => $dav->readRange( $file, 0, 1 ) ] as $act ) {
			try {
				$act();
				$this->fail( 'Acted on ' . $file->ref );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason );
			}
		}
		$this->assertSame( [], $http->requests );
	}

	/**
	 * @return array<string, array{0: UploadSession}>
	 */
	public static function foreignSessions(): array {
		$part = self::NAME . '.lwm-part';

		return [
			'another type'          => [ new UploadSession( 's3', self::NAME, 23, 0, 23, $part, [ 'mode' => 'webdav' ] ) ],
			'no mode'               => [ new UploadSession( 'webdav', self::NAME, 23, 0, 23, $part, [] ) ],
			'another mode'          => [ new UploadSession( 'webdav', self::NAME, 23, 0, 23, $part, [ 'mode' => 'tus' ] ) ],
			'remote is the parent'  => [ new UploadSession( 'webdav', self::NAME, 23, 0, 23, '..', [ 'mode' => 'webdav' ] ) ],
			'remote is the backup'  => [ new UploadSession( 'webdav', self::NAME, 23, 0, 23, self::NAME, [ 'mode' => 'webdav' ] ) ],
			'an older ".part" part' => [ new UploadSession( 'webdav', self::NAME, 23, 0, 23, self::NAME . '.part', [ 'mode' => 'webdav' ] ) ],
			'chunk is not the size' => [ new UploadSession( 'webdav', self::NAME, 23, 0, 5, $part, [ 'mode' => 'webdav' ] ) ],
			'not a backup name'     => [ new UploadSession( 'webdav', 'index.php', 23, 0, 23, 'index.php.lwm-part', [ 'mode' => 'webdav' ] ) ],
			'nextcloud on a plain address' => [ new UploadSession( 'webdav', self::NAME, 23, 0, NextcloudUpload::chunkFor( 23 ), 'lw-migration-0123456789abcdef', [ 'mode' => 'nextcloud', 'touched' => 1760000000 ] ) ],
		];
	}

	/**
	 * Preflight P-I8 (H10): a stored session that is not one of ours starts
	 * over before any request; aborting it sends nothing.
	 *
	 * @param UploadSession $s Foreign session.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreignSessions' )]
	public function test_a_foreign_session_is_gone_before_any_request_and_abort_sends_nothing( UploadSession $s ): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ), RecordedHttp::answer( 201 ) ] );
		$dav  = $this->dav( $http, 'https://dav.example/webdav/', '', WebDavDestination::PLAIN );
		$done = new UploadSession( $s->type, $s->name, $s->size, $s->size, $s->chunk, $s->remote, $s->extra );
		$acts = [
			fn() => $dav->uploadFrom( $s, $this->dir . '/' . self::NAME ),
			static fn() => $dav->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) ),
			static fn() => $dav->finishUpload( $done ),
		];

		foreach ( $acts as $act ) {
			try {
				$act();
				$this->fail( 'Sent.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
			}
		}
		$dav->abort( $s );
		$this->assertSame( [], $http->requests );
	}

	/**
	 * Preflight P-I8: a plain session halfway would PUT only the tail over the part file.
	 */
	public function test_a_plain_session_halfway_is_gone_before_any_request(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );
		$s    = new UploadSession( 'webdav', self::NAME, 23, 5, 23, self::NAME . '.lwm-part', [ 'mode' => 'webdav' ] );

		try {
			$this->dav( $http, 'https://dav.example/', '', WebDavDestination::PLAIN )->uploadFrom( $s, $this->dir . '/' . self::NAME );
			$this->fail( 'Sent.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_finish_accepts_a_move_that_arrived_before_its_request_died(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 404 ), RecordedHttp::answer( 207, self::listing( '/' . self::NAME, 23 ) ) ] );
		$s    = new UploadSession( 'webdav', self::NAME, 23, 23, 23, self::NAME . '.lwm-part', [ 'mode' => 'webdav' ] );

		$this->assertSame( 23, $this->dav( $http, 'https://dav.example/', '', WebDavDestination::PLAIN )->finishUpload( $s )->size );
	}

	public function test_a_move_answered_404_without_the_file_in_place_fails(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 404 ), RecordedHttp::answer( 404 ) ] );
		$s    = new UploadSession( 'webdav', self::NAME, 23, 23, 23, self::NAME . '.lwm-part', [ 'mode' => 'webdav' ] );

		$this->expectException( PermanentFailure::class );
		$this->dav( $http, 'https://dav.example/', '', WebDavDestination::PLAIN )->finishUpload( $s );
	}

	public function test_the_connection_test_writes_lists_reads_and_deletes_the_test_file(): void {
		$name = '';
		$http = new RecordedHttp(
			[
				RecordedHttp::answer( 201 ),
				function ( string $url ) use ( &$name ): array {
					$name = basename( $url, '.lwm-part' );
					return RecordedHttp::answer( 201 );
				},
				RecordedHttp::answer( 201 ),
				static function () use ( &$name ): array {
					return RecordedHttp::answer( 207, self::listing( '/b/' . $name, strlen( 'LW Migration connection test ' . $name . "\n" ) ) );
				},
				static function () use ( &$name ): array {
					return RecordedHttp::answer( 207, self::listing( '/b/' . $name, strlen( 'LW Migration connection test ' . $name . "\n" ) ) );
				},
				static function ( string $url, array $args ) use ( &$name ): array {
					$body = 'LW Migration connection test ' . $name . "\n";
					self::assertSame( 'bytes=1-' . ( strlen( $body ) - 1 ), $args['headers']['Range'], 'Read back from inside the file.' );
					return RecordedHttp::answer( 206, substr( $body, 1 ), [ 'Content-Range' => 'bytes 1-' . ( strlen( $body ) - 1 ) . '/' . strlen( $body ) ] );
				},
				RecordedHttp::answer( 204 ),
			]
		);

		$this->dav( $http, 'https://dav.example/', 'b', WebDavDestination::PLAIN )->test();

		$this->assertSame( [ 'MKCOL', 'PUT', 'MOVE', 'PROPFIND', 'PROPFIND', 'GET', 'DELETE' ], array_map( static fn( array $r ): string => $r[1]['method'], $http->requests ) );
		$this->assertSame( [ '1', '0' ], [ $http->requests[3][1]['headers']['Depth'], $http->requests[4][1]['headers']['Depth'] ] );
		$this->assertSame( 'DELETE https://dav.example/b/' . $name, self::calls( $http )[6] );
	}

	/**
	 * Task 4 m2: a test file the folder listing does not show (the address
	 * points elsewhere than the listing looks) fails the test with our own
	 * text instead of passing and never listing or pruning a backup later.
	 */
	public function test_the_connection_test_fails_when_the_folder_listing_does_not_show_the_test_file(): void {
		$empty = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"></d:multistatus>';
		$http  = new RecordedHttp( [ RecordedHttp::answer( 201 ), RecordedHttp::answer( 201 ), RecordedHttp::answer( 207, $empty ), RecordedHttp::answer( 204 ), RecordedHttp::answer( 404 ), RecordedHttp::answer( 207, $empty ) ] );

		try {
			$this->dav( $http, 'https://dav.example/', '', WebDavDestination::PLAIN )->test();
			$this->fail( 'Passed.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'https://dav.example', $e->getMessage() );
		}
		$calls = self::calls( $http );
		$this->assertMatchesRegularExpression( '#^DELETE https://dav\.example/lw-migration-test-[0-9a-f]{16}\.txt$#', $calls[3], 'The moved test file is removed.' );
	}

	/**
	 * Review Focus 1 through the real upload step (plan D25): the upload
	 * folder outlived Nextcloud's day; the step starts once more in a new folder.
	 */
	public function test_an_expired_nextcloud_upload_starts_over_in_a_new_folder_through_the_upload_step(): void {
		$http                           = new RecordedHttp(
			[
				RecordedHttp::answer( 200, self::NC ),
				RecordedHttp::answer( 201 ), // MKCOL folder 1
				RecordedHttp::answer( 201 ), // PUT 00001
				RecordedHttp::answer( 200, self::NC ),
				RecordedHttp::answer( 201 ), // MKCOL folder 2
				RecordedHttp::answer( 201 ), // PUT 00001
				RecordedHttp::answer( 201 ), // MOVE .file
			]
		);
		$d                              = FakeCloud::install( [ 'aaaa1111' => 'Cloud' ] );
		FakeCloud::$remotes['aaaa1111'] = $this->dav( $http, self::FILES, '', WebDavDestination::AUTO );
		$store                          = new JobStore( $this->dir . '/jobs' );
		$job                            = $store->create( 'export', [ CloudUploadStep::KEY ], [], 1 );
		$job->state['backup']           = [
			'name' => self::NAME,
			'path' => $this->dir . '/' . self::NAME,
			'size' => 23,
		];
		$job->state['cloud']            = CloudPlan::manual( [ 'aaaa1111' ], true )->toArray();
		$step                           = new CloudUploadStep( $d, fn(): int => $this->now, static function (): void {} );

		$step->run( $job, new NoDeadline() ); // start (status, MKCOL)
		$step->run( $job, new NoDeadline() ); // the one chunk
		$this->now += 25 * 3600;               // Nobody came back for a day.
		for ( $i = 0; $i < 10 && ! $step->run( $job, new NoDeadline() )->isDone(); $i++ ) {
			$store->save( $job );
		}

		$calls = self::calls( $http );
		$this->assertCount( 7, $calls );
		$this->assertNotSame( substr( $calls[1], 6 ), substr( $calls[4], 6 ), 'A new upload folder.' );
		$this->assertStringStartsWith( 'MOVE ' . substr( $calls[4], 6 ), $calls[6] );
		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\GoogleDrive;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\GoogleDrive\DriveErrors;
use LightweightPlugins\Migration\Storage\Destinations\GoogleDrive\DriveUpload;
use LightweightPlugins\Migration\Storage\Destinations\GoogleDrive\GoogleDriveDestination;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ChunkSizer;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Plan Task 8 (spec 21.5/21.10, E9–E12, E19, E23) with preflight P-C2,
 * P-I2–P-I6, P-M2, P-M3, P-M20 and M7b's never-overwrite rule (H13).
 */
final class GoogleDriveDestinationTest extends MonkeyTestCase {

	private const KIB = 262144;

	private const NAME = 'site-20261012-030000-123.wpress';

	private const SESSION = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=xa298sd';

	/** No file of the backup's name in the folder yet. */
	private const NONE = '{"files":[]}';

	/** @var array<int, string> */
	private array $folders = [];

	private static ?CurlHooks $curl = null;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		RecordedHttp::install();
		self::$curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => [ '142.250.1.95' ] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/**
	 * Tokens valid for an hour: no token request in these tests. Needs
	 * FakeWpdb, RecordedHttp::install() and the test's installed CurlHooks.
	 */
	public static function http( RecordedHttp $recorded, ?CurlHooks $curl = null ): OAuthHttp {
		$vault = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$vault->put( 'abcd1234', [ 'refreshToken' => 'r', 'accessToken' => '1900000000:ya29', TokenKeeper::GRANT => 'g1' ] );
		$endpoint = new class() implements TokenEndpoint {
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
		};
		$keeper = new TokenKeeper( 'abcd1234', new OAuthApp( Provider::google(), 'c', 's' ), (array) $vault->open( 'abcd1234' ), $endpoint, $vault, static fn(): int => 1_800_000_000 );
		$curl   = $curl ?? self::$curl ?? ( new CurlHooks() )->install();

		return new OAuthHttp( $keeper, $curl->wrap( $recorded->send() ), static fn(): int => 1_800_000_000 );
	}

	private function drive( RecordedHttp $recorded, string $folder = 'folder-1', ?int $memory = null ): GoogleDriveDestination {
		return new GoogleDriveDestination(
			self::http( $recorded ),
			$folder,
			function ( string $id ): void {
				$this->folders[] = $id;
			},
			new ChunkSizer( self::KIB, 4 * self::KIB, self::KIB, static fn(): ?int => $memory, static fn(): float => 0.0 ),
			'wphu-org'
		);
	}

	private static function session( int $size, int $offset = 0, int $chunk = self::KIB ): UploadSession {
		return new UploadSession( 'gdrive', self::NAME, $size, $offset, $chunk, self::SESSION, [ 'fileId' => '' ] );
	}

	/**
	 * @return string "<class> <reason>" of the failure, or "none".
	 */
	private static function failure( \Closure $call ): string {
		try {
			$call();
		} catch ( CloudFailure $e ) {
			return ( $e instanceof PermanentFailure ? 'permanent' : 'transient' ) . ' ' . $e->reason;
		}
		return 'none';
	}

	public function test_start_looks_for_the_name_then_posts_metadata_into_the_folder_and_keeps_the_session_address(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, self::NONE ), RecordedHttp::answer( 200, '', [ 'Location' => self::SESSION ] ) ] );

		$s = $this->drive( $recorded )->startUpload( self::NAME, 600000 );

		$this->assertStringContainsString( "name = '" . self::NAME . "' and 'folder-1' in parents and trashed = false", rawurldecode( $recorded->requests[0][0] ) );
		[ $url, $args ] = $recorded->requests[1];
		$this->assertSame( GoogleDriveDestination::UPLOAD, $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 'Bearer ya29', $args['headers']['Authorization'] );
		$this->assertSame( '600000', $args['headers']['X-Upload-Content-Length'] );
		$this->assertStringContainsString( 'files(id,size,appProperties)', rawurldecode( $recorded->requests[0][0] ) );
		$this->assertSame(
			[
				'name'          => self::NAME,
				'parents'       => [ 'folder-1' ],
				'mimeType'      => 'application/octet-stream',
				'appProperties' => [ DriveUpload::MARK => DriveUpload::marker( self::NAME, 600000 ) ],
			],
			json_decode( $args['body'], true )
		);
		$this->assertSame( self::SESSION, $s->remote );
		$this->assertSame( [ 'fileId' => '' ], $s->extra );
		$this->assertSame( self::KIB, $s->chunk );
		$this->assertGreaterThan( 0, self::$curl->seen, 'Checked and pinned.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function foreignSessionAddresses(): array {
		return [
			'another host'      => [ 'https://evil.example/upload/drive/v3/files?upload_id=x' ],
			'no upload id'      => [ 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable' ],
			'another path'      => [ 'https://www.googleapis.com/drive/v3/files?upload_id=x' ],
			'plain http'        => [ 'http://www.googleapis.com/upload/drive/v3/files?upload_id=x' ],
			'none'              => [ '' ],
			'a user in the URL' => [ 'https://a@www.googleapis.com/upload/drive/v3/files?upload_id=x' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreignSessionAddresses' )]
	public function test_a_session_address_that_is_not_googles_upload_is_refused( string $location ): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, self::NONE ), RecordedHttp::answer( 200, '', [ 'Location' => $location ] ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->drive( $recorded )->startUpload( self::NAME, 10 ) ) );
	}

	public function test_a_gone_folder_is_found_or_created_once_and_remembered(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, self::NONE ),
				RecordedHttp::answer( 404, '{"error":{"errors":[{"reason":"notFound"}],"code":404}}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ),
				RecordedHttp::answer( 200, '{"files":[]}' ),
				RecordedHttp::answer( 200, '{"id":"folder-2"}' ),
				RecordedHttp::answer( 200, self::NONE ),
				RecordedHttp::answer( 200, '', [ 'Location' => self::SESSION ] ),
			]
		);

		$this->drive( $recorded )->startUpload( self::NAME, 10 );

		$this->assertSame( [ 'folder-2' ], $this->folders );
		$this->assertSame( [ 'folder-2' ], json_decode( $recorded->requests[6][1]['body'], true )['parents'] );
		$parent = rawurldecode( $recorded->requests[2][0] );
		$find   = rawurldecode( $recorded->requests[3][0] );
		$this->assertStringContainsString( "appProperties has { key='lwMigration' and value='backups' }", $parent );
		$this->assertStringContainsString( 'orderBy=createdTime', $parent, 'Two folders made by a race: every process takes the oldest (P-M20).' );
		$this->assertStringContainsString( "'parent-1' in parents and appProperties has { key='lwMigrationSite' and value='wphu-org' }", $find );
		$this->assertStringContainsString( 'orderBy=createdTime', $find );
		$this->assertSame(
			[
				'name'          => 'wphu-org',
				'mimeType'      => 'application/vnd.google-apps.folder',
				'parents'       => [ 'parent-1' ],
				'appProperties' => [ 'lwMigration' => 'site', 'lwMigrationSite' => 'wphu-org' ],
			],
			json_decode( $recorded->requests[4][1]['body'], true )
		);
		$this->assertStringContainsString( "'folder-2' in parents", rawurldecode( $recorded->requests[5][0] ) );
	}

	public function test_a_folder_gone_twice_stops_the_upload(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 404 ),
				RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"folder-2"}]}' ),
				RecordedHttp::answer( 404 ),
			]
		);

		$this->assertSame( 'permanent ' . PermanentFailure::MISSING, self::failure( fn() => $this->drive( $recorded )->startUpload( self::NAME, 10 ) ) );
		$this->assertCount( 4, $recorded->requests );
	}

	public function test_without_a_folder_id_the_folder_is_found_first(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"folder-7"}]}' ),
				RecordedHttp::answer( 200, self::NONE ),
				RecordedHttp::answer( 200, '', [ 'Location' => self::SESSION ] ),
			]
		);

		$this->drive( $recorded, '' )->startUpload( self::NAME, 10 );

		$this->assertSame( [ 'folder-7' ], $this->folders );
	}

	public function test_without_a_site_the_run_time_slug_is_the_folder(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://Run-Time.example.com' );
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"files":[{"id":"parent-1"}]}' ), RecordedHttp::answer( 200, '{"files":[{"id":"folder-7"}]}' ), RecordedHttp::answer( 200, self::NONE ), RecordedHttp::answer( 200, '', [ 'Location' => self::SESSION ] ) ] );
		$drive    = new GoogleDriveDestination( self::http( $recorded ), '', static function ( string $id ): void {} );

		$drive->startUpload( self::NAME, 10 );

		$this->assertStringContainsString( "value='run-time-example-com'", rawurldecode( $recorded->requests[1][0] ) );
	}

	/**
	 * H13: Drive keeps two files of one name side by side, so the upload
	 * never creates a second one: a file of the name with the backup's size
	 * is this backup (an upload whose final answer got lost before the
	 * session was started over) and is kept without a request more; one of
	 * another size is refused.
	 */
	public function test_a_file_of_the_same_name_is_taken_only_with_the_backups_size(): void {
		$mark     = '"appProperties":{"' . DriveUpload::MARK . '":"' . DriveUpload::marker( self::NAME, 600000 ) . '"}';
		$same     = new RecordedHttp( [ RecordedHttp::answer( 200, '{"files":[{"id":"file-3","name":"' . self::NAME . '","size":"600000",' . $mark . '}]}' ) ] );
		$drive    = $this->drive( $same );
		$s        = $drive->startUpload( self::NAME, 600000 );
		$file     = $drive->finishUpload( $s );
		$other    = new RecordedHttp( [ RecordedHttp::answer( 200, '{"files":[{"id":"file-3","name":"' . self::NAME . '","size":"5",' . $mark . '}]}' ) ] );
		$refusal  = self::failure( fn() => $this->drive( $other )->startUpload( self::NAME, 600000 ) );

		$this->assertTrue( $s->isComplete() );
		$this->assertSame( 'file-3', $file->ref );
		$this->assertCount( 1, $same->requests, 'No upload session, no second file.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, $refusal );
		$this->assertCount( 1, $other->requests );
	}

	/**
	 * Task 8 m1: the shortcut binds to this backup — the file must carry the
	 * marker this site's initiate wrote for this name and size; a file of the
	 * name and size without it (or with another backup's marker) is refused.
	 */
	public function test_a_file_of_the_same_name_and_size_without_this_backups_marker_is_refused(): void {
		$bodies = [
			'{"files":[{"id":"file-3","size":"600000"}]}',
			'{"files":[{"id":"file-3","size":"600000","appProperties":{"' . DriveUpload::MARK . '":"' . DriveUpload::marker( self::NAME, 600001 ) . '"}}]}',
			'{"files":[{"id":"file-3","size":"600000","appProperties":"x"}]}',
		];
		foreach ( $bodies as $body ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, $body ) ] );
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->drive( $recorded )->startUpload( self::NAME, 600000 ) ), $body );
			$this->assertCount( 1, $recorded->requests );
		}
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\z/', DriveUpload::marker( self::NAME, 600000 ) );
		$this->assertNotSame( DriveUpload::marker( self::NAME, 600000 ), DriveUpload::marker( 'other-20261012-030000-123.wpress', 600000 ) );
	}

	/**
	 * Task 8 m6: a quote in a backup name is escaped in the name search; a
	 * backslash never reaches it (not a backup name).
	 */
	public function test_a_quote_in_the_name_is_escaped_in_the_search_and_a_backslash_is_never_sent(): void {
		$name     = "o'brien-20261012-030000-123.wpress";
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, self::NONE ), RecordedHttp::answer( 200, '', [ 'Location' => self::SESSION ] ) ] );

		$this->drive( $recorded )->startUpload( $name, 10 );

		$this->assertStringContainsString( "name = 'o\\'brien-20261012-030000-123.wpress' and", rawurldecode( $recorded->requests[0][0] ) );
		$none = new RecordedHttp();
		try {
			$this->drive( $none )->startUpload( 'a\\b-20261012-030000-123.wpress', 10 );
			$this->fail( 'Not a backup name.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( [], $none->requests );
		}
	}

	public function test_only_backups_and_the_test_file_are_uploaded(): void {
		$this->expectException( \LogicException::class );

		$this->drive( new RecordedHttp() )->startUpload( 'notes.txt', 10 );
	}

	public function test_a_308_moves_the_offset_by_the_range_header_and_the_put_carries_no_authorization(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 308, '', [ 'Range' => 'bytes=0-262143' ] ) ] );

		$s = $this->drive( $recorded )->uploadChunk( self::session( 600000 ), str_repeat( 'a', self::KIB ) );

		[ $url, $args ] = $recorded->requests[0];
		$this->assertSame( self::SESSION, $url );
		$this->assertSame( 'PUT', $args['method'] );
		$this->assertSame( 'bytes 0-262143/600000', $args['headers']['Content-Range'] );
		$this->assertSame( (string) self::KIB, $args['headers']['Content-Length'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertGreaterThanOrEqual( 120, $args['timeout'] );
		$this->assertSame( self::KIB, $s->offset );
		$this->assertSame( 2 * self::KIB, $s->chunk, 'A fast chunk doubles the next one, still a 256 KiB multiple.' );
	}

	public function test_a_308_that_keeps_less_than_the_chunk_resends_the_rest(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 308, '', [ 'Range' => 'bytes=0-299999' ] ) ] );

		$s = $this->drive( $recorded )->uploadChunk( self::session( 600000, self::KIB ), str_repeat( 'a', self::KIB ) );

		$this->assertSame( 300000, $s->offset, 'Do not assume the server received every byte sent.' );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function badRanges(): array {
		return [
			'below what it had confirmed' => [ [ 'Range' => 'bytes=0-1000' ] ],
			'none after confirmed bytes'  => [ [] ],
			'past what was sent'          => [ [ 'Range' => 'bytes=0-599999' ] ],
			'not from byte 0'             => [ [ 'Range' => 'bytes=5-524287' ] ],
			'not a range'                 => [ [ 'Range' => 'all of it' ] ],
		];
	}

	/**
	 * @param array<string, string> $headers The 308's headers.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'badRanges' )]
	public function test_a_308_outside_the_chunk_just_sent_starts_the_upload_over( array $headers ): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 308, '', $headers ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->drive( $recorded )->uploadChunk( self::session( 600000, self::KIB ), str_repeat( 'a', self::KIB ) ) ) );
	}

	public function test_status_query_adopts_the_servers_range(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 308, '', [ 'Range' => 'bytes=0-42' ] ), RecordedHttp::answer( 308 ) ] );
		$drive    = $this->drive( $recorded );

		$this->assertSame( 43, $drive->resume( self::session( 600000, self::KIB ) )->offset, 'The server has less than the job thought.' );
		$args = $recorded->requests[0][1];
		$this->assertSame( 'PUT', $args['method'] );
		$this->assertSame( 'bytes */600000', $args['headers']['Content-Range'] );
		$this->assertSame( '0', $args['headers']['Content-Length'] );
		$this->assertSame( '', $args['body'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertSame( 0, $drive->resume( self::session( 600000, self::KIB ) )->offset, 'No Range header: nothing received.' );
	}

	public function test_a_status_answer_past_the_part_in_flight_starts_over(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 308, '', [ 'Range' => 'bytes=0-524288' ] ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->drive( $recorded )->resume( self::session( 600000, self::KIB ) ) ) );
	}

	public function test_the_last_chunk_returns_the_file_id_and_finish_needs_no_request(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"id":"file-9","name":"' . self::NAME . '"}' ) ] );
		$drive    = $this->drive( $recorded );

		$s    = $drive->uploadChunk( self::session( 600000, 2 * self::KIB, 4 * self::KIB ), str_repeat( 'b', 600000 - 2 * self::KIB ) );
		$file = $drive->finishUpload( $s );

		$this->assertTrue( $s->isComplete() );
		$this->assertSame( 'bytes 524288-599999/600000', $recorded->requests[0][1]['headers']['Content-Range'] );
		$this->assertSame( 'file-9', $file->ref );
		$this->assertSame( 600000, $file->size );
		$this->assertSame( self::NAME, $file->name );
		$this->assertCount( 1, $recorded->requests );
	}

	public function test_a_finished_answer_to_a_chunk_that_was_not_the_last_is_refused(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"id":"file-9"}' ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->drive( $recorded )->uploadChunk( self::session( 600000 ), str_repeat( 'a', self::KIB ) ) ) );
	}

	public function test_a_lost_final_answer_is_found_by_the_status_query(): void {
		$s = $this->drive( new RecordedHttp( [ RecordedHttp::answer( 201, '{"id":"file-9"}' ) ] ) )->resume( self::session( 600000, 2 * self::KIB ) );

		$this->assertTrue( $s->isComplete() );
		$this->assertSame( 'file-9', $s->extra['fileId'] );
		$this->assertSame( self::KIB, $s->chunk, 'The status query keeps the chunk (ResumeCheck::fits()).' );
	}

	public function test_finish_without_a_file_id_asks_the_session_once(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 404 ) ] );
		$complete = new UploadSession( 'gdrive', self::NAME, 10, 10, self::KIB, self::SESSION, [ 'fileId' => '' ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->drive( $recorded )->finishUpload( $complete ) ) );
		$this->assertCount( 1, $recorded->requests );
	}

	public function test_a_gone_session_is_session_gone(): void {
		foreach ( [ 'chunk', 'resume' ] as $how ) {
			$drive = $this->drive( new RecordedHttp( [ RecordedHttp::answer( 404 ) ] ) );
			try {
				'chunk' === $how ? $drive->uploadChunk( self::session( 600000 ), str_repeat( 'a', self::KIB ) ) : $drive->resume( self::session( 600000 ) );
				$this->fail( 'Gone.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason, $how );
				$this->assertStringNotContainsString( 'upload_id', $e->getMessage() );
			}
		}
	}

	/**
	 * P-I2: the session address is sent without Authorization, so a 401 or
	 * 403 there is not a withdrawn grant (never REAUTH, never the reconnect
	 * mail) — the session is gone; a known reason still counts.
	 */
	public function test_a_refusal_of_the_session_address_is_never_reauth(): void {
		foreach ( [ 401, 403, 410 ] as $status ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( $status, '{"error":{"code":' . $status . '}}' ) ] );
			$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->drive( $recorded )->uploadChunk( self::session( 600000 ), str_repeat( 'a', self::KIB ) ) ), (string) $status );
		}
		$full = new RecordedHttp( [ RecordedHttp::answer( 403, '{"error":{"errors":[{"reason":"storageQuotaExceeded"}],"code":403}}' ) ] );
		$this->assertSame( 'permanent ' . PermanentFailure::FULL, self::failure( fn() => $this->drive( $full )->resume( self::session( 600000, self::KIB ) ) ) );
		$busy = new RecordedHttp( [ RecordedHttp::answer( 503 ) ] );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, self::failure( fn() => $this->drive( $busy )->resume( self::session( 600000, self::KIB ) ) ) );
	}

	/**
	 * Task 8 m6: a rate limit on the session address passes (the reason
	 * counts before the unauthorized-status rule).
	 */
	public function test_a_rate_limit_on_the_session_address_is_transient(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 403, '{"error":{"errors":[{"reason":"rateLimitExceeded"}],"code":403}}', [ 'Retry-After' => '20' ] ) ] );

		$this->assertSame( 'transient ' . TransientFailure::BUSY, self::failure( fn() => $this->drive( $recorded )->uploadChunk( self::session( 600000 ), str_repeat( 'a', self::KIB ) ) ) );
	}

	/**
	 * Task 8 m4: a session stored by a process with more free memory sends
	 * no more than this process's cap; the rest follows in the next chunk.
	 */
	public function test_a_resumed_chunk_above_the_memory_cap_sends_only_the_cap(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 308, '', [ 'Range' => 'bytes=0-' . ( 2 * self::KIB - 1 ) ] ) ] );
		$drive    = $this->drive( $recorded, 'folder-1', 4 * self::KIB );

		$s = $drive->uploadChunk( self::session( 600000, self::KIB, 4 * self::KIB ), str_repeat( 'a', 600000 - self::KIB ) );

		$this->assertSame( 'bytes 262144-524287/600000', $recorded->requests[0][1]['headers']['Content-Range'] );
		$this->assertSame( self::KIB, strlen( $recorded->requests[0][1]['body'] ) );
		$this->assertSame( 2 * self::KIB, $s->offset );
		$this->assertSame( self::KIB, $s->chunk );
	}

	public function test_a_401_is_reauth_only_on_an_authorized_request(): void {
		$this->assertSame( PermanentFailure::REAUTH, DriveErrors::classify( 401, '', '', 0 )->reason );
		$this->assertSame( PermanentFailure::SESSION_GONE, DriveErrors::classify( 401, '', '', 0, false )->reason );
		$this->assertSame( PermanentFailure::DENIED, DriveErrors::classify( 403, '{"error":{"errors":[{"reason":"forbidden"}]}}', '', 0 )->reason );
		$this->assertSame( 30, DriveErrors::classify( 403, '{"error":{"errors":[{"reason":"rateLimitExceeded"}]}}', '30', 0 )->retryAfter );
	}

	public function test_a_chunk_of_the_wrong_length_is_refused_before_sending(): void {
		$recorded = new RecordedHttp();
		foreach ( [ 1000, 0 ] as $length ) {
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->drive( $recorded )->uploadChunk( self::session( 600000 ), str_repeat( 'a', $length ) ) ) );
		}
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->drive( $recorded )->uploadChunk( self::session( 10, 10 ), '' ) ), 'A complete session takes no chunk.' );
		$this->assertSame( [], $recorded->requests );
	}

	/**
	 * @return array<string, array{0: UploadSession}>
	 */
	public static function foreignSessions(): array {
		$good = self::session( 600000, self::KIB );

		return [
			'another type'          => [ new UploadSession( 's3', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, $good->extra ) ],
			'another host'          => [ new UploadSession( 'gdrive', $good->name, $good->size, $good->offset, $good->chunk, 'https://evil.example/upload/drive/v3/files?upload_id=x', $good->extra ) ],
			'not a multiple'        => [ new UploadSession( 'gdrive', $good->name, $good->size, $good->offset, 1000, $good->remote, $good->extra ) ],
			'over the largest part' => [ new UploadSession( 'gdrive', $good->name, 900000000, 0, 128 * 1048576, $good->remote, $good->extra ) ],
			'other extras'          => [ new UploadSession( 'gdrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, [ 'fileId' => '', 'key' => 'x' ] ) ],
			'a bad file id'         => [ new UploadSession( 'gdrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, [ 'fileId' => '../x' ] ) ],
			'not a backup name'     => [ new UploadSession( 'gdrive', 'notes.txt', $good->size, $good->offset, $good->chunk, $good->remote, $good->extra ) ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreignSessions' )]
	public function test_a_foreign_stored_session_starts_over_before_any_request( UploadSession $s ): void {
		$recorded = new RecordedHttp();
		$drive    = $this->drive( $recorded );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $drive->resume( $s ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $drive->uploadChunk( $s, str_repeat( 'a', $s->nextLength() ) ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $drive->finishUpload( $s->with( $s->size, $s->extra ) ) ) );
		$drive->abort( $s );
		$this->assertSame( [], $recorded->requests );
	}

	public function test_storage_full_and_rate_limits_are_classified(): void {
		$full = '{"error":{"errors":[{"domain":"global","reason":"storageQuotaExceeded"}],"code":403}}';
		$busy = '{"error":{"errors":[{"domain":"usageLimits","reason":"userRateLimitExceeded"}],"code":403}}';
		$deny = '{"error":{"errors":[{"domain":"global","reason":"insufficientFilePermissions"}],"code":403}}';

		$this->assertSame( 'permanent ' . PermanentFailure::FULL, self::failure( fn() => $this->drive( new RecordedHttp( [ RecordedHttp::answer( 200, self::NONE ), RecordedHttp::answer( 403, $full ) ] ) )->startUpload( self::NAME, 10 ) ) );
		$this->assertSame( 'transient ' . TransientFailure::BUSY, self::failure( fn() => $this->drive( new RecordedHttp( [ RecordedHttp::answer( 200, self::NONE ), RecordedHttp::answer( 403, $busy, [ 'Retry-After' => '30' ] ) ] ) )->startUpload( self::NAME, 10 ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::DENIED, self::failure( fn() => $this->drive( new RecordedHttp( [ RecordedHttp::answer( 403, $deny ) ] ) )->startUpload( self::NAME, 10 ) ) );
	}

	public function test_failure_messages_carry_no_provider_text(): void {
		$body = '{"error":{"errors":[{"reason":"weird"}],"code":400,"message":"Secret provider text"}}';
		try {
			$this->drive( new RecordedHttp( [ RecordedHttp::answer( 400, $body ) ] ) )->startUpload( self::NAME, 10 );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertStringNotContainsString( 'Secret', $e->getMessage() );
			$this->assertStringContainsString( 'Google Drive', $e->getMessage() );
		}
	}

	public function test_list_reads_every_page_and_keeps_only_backups_and_the_test_file(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"files":[{"id":"f1","name":"a-20261010-030000-001.wpress","size":"10","modifiedTime":"2026-10-10T03:00:00.000Z"},{"id":"d1","name":"sub"},{"id":"x1","name":"notes.txt","size":"3"}],"nextPageToken":"p2"}' ),
				RecordedHttp::answer( 200, '{"files":[{"id":"f2","name":"b-20261011-030000-001.wpress","size":"20","modifiedTime":"2026-10-11T03:00:00.000Z"},{"id":"bad/id","name":"c-20261011-030000-001.wpress","size":"1"},{"id":"p1","name":"lw-migration-test-0123456789abcdef.txt","size":"4"}]}' ),
			]
		);

		$files = $this->drive( $recorded )->list();

		$this->assertSame( [ 'f1', 'f2', 'p1' ], array_map( static fn( RemoteFile $f ): string => $f->ref, $files ) );
		$this->assertSame( 20, $files[1]->size );
		$this->assertSame( 1791687600, $files[1]->mtime );
		$this->assertStringContainsString( "'folder-1' in parents and trashed = false", rawurldecode( $recorded->requests[0][0] ) );
		$this->assertStringContainsString( 'pageToken=p2', $recorded->requests[1][0] );
		$this->assertSame( 'Bearer ya29', $recorded->requests[1][1]['headers']['Authorization'] );
	}

	public function test_a_listing_without_a_folder_or_of_a_gone_folder_is_empty(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 404 ) ] );

		$this->assertSame( [], $this->drive( new RecordedHttp(), '' )->list() );
		$this->assertSame( [], $this->drive( $recorded )->list() );
	}

	/**
	 * Task 9 m4: a listing that cannot be read to its end is refused, never
	 * cut short — a page token of another shape, more pages than the cap,
	 * or a folder gone between two pages.
	 */
	public function test_a_listing_that_cannot_be_read_to_its_end_is_refused(): void {
		$more = static fn( string $token ): array => RecordedHttp::answer( 200, '{"files":[],"nextPageToken":"' . $token . '"}' );

		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->drive( new RecordedHttp( [ $more( 'bad token' ) ] ) )->list() ), 'A token of another shape.' );
		$capped = new RecordedHttp( array_fill( 0, 51, $more( 'p2' ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->drive( $capped )->list() ), 'More pages than the cap.' );
		$this->assertCount( 50, $capped->requests );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->drive( new RecordedHttp( [ $more( 'p2' ), RecordedHttp::answer( 404 ) ] ) )->list() ), 'Gone between two pages.' );
	}

	public function test_read_range_sends_the_range_and_checks_the_answer(): void {
		$file     = new RemoteFile( self::NAME, 10, 0, 'file-9' );
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 206, 'bcd', [ 'Content-Range' => 'bytes 1-3/10' ] ),
				RecordedHttp::answer( 200, 'abc' ),
				RecordedHttp::answer( 200, 'abc' ),
				RecordedHttp::answer( 206, 'xyz', [ 'Content-Range' => 'bytes 0-2/10' ] ),
				RecordedHttp::answer( 206, 'bc' ),
				RecordedHttp::answer( 206, 'bcd', [ 'Content-Range' => 'bytes one-three/10' ] ),
			]
		);
		$drive    = $this->drive( $recorded );

		$this->assertSame( 'bcd', $drive->readRange( $file, 1, 3 ) );
		$this->assertSame( 'https://www.googleapis.com/drive/v3/files/file-9?alt=media', $recorded->requests[0][0] );
		$this->assertSame( 'bytes=1-3', $recorded->requests[0][1]['headers']['Range'] );
		$this->assertSame( 3, $recorded->requests[0][1]['limit_response_size'] );
		$this->assertSame( 'Bearer ya29', $recorded->requests[0][1]['headers']['Authorization'] );
		$this->assertSame( 'abc', $drive->readRange( $file, 0, 3 ), 'A whole-file answer is fine from byte 0.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $drive->readRange( $file, 1, 3 ) ), 'P-I6: 200 from byte 1.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $drive->readRange( $file, 1, 3 ) ), 'Another part.' );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, self::failure( fn() => $drive->readRange( $file, 1, 3 ) ), 'Fewer bytes.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $drive->readRange( $file, 1, 3 ) ), 'Task 8 m3: a Content-Range that does not parse.' );
	}

	public function test_only_backups_with_a_drive_id_are_read_or_deleted(): void {
		$recorded = new RecordedHttp();
		$drive    = $this->drive( $recorded );
		foreach ( [ new RemoteFile( 'notes.txt', 10, 0, 'file-9' ), new RemoteFile( self::NAME, 10, 0, '../file-9' ), new RemoteFile( self::NAME, 10, 0, 'file-9?alt=media' ) ] as $file ) {
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $drive->readRange( $file, 1, 3 ) ) );
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $drive->delete( $file ) ) );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_delete_of_a_missing_file_is_fine(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 204 ), RecordedHttp::answer( 404 ), RecordedHttp::answer( 500 ) ] );
		$drive    = $this->drive( $recorded );

		$drive->delete( new RemoteFile( self::NAME, 1, 0, 'file-9' ) );
		$drive->delete( new RemoteFile( self::NAME, 1, 0, 'file-9' ) );

		$this->assertSame( 'DELETE', $recorded->requests[0][1]['method'] );
		$this->assertSame( 'https://www.googleapis.com/drive/v3/files/file-9', $recorded->requests[0][0] );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, self::failure( fn() => $drive->delete( new RemoteFile( self::NAME, 1, 0, 'file-9' ) ) ) );
	}

	public function test_abort_sends_nothing(): void {
		$recorded = new RecordedHttp();

		$this->drive( $recorded )->abort( self::session( 600000, self::KIB ) );

		$this->assertSame( [], $recorded->requests );
	}

	/**
	 * P-M3: a chunk is a string in memory that cURL copies, so the largest
	 * one stays under a quarter of the memory still free.
	 */
	public function test_chunks_stay_under_a_quarter_of_the_free_memory(): void {
		$tight = new ChunkSizer( 2 * 1048576, 64 * 1048576, self::KIB, static fn(): ?int => 4 * 1048576, static fn(): float => 0.0 );
		$roomy = new ChunkSizer( 2 * 1048576, 64 * 1048576, self::KIB, static fn(): ?int => null, static fn(): float => 0.0 );
		$tiny  = new ChunkSizer( 2 * 1048576, 64 * 1048576, self::KIB, static fn(): ?int => 1000, static fn(): float => 0.0 );

		$this->assertSame( 1048576, $tight->first() );
		$this->assertSame( 1048576, $tight->next( 1048576, 0.0 ), 'Never above the cap.' );
		$this->assertSame( 2 * 1048576, $roomy->first() );
		$this->assertSame( 4 * 1048576, $roomy->next( 2 * 1048576, 0.0 ) );
		$this->assertSame( 1048576, $roomy->next( 2 * 1048576, -9.0 ), 'A slow chunk halves the next.' );
		$this->assertSame( self::KIB, $tiny->first(), 'At least one multiple.' );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Dropbox;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\ContentHash;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\DropboxApi;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\DropboxDestination;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\DropboxErrors;
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
 * Plan Task 9 (spec 21.5/21.10, E9–E11, E22, E23) with preflight P-C2,
 * P-I2–P-I4, P-I6, P-M2, P-M3 and M7b's never-overwrite rule (H13).
 */
final class DropboxDestinationTest extends MonkeyTestCase {

	private const MIB4 = 4194304;

	private const NAME = 'site-20261012-030000-123.wpress';

	private static ?CurlHooks $curl = null;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		RecordedHttp::install();
		self::$curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => [ '162.125.1.20' ] );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
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
		$vault->put( 'abcd1234', [ 'refreshToken' => 'r', 'accessToken' => '1900000000:sl.b', TokenKeeper::GRANT => 'g1' ] );
		$endpoint = new class() implements TokenEndpoint {
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
		};
		$keeper = new TokenKeeper( 'abcd1234', new OAuthApp( Provider::dropbox(), 'k', 's' ), (array) $vault->open( 'abcd1234' ), $endpoint, $vault, static fn(): int => 1_800_000_000 );
		$curl   = $curl ?? self::$curl ?? ( new CurlHooks() )->install();

		return new OAuthHttp( $keeper, $curl->wrap( $recorded->send() ), static fn(): int => 1_800_000_000 );
	}

	private static function box( RecordedHttp $recorded, ?int $memory = null, string $folder = 'wphu-org' ): DropboxDestination {
		return new DropboxDestination( self::http( $recorded ), $folder, new ChunkSizer( self::MIB4, 2 * self::MIB4, self::MIB4, static fn(): ?int => $memory, static fn(): float => 0.0 ) );
	}

	/**
	 * A session of ours: the block digests of what was sent so far ('a' bytes).
	 */
	private static function session( int $size, int $offset = 0, int $chunk = self::MIB4 ): UploadSession {
		return new UploadSession( 'dropbox', self::NAME, $size, $offset, $chunk, 'sess-1', [ 'blocks' => base64_encode( ContentHash::blocks( str_repeat( 'a', $offset ) ) ) ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function arg( RecordedHttp $recorded, int $i ): array {
		return json_decode( $recorded->requests[ $i ][1]['headers']['Dropbox-API-Arg'], true );
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

	private static function meta( int $size, string $hash, string $tag = 'file' ): array {
		return RecordedHttp::answer( 200, '{".tag":"' . $tag . '","id":"id:abc","name":"' . self::NAME . '","size":' . $size . ',"server_modified":"2026-10-12T03:00:00Z","content_hash":"' . $hash . '"}' );
	}

	public function test_start_opens_a_session_on_the_content_host(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"session_id":"sess-1"}' ) ] );

		$s = self::box( $recorded )->startUpload( self::NAME, 10 );

		[ $url, $args ] = $recorded->requests[0];
		$this->assertSame( 'https://content.dropboxapi.com/2/files/upload_session/start', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( [ 'close' => false ], self::arg( $recorded, 0 ) );
		$this->assertSame( 'application/octet-stream', $args['headers']['Content-Type'] );
		$this->assertSame( 'Bearer sl.b', $args['headers']['Authorization'] );
		$this->assertSame( '', $args['body'] );
		$this->assertSame( 'sess-1', $s->remote );
		$this->assertSame( [ 'blocks' => '' ], $s->extra );
		$this->assertSame( self::MIB4, $s->chunk );
		$this->assertGreaterThan( 0, self::$curl->seen, 'Checked and pinned.' );
	}

	public function test_a_session_id_of_another_shape_and_a_name_that_is_not_a_backup_are_refused(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"session_id":"../x y"}' ) ] );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( $recorded )->startUpload( self::NAME, 10 ) ) );

		$none = new RecordedHttp();
		try {
			self::box( $none )->startUpload( 'notes.txt', 10 );
			$this->fail( 'Not a backup.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( [], $none->requests );
		}
	}

	public function test_append_sends_the_cursor_and_the_bytes_and_moves_the_offset(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, 'null' ) ] );
		$bytes    = str_repeat( 'a', self::MIB4 );

		$s = self::box( $recorded )->uploadChunk( self::session( 10 * 1048576 ), $bytes );

		[ $url, $args ] = $recorded->requests[0];
		$this->assertSame( 'https://content.dropboxapi.com/2/files/upload_session/append_v2', $url );
		$this->assertSame( [ 'cursor' => [ 'session_id' => 'sess-1', 'offset' => 0 ], 'close' => false, 'content_hash' => hash( 'sha256', hash( 'sha256', $bytes, true ) ) ], self::arg( $recorded, 0 ) );
		$this->assertSame( $bytes, $args['body'] );
		$this->assertSame( 'application/octet-stream', $args['headers']['Content-Type'] );
		$this->assertGreaterThanOrEqual( 120, $args['timeout'] );
		$this->assertSame( self::MIB4, $s->offset );
		$this->assertSame( 2 * self::MIB4, $s->chunk, 'A fast chunk doubles the next one.' );
		$this->assertSame( [ 'blocks' => base64_encode( hash( 'sha256', $bytes, true ) ) ], $s->extra );
	}

	/**
	 * A lost answer: the next append of the same part is answered with the
	 * offset Dropbox holds, which is adopted only between what was
	 * confirmed and what was sent, on a block boundary (or the end).
	 */
	public function test_incorrect_offset_adopts_the_correct_offset_without_failing(): void {
		$answer = static fn( int $at ): array => RecordedHttp::answer( 409, '{"error_summary":"incorrect_offset/..","error":{".tag":"incorrect_offset","correct_offset":' . $at . '}}' );

		$all  = self::box( new RecordedHttp( [ $answer( 3 * self::MIB4 ) ] ) )->uploadChunk( self::session( 99 * self::MIB4, self::MIB4, 2 * self::MIB4 ), str_repeat( 'a', 2 * self::MIB4 ) );
		$half = self::box( new RecordedHttp( [ $answer( 2 * self::MIB4 ) ] ) )->uploadChunk( self::session( 99 * self::MIB4, self::MIB4, 2 * self::MIB4 ), str_repeat( 'a', 2 * self::MIB4 ) );
		$end  = self::box( new RecordedHttp( [ $answer( self::MIB4 + 10 ) ] ) )->uploadChunk( self::session( self::MIB4 + 10, self::MIB4 ), str_repeat( 'a', 10 ) );

		$this->assertSame( 3 * self::MIB4, $all->offset, 'The earlier append had arrived; its answer was lost.' );
		$this->assertSame( self::session( 99 * self::MIB4, 3 * self::MIB4 )->extra, $all->extra );
		$this->assertSame( 2 * self::MIB4, $half->offset );
		$this->assertSame( self::session( 99 * self::MIB4, 2 * self::MIB4 )->extra, $half->extra );
		$this->assertSame( self::MIB4 + 10, $end->offset );
		$this->assertTrue( $end->isComplete() );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function offsetsOutOfBounds(): array {
		return [
			'below what was confirmed' => [ 0 ],
			'what was confirmed'       => [ self::MIB4 ],
			'past what was sent'       => [ 3 * self::MIB4 + 1 ],
			'off a block boundary'     => [ self::MIB4 + 5 ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'offsetsOutOfBounds' )]
	public function test_an_offset_outside_what_was_sent_starts_over( int $at ): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"incorrect_offset/..","error":{".tag":"incorrect_offset","correct_offset":' . $at . '}}' ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => self::box( $recorded )->uploadChunk( self::session( 99 * self::MIB4, self::MIB4, 2 * self::MIB4 ), str_repeat( 'a', 2 * self::MIB4 ) ) ) );
	}

	public function test_a_lost_or_closed_session_is_session_gone(): void {
		foreach ( [ 'not_found', 'closed' ] as $tag ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"' . $tag . '/...","error":{".tag":"' . $tag . '"}}' ) ] );
			$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => self::box( $recorded )->uploadChunk( self::session( 10 ), str_repeat( 'a', 10 ) ) ), $tag );
		}
	}

	public function test_a_chunk_of_the_wrong_length_is_refused_before_sending(): void {
		$recorded = new RecordedHttp();
		foreach ( [ 1000, 0 ] as $length ) {
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( $recorded )->uploadChunk( self::session( 10 * 1048576 ), str_repeat( 'a', $length ) ) ) );
		}
		$this->assertSame( [], $recorded->requests );
	}

	/**
	 * P-M3 / Task 8 m4: a session stored by a process with more free memory
	 * sends no more than this process's cap.
	 */
	public function test_a_resumed_chunk_above_the_memory_cap_sends_only_the_cap(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, 'null' ) ] );

		$s = self::box( $recorded, 4 * self::MIB4 )->uploadChunk( self::session( 10 * self::MIB4, self::MIB4, 2 * self::MIB4 ), str_repeat( 'a', 2 * self::MIB4 ) );

		$this->assertSame( self::MIB4, strlen( $recorded->requests[0][1]['body'] ) );
		$this->assertSame( 2 * self::MIB4, $s->offset );
		$this->assertSame( self::MIB4, $s->chunk );
	}

	/**
	 * Task 9 m1: under the memory cap this request sends only a prefix of the
	 * part, but an earlier append (from a process with more memory) may
	 * have delivered all of it: Dropbox's offset is adopted up to the part
	 * the step handed in, never past it.
	 */
	public function test_a_clamped_chunk_adopts_an_offset_up_to_the_part_handed_in(): void {
		$answer   = static fn( int $at ): array => RecordedHttp::answer( 409, '{"error_summary":"incorrect_offset/..","error":{".tag":"incorrect_offset","correct_offset":' . $at . '}}' );
		$recorded = new RecordedHttp( [ $answer( 3 * self::MIB4 ) ] );

		$s = self::box( $recorded, 4 * self::MIB4 )->uploadChunk( self::session( 10 * self::MIB4, self::MIB4, 2 * self::MIB4 ), str_repeat( 'a', 2 * self::MIB4 ) );

		$this->assertSame( self::MIB4, strlen( $recorded->requests[0][1]['body'] ), 'Only the cap was sent.' );
		$this->assertSame( 3 * self::MIB4, $s->offset );
		$this->assertSame( self::session( 10 * self::MIB4, 3 * self::MIB4 )->extra, $s->extra );
		$this->assertSame( self::MIB4, $s->chunk );
		$past = new RecordedHttp( [ $answer( 3 * self::MIB4 + 1 ) ] );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => self::box( $past, 4 * self::MIB4 )->uploadChunk( self::session( 10 * self::MIB4, self::MIB4, 2 * self::MIB4 ), str_repeat( 'a', 2 * self::MIB4 ) ) ) );
	}

	/**
	 * Task 9 m3: every append carries the content_hash of its bytes; a part
	 * Dropbox received damaged is not taken and is sent again (transient).
	 */
	public function test_a_part_received_damaged_is_sent_again(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"content_hash_mismatch/..","error":{".tag":"content_hash_mismatch"}}' ) ] );

		$this->assertSame( 'transient ' . TransientFailure::NETWORK, self::failure( fn() => self::box( $recorded )->uploadChunk( self::session( 10 ), str_repeat( 'a', 10 ) ) ) );
		$this->assertSame( hash( 'sha256', hash( 'sha256', str_repeat( 'a', 10 ), true ) ), self::arg( $recorded, 0 )['content_hash'] );
	}

	public function test_finish_commits_into_the_app_folder_without_renaming_and_returns_the_id(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"id":"id:abc","name":"' . self::NAME . '","size":10,"server_modified":"2026-10-12T03:00:00Z"}' ) ] );

		$file = self::box( $recorded )->finishUpload( self::session( 10, 10 ) );

		$this->assertSame( 'https://content.dropboxapi.com/2/files/upload_session/finish', $recorded->requests[0][0] );
		$this->assertSame(
			[
				'cursor' => [ 'session_id' => 'sess-1', 'offset' => 10 ],
				'commit' => [ 'path' => '/wphu-org/' . self::NAME, 'mode' => 'add', 'autorename' => false, 'mute' => true ],
			],
			self::arg( $recorded, 0 )
		);
		$this->assertSame( '', $recorded->requests[0][1]['body'] );
		$this->assertSame( 'id:abc', $file->ref );
		$this->assertSame( 10, $file->size );
		$this->assertSame( self::NAME, $file->name );
		$this->assertSame( 1791774000, $file->mtime );
	}

	public function test_finish_needs_a_complete_session_and_a_confirmed_file(): void {
		try {
			self::box( new RecordedHttp() )->finishUpload( self::session( 10 ) );
			$this->fail( 'Incomplete.' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'complete', $e->getMessage() );
		}
		foreach ( [ '{"id":"id:abc","name":"' . self::NAME . '","size":9}', '{"id":"../x","name":"' . self::NAME . '","size":10}', 'null' ] as $body ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, $body ) ] );
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( $recorded )->finishUpload( self::session( 10, 10 ) ) ), $body );
		}
	}

	/**
	 * E11 + H13: a finish whose answer got lost (the session is closed) or
	 * an upload started over after it (the name is taken) is this backup
	 * only when the file there has its size and its content hash; anything
	 * else is another file, which is never replaced.
	 */
	public function test_a_lost_finish_answer_or_a_taken_name_is_this_backup_only_with_its_content_hash(): void {
		$s    = self::session( 10, 10 );
		$hash = ContentHash::of( ContentHash::blocks( str_repeat( 'a', 10 ) ) );
		foreach ( [ 'lookup_failed/closed/..', 'path/conflict/file/...' ] as $summary ) {
			$refused  = RecordedHttp::answer( 409, '{"error_summary":"' . $summary . '"}' );
			$recorded = new RecordedHttp( [ $refused, self::meta( 10, $hash ) ] );

			$file = self::box( $recorded )->finishUpload( $s );

			$this->assertSame( 'id:abc', $file->ref, $summary );
			$this->assertSame( 'https://api.dropboxapi.com/2/files/get_metadata', $recorded->requests[1][0] );
			$this->assertSame( [ 'path' => '/wphu-org/' . self::NAME ], json_decode( $recorded->requests[1][1]['body'], true ) );
			$this->assertSame( 'application/json', $recorded->requests[1][1]['headers']['Content-Type'] );
			foreach ( [ self::meta( 9, $hash ), self::meta( 10, str_repeat( '0', 64 ) ), self::meta( 10, $hash, 'folder' ) ] as $other ) {
				$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( new RecordedHttp( [ $refused, $other ] ) )->finishUpload( $s ) ), $summary );
			}
		}
	}

	public function test_a_closed_session_whose_file_is_gone_starts_over_and_a_folder_of_the_name_is_refused(): void {
		$closed  = RecordedHttp::answer( 409, '{"error_summary":"lookup_failed/closed/.."}' );
		$missing = RecordedHttp::answer( 409, '{"error_summary":"path/not_found/.."}' );
		$folder  = RecordedHttp::answer( 409, '{"error_summary":"path/conflict/folder/.."}' );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => self::box( new RecordedHttp( [ $closed, $missing ] ) )->finishUpload( self::session( 10, 10 ) ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( new RecordedHttp( [ $folder ] ) )->finishUpload( self::session( 10, 10 ) ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => self::box( new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"lookup_failed/not_found/.."}' ) ] ) )->finishUpload( self::session( 10, 10 ) ) ) );
	}

	public function test_a_finish_at_another_offset_starts_over(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"lookup_failed/incorrect_offset/..","error":{".tag":"lookup_failed","lookup_failed":{".tag":"incorrect_offset","correct_offset":4}}}' ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => self::box( $recorded )->finishUpload( self::session( 10, 10 ) ) ) );
		$this->assertCount( 1, $recorded->requests, 'No metadata lookup: the session is out of step, not closed.' );
	}

	/**
	 * @return array<string, array{0: UploadSession}>
	 */
	public static function foreignSessions(): array {
		$good = self::session( 3 * self::MIB4, self::MIB4 );

		return [
			'another type'          => [ new UploadSession( 's3', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, $good->extra ) ],
			'a foreign session id'  => [ new UploadSession( 'dropbox', $good->name, $good->size, $good->offset, $good->chunk, 'https://evil.example/x', $good->extra ) ],
			'not a multiple'        => [ new UploadSession( 'dropbox', $good->name, $good->size, $good->offset, 1000, $good->remote, $good->extra ) ],
			'over the largest part' => [ new UploadSession( 'dropbox', $good->name, 900000000, $good->offset, 256 * 1048576, $good->remote, $good->extra ) ],
			'other extras'          => [ new UploadSession( 'dropbox', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, $good->extra + [ 'key' => 'x' ] ) ],
			'digests of another offset' => [ new UploadSession( 'dropbox', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, [ 'blocks' => '' ] ) ],
			'an offset off a block' => [ new UploadSession( 'dropbox', $good->name, $good->size, 5, $good->chunk, $good->remote, $good->extra ) ],
			'not a backup name'     => [ new UploadSession( 'dropbox', 'notes.txt', $good->size, $good->offset, $good->chunk, $good->remote, $good->extra ) ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreignSessions' )]
	public function test_a_foreign_stored_session_starts_over_before_any_request( UploadSession $s ): void {
		$recorded = new RecordedHttp();
		$box      = self::box( $recorded );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $box->uploadChunk( $s, str_repeat( 'a', $s->nextLength() ) ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $box->finishUpload( $s->with( $s->size, $s->extra ) ) ) );
		$box->abort( $s );
		$this->assertSame( [], $recorded->requests );
	}

	public function test_a_missing_site_folder_lists_nothing_and_a_later_page_missing_is_not_a_cut_listing(): void {
		$none = new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"path/not_found/..","error":{".tag":"path","path":{".tag":"not_found"}}}' ) ] );
		$this->assertSame( [], self::box( $none )->list() );
		$this->assertSame( '/wphu-org', json_decode( $none->requests[0][1]['body'], true )['path'] );
	}

	public function test_two_destinations_with_different_folders_send_different_paths(): void {
		$a = new RecordedHttp( [ RecordedHttp::answer( 200, '{"entries":[],"has_more":false}' ) ] );
		$b = new RecordedHttp( [ RecordedHttp::answer( 200, '{"entries":[],"has_more":false}' ) ] );

		self::box( $a, null, 'one-site' )->list();
		self::box( $b, null, 'two-site' )->list();

		$this->assertSame( '/one-site', json_decode( $a->requests[0][1]['body'], true )['path'] );
		$this->assertSame( '/two-site', json_decode( $b->requests[0][1]['body'], true )['path'] );
	}

	public function test_without_a_folder_the_run_time_slug_is_used_never_the_app_folder_root(): void {
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org/blog' );
		foreach ( [ '', '../x', '/' ] as $folder ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"entries":[],"has_more":false}' ) ] );

			self::box( $recorded, null, $folder )->list();

			$this->assertSame( '/wphu-org-blog', json_decode( $recorded->requests[0][1]['body'], true )['path'] );
		}
	}

	public function test_a_listed_entry_whose_path_is_in_another_folder_is_skipped(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"entries":[{".tag":"file","id":"id:1","name":"a-20261010-030000-001.wpress","size":5,"path_lower":"/wphu-org/a-20261010-030000-001.wpress"},{".tag":"file","id":"id:2","name":"b-20261010-030000-001.wpress","size":5,"path_lower":"/other/b-20261010-030000-001.wpress"},{".tag":"file","id":"id:3","name":"c-20261010-030000-001.wpress","size":5,"path_lower":7},{".tag":"file","id":"id:4","name":"d-20261010-030000-001.wpress","size":5}],"has_more":false}' ),
			]
		);

		$files = self::box( $recorded )->list();

		$this->assertSame( [ 'id:1', 'id:4' ], array_map( static fn( RemoteFile $f ): string => $f->ref, $files ) );
	}

	public function test_a_finish_that_lands_in_another_folder_is_not_confirmed(): void {
		$s        = self::session( 10, 10 );
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"id":"id:abc","name":"' . self::NAME . '","size":10,"path_lower":"/other/' . self::NAME . '"}' ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( $recorded )->finishUpload( $s ) ) );
	}

	public function test_the_refusals_name_this_sites_folder(): void {
		$bad = new RemoteFile( 'notes.txt', 1, 0, 'id:abc' );
		foreach ( [ fn() => self::box( new RecordedHttp() )->delete( $bad ), fn() => self::box( new RecordedHttp() )->readRange( $bad, 0, 1 ) ] as $call ) {
			try {
				$call();
				$this->fail( 'Refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertStringContainsString( "this site's folder", $e->getMessage() );
			}
		}
	}

	public function test_list_continues_while_there_is_more_and_keeps_only_backups_and_the_test_file(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"entries":[{".tag":"file","id":"id:1","name":"a-20261010-030000-001.wpress","size":5,"server_modified":"2026-10-10T03:00:00Z"},{".tag":"folder","id":"id:d","name":"sub"},{".tag":"file","id":"id:n","name":"notes.txt","size":3}],"cursor":"c1","has_more":true}' ),
				RecordedHttp::answer( 200, '{"entries":[{".tag":"file","id":"id:2","name":"b-20261011-030000-001.wpress","size":6,"server_modified":"2026-10-11T03:00:00Z"},{".tag":"file","id":"bad/id","name":"c-20261011-030000-001.wpress","size":1},{".tag":"file","id":"id:3","name":"d-20261011-030000-001.wpress","size":"7"},{".tag":"file","id":"id:p","name":"lw-migration-test-0123456789abcdef.txt","size":4}],"cursor":"c2","has_more":false}' ),
			]
		);

		$files = self::box( $recorded )->list();

		$this->assertSame( [ 'id:1', 'id:2', 'id:p' ], array_map( static fn( RemoteFile $f ): string => $f->ref, $files ) );
		$this->assertSame( 1791687600, $files[1]->mtime );
		$this->assertSame( 'https://api.dropboxapi.com/2/files/list_folder', $recorded->requests[0][0] );
		$this->assertSame( [ 'path' => '/wphu-org', 'recursive' => false, 'include_deleted' => false, 'limit' => 1000 ], json_decode( $recorded->requests[0][1]['body'], true ) );
		$this->assertSame( 'https://api.dropboxapi.com/2/files/list_folder/continue', $recorded->requests[1][0] );
		$this->assertSame( [ 'cursor' => 'c1' ], json_decode( $recorded->requests[1][1]['body'], true ) );
		$this->assertSame( 'Bearer sl.b', $recorded->requests[1][1]['headers']['Authorization'] );
	}

	public function test_an_app_folder_not_created_yet_lists_nothing(): void {
		$this->assertSame( [], self::box( new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"path/not_found/.."}' ) ] ) )->list() );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, self::failure( fn() => self::box( new RecordedHttp( [ RecordedHttp::answer( 503 ) ] ) )->list() ) );
	}

	/**
	 * Task 9 m4: a listing that cannot be read to its end is refused, never
	 * cut short — a cursor of another shape, more pages than the cap, or
	 * a folder gone between two pages.
	 */
	public function test_a_listing_that_cannot_be_read_to_its_end_is_refused(): void {
		$more = static fn( string $cursor ): array => RecordedHttp::answer( 200, '{"entries":[],"cursor":"' . $cursor . '","has_more":true}' );

		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( new RecordedHttp( [ $more( 'bad cursor' ) ] ) )->list() ), 'A cursor of another shape.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( new RecordedHttp( [ RecordedHttp::answer( 200, '{"entries":[],"has_more":true}' ) ] ) )->list() ), 'No cursor.' );
		$capped = new RecordedHttp( array_fill( 0, 51, $more( 'c1' ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => self::box( $capped )->list() ), 'More pages than the cap.' );
		$this->assertCount( 50, $capped->requests );
		$gone = new RecordedHttp( [ $more( 'c1' ), RecordedHttp::answer( 409, '{"error_summary":"path/not_found/.."}' ) ] );
		$this->assertSame( 'permanent ' . PermanentFailure::MISSING, self::failure( fn() => self::box( $gone )->list() ), 'Gone between two pages.' );
	}

	public function test_full_storage_busy_namespace_limits_and_permissions_are_classified(): void {
		$this->assertSame( 'permanent ' . PermanentFailure::FULL, self::failure( fn() => self::box( new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"path/insufficient_space/.."}' ) ] ) )->finishUpload( self::session( 10, 10 ) ) ) );
		$this->assertSame( 'transient ' . TransientFailure::BUSY, self::failure( fn() => self::box( new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"too_many_write_operations/.."}' ) ] ) )->startUpload( self::NAME, 10 ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::DENIED, self::failure( fn() => self::box( new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"path/no_write_permission/.."}' ) ] ) )->finishUpload( self::session( 10, 10 ) ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::TOO_LARGE, self::failure( fn() => self::box( new RecordedHttp( [ RecordedHttp::answer( 409, '{"error_summary":"payload_too_large/.."}' ) ] ) )->uploadChunk( self::session( 10 ), str_repeat( 'a', 10 ) ) ) );
		$busy = DropboxErrors::classify( 429, '', '30', 0 );
		$this->assertSame( [ TransientFailure::BUSY, 30 ], [ $busy->reason, $busy->retryAfter ] );
		$this->assertSame( PermanentFailure::REAUTH, DropboxErrors::classify( 401, '', '', 0 )->reason, 'Every Dropbox request carries the token.' );
	}

	public function test_failure_messages_carry_no_provider_text(): void {
		$body = '{"error_summary":"path/disallowed_name/Secret..","error":{".tag":"path"},"user_message":{"text":"Secret provider text"}}';
		try {
			self::box( new RecordedHttp( [ RecordedHttp::answer( 409, $body ) ] ) )->finishUpload( self::session( 10, 10 ) );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( 'Secret', $e->getMessage() );
			$this->assertStringNotContainsString( 'disallowed', $e->getMessage() );
			$this->assertStringContainsString( 'Dropbox', $e->getMessage() );
		}
	}

	public function test_the_arg_header_is_http_header_safe(): void {
		$this->assertSame( '{"path":"/b\u00e9t\u007f"}', DropboxApi::arg( [ 'path' => "/bét\x7f" ] ) );
		$this->assertSame( 1, preg_match( '/^[\x20-\x7E]+\z/', DropboxApi::arg( [ 'path' => "/\u{1F600}-\u{00FC}" ] ) ) );
	}

	public function test_read_range_asks_the_content_host_with_a_range_and_no_content_type(): void {
		$file     = new RemoteFile( self::NAME, 10, 0, 'id:abc' );
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 206, 'bcd', [ 'Content-Range' => 'bytes 1-3/10' ] ),
				RecordedHttp::answer( 200, 'abc' ),
				RecordedHttp::answer( 200, 'abc' ),
				RecordedHttp::answer( 206, 'xyz', [ 'Content-Range' => 'bytes 0-2/10' ] ),
				RecordedHttp::answer( 206, 'xyz', [ 'Content-Range' => 'bytes one/10' ] ),
				RecordedHttp::answer( 206, 'bc' ),
				RecordedHttp::answer( 409, '{"error_summary":"path/not_found/.."}' ),
			]
		);
		$box      = self::box( $recorded );

		$this->assertSame( 'bcd', $box->readRange( $file, 1, 3 ) );
		[ $url, $args ] = $recorded->requests[0];
		$this->assertSame( 'https://content.dropboxapi.com/2/files/download', $url );
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( 'bytes=1-3', $args['headers']['Range'] );
		$this->assertArrayNotHasKey( 'Content-Type', $args['headers'] );
		$this->assertSame( 3, $args['limit_response_size'] );
		$this->assertSame( 'Bearer sl.b', $args['headers']['Authorization'] );
		$this->assertSame( [ 'path' => 'id:abc' ], self::arg( $recorded, 0 ) );
		$this->assertSame( 'abc', $box->readRange( $file, 0, 3 ), 'A whole-file answer is fine from byte 0.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $box->readRange( $file, 1, 3 ) ), 'P-I6: 200 from byte 1.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $box->readRange( $file, 1, 3 ) ), 'Another part.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $box->readRange( $file, 1, 3 ) ), 'A Content-Range that does not parse.' );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, self::failure( fn() => $box->readRange( $file, 1, 3 ) ), 'Fewer bytes.' );
		$this->assertSame( 'permanent ' . PermanentFailure::MISSING, self::failure( fn() => $box->readRange( $file, 1, 3 ) ) );
	}

	public function test_only_backups_with_a_dropbox_id_are_read_or_deleted(): void {
		$recorded = new RecordedHttp();
		$box      = self::box( $recorded );
		foreach ( [ new RemoteFile( 'notes.txt', 10, 0, 'id:abc' ), new RemoteFile( self::NAME, 10, 0, '/other/path' ), new RemoteFile( self::NAME, 10, 0, 'id:../x' ) ] as $file ) {
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $box->readRange( $file, 1, 3 ) ) );
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $box->delete( $file ) ) );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_deleting_a_missing_file_is_fine(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"metadata":{}}' ), RecordedHttp::answer( 409, '{"error_summary":"path_lookup/not_found/.."}' ), RecordedHttp::answer( 500 ) ] );
		$box      = self::box( $recorded );

		$box->delete( new RemoteFile( self::NAME, 1, 0, 'id:abc' ) );
		$box->delete( new RemoteFile( self::NAME, 1, 0, 'id:abc' ) );

		$this->assertSame( 'https://api.dropboxapi.com/2/files/delete_v2', $recorded->requests[0][0] );
		$this->assertSame( [ 'path' => 'id:abc' ], json_decode( $recorded->requests[0][1]['body'], true ) );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, self::failure( fn() => $box->delete( new RemoteFile( self::NAME, 1, 0, 'id:abc' ) ) ) );
	}

	public function test_abort_sends_nothing(): void {
		$recorded = new RecordedHttp();

		self::box( $recorded )->abort( self::session( 10 * self::MIB4, self::MIB4 ) );

		$this->assertSame( [], $recorded->requests );
	}
}

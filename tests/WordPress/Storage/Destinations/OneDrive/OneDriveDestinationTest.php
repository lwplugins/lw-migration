<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OneDrive;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ChunkSizer;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\GraphErrors;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\OneDriveDestination;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\QuickXorHash;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Plan Task 10 (spec 21.5/21.10, E9–E11, E13, E18, E23) with preflight
 * P-C2, P-I2–P-I6, P-M2, P-M3 and M7b's never-overwrite rule (H13).
 */
final class OneDriveDestinationTest extends MonkeyTestCase {

	private const K320 = 327680;

	private const UP = 'https://sn3302.up.1drv.com/up/fe6987415ace7X4e1eF866337';

	private const NAME = 'site-20261012-030000-123.wpress';

	private static ?CurlHooks $curl = null;

	/** @var array<int, float> Clock readings handed out in order. */
	private array $times = [];

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		RecordedHttp::install();
		self::$curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => [ '20.190.160.1' ] );
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
	 * Tokens valid for an hour: no token request in these tests (a renewal
	 * would throw). Needs FakeWpdb, RecordedHttp::install() and the test's
	 * installed CurlHooks.
	 */
	public static function http( RecordedHttp $recorded, ?CurlHooks $curl = null ): OAuthHttp {
		$vault = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$vault->put( 'abcd1234', [ 'refreshToken' => 'r', 'accessToken' => '1900000000:eyJ0', TokenKeeper::GRANT => 'g1' ] );
		$endpoint = new class() implements TokenEndpoint {
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
		};
		$keeper = new TokenKeeper( 'abcd1234', new OAuthApp( Provider::microsoft(), 'c', 's' ), (array) $vault->open( 'abcd1234' ), $endpoint, $vault, static fn(): int => 1_800_000_000 );
		$curl   = $curl ?? self::$curl ?? ( new CurlHooks() )->install();

		return new OAuthHttp( $keeper, $curl->wrap( $recorded->send() ), static fn(): int => 1_800_000_000 );
	}

	private function one( RecordedHttp $recorded, ?int $memory = null, string $folder = 'wphu-org' ): OneDriveDestination {
		return new OneDriveDestination(
			self::http( $recorded ),
			$folder,
			new ChunkSizer(
				self::K320,
				3 * self::K320,
				self::K320,
				static fn(): ?int => $memory,
				function (): float {
					return [] === $this->times ? 0.0 : array_shift( $this->times );
				}
			)
		);
	}

	/**
	 * Answers every fragment like OneDrive: 202 with the next range, 201 with the item at the end.
	 */
	private static function service(): \Closure {
		return static function ( string $url, array $args ): array {
			preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/', (string) ( $args['headers']['Content-Range'] ?? '' ), $m );
			if ( (int) $m[2] + 1 === (int) $m[3] ) {
				return RecordedHttp::answer( 201, '{"id":"item-1","name":"' . self::NAME . '","size":' . $m[3] . ',"file":{}}' );
			}
			return RecordedHttp::answer( 202, '{"nextExpectedRanges":["' . ( (int) $m[2] + 1 ) . '-"]}' );
		};
	}

	/**
	 * A session of ours: the hash fold of what was sent so far ('x' bytes).
	 */
	private static function session( int $size, int $offset = 0, int $chunk = self::K320, ?int $held = null ): UploadSession {
		return new UploadSession(
			'onedrive',
			self::NAME,
			$size,
			$offset,
			$chunk,
			self::UP,
			[
				'held'   => $held ?? $offset,
				'itemId' => '',
				'xor'    => base64_encode( QuickXorHash::fold( str_repeat( 'x', $offset ), 0 ) ),
			]
		);
	}

	/**
	 * The app folder's file of the backup's name, with the hash of $bytes.
	 */
	private static function item( string $bytes, ?int $size = null ): array {
		return RecordedHttp::answer( 200, '{"id":"item-1","name":"' . self::NAME . '","size":' . ( $size ?? strlen( $bytes ) ) . ',"file":{"hashes":{"quickXorHash":"' . QuickXorHash::digest( QuickXorHash::fold( $bytes, 0 ), strlen( $bytes ) ) . '"}}}' );
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

	public function test_start_creates_an_upload_session_in_the_app_folder_that_never_replaces_a_file(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"uploadUrl":"' . self::UP . '","expirationDateTime":"2026-10-13T03:00:00Z"}' ) ] );

		$s = $this->one( $recorded )->startUpload( self::NAME, 1000 );

		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org/' . self::NAME . ':/createUploadSession', $recorded->requests[0][0] );
		$this->assertSame( [ 'item' => [ '@microsoft.graph.conflictBehavior' => 'fail' ] ], json_decode( $recorded->requests[0][1]['body'], true ) );
		$this->assertSame( self::UP, $s->remote );
		$this->assertSame( [ 'held' => 0, 'itemId' => '', 'xor' => base64_encode( QuickXorHash::empty() ) ], $s->extra );
		$this->assertSame( 'Bearer eyJ0', $recorded->requests[0][1]['headers']['Authorization'] );
		$this->assertGreaterThan( 0, self::$curl->seen, 'Checked and pinned.' );
	}

	public function test_start_refuses_a_taken_name_a_foreign_upload_address_and_a_name_that_is_not_a_backup(): void {
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->one( new RecordedHttp( [ RecordedHttp::answer( 409, '{"error":{"code":"nameAlreadyExists"}}' ) ] ) )->startUpload( self::NAME, 10 ) ) );
		foreach ( [ 'http://sn3302.up.1drv.com/up/x', 'https://10.0.0.1/up/x', 'https://u:p@sn3302.up.1drv.com/up/x', 'https://sn3302.up.1drv.com:8443/up/x' ] as $url ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"uploadUrl":"' . $url . '"}' ) ] );
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->one( $recorded )->startUpload( self::NAME, 10 ) ), $url );
		}
		$none = new RecordedHttp();
		try {
			$this->one( $none )->startUpload( 'notes.txt', 10 );
			$this->fail( 'Not a backup.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( [], $none->requests );
		}
	}

	public function test_every_fragment_but_the_last_is_a_multiple_of_320_kib(): void {
		$size     = 3_000_001;
		$recorded = new RecordedHttp( array_fill( 0, 20, self::service() ) );
		$one      = $this->one( $recorded );
		$s        = self::session( $size );
		// Fast, fast, slow, fast…: the chunk doubles, is capped, halves, grows again.
		$this->times = [ 0.0, 0.5, 0.0, 0.5, 0.0, 20.0, 0.0, 0.5, 0.0, 0.5, 0.0, 0.5, 0.0, 0.5 ];
		while ( ! $s->isComplete() ) {
			$s = $one->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) );
		}

		$lengths = [];
		foreach ( $recorded->requests as [ $url, $args ] ) {
			preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/', $args['headers']['Content-Range'], $m );
			$this->assertSame( (string) $size, $m[3], 'The same total in every Content-Range.' );
			$lengths[] = (int) $m[2] - (int) $m[1] + 1;
		}
		$last = array_pop( $lengths );
		foreach ( $lengths as $length ) {
			$this->assertSame( 0, $length % self::K320 );
			$this->assertLessThan( 62914560, $length );
		}
		$this->assertGreaterThan( 0, $last );
		$this->assertSame( 'item-1', $s->extra['itemId'] );
		$this->assertSame( base64_encode( QuickXorHash::fold( str_repeat( 'x', $size ), 0 ) ), $s->extra['xor'], 'The hash fold of every confirmed byte.' );
	}

	public function test_the_last_fragment_may_be_any_length_and_completes(): void {
		$recorded = new RecordedHttp( [ self::service() ] );

		$s = $this->one( $recorded )->uploadChunk( self::session( 2 * self::K320 + 27, 2 * self::K320 ), str_repeat( 'z', 27 ) );

		$this->assertSame( 'bytes 655360-655386/655387', $recorded->requests[0][1]['headers']['Content-Range'] );
		$this->assertTrue( $s->isComplete() );
		$this->assertSame( 'item-1', $this->one( new RecordedHttp() )->finishUpload( $s )->ref );
	}

	/**
	 * A status answer is adopted in two steps (the hash needs the bytes):
	 * resume() records what OneDrive holds, the next uploadChunk() takes
	 * those bytes into the hash without sending them, and later fragments
	 * are whole multiples again.
	 */
	public function test_a_resume_off_a_multiple_keeps_later_fragments_whole_multiples(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"nextExpectedRanges":["400000-"]}' ), self::service() ] );
		$one      = $this->one( $recorded );

		$s = $one->resume( self::session( 3_000_000, self::K320 ) );
		$this->assertSame( self::K320, $s->offset );
		$s = $one->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) );
		$this->assertSame( 400000, $s->offset );
		$this->assertCount( 1, $recorded->requests, 'Held bytes are not sent again.' );
		$s = $one->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) );

		$this->assertSame( 'GET', $recorded->requests[0][1]['method'] );
		$this->assertSame( self::UP, $recorded->requests[0][0] );
		$this->assertArrayNotHasKey( 'Authorization', $recorded->requests[0][1]['headers'] );
		$this->assertSame( 'bytes 400000-727679/3000000', $recorded->requests[1][1]['headers']['Content-Range'] );
		$this->assertSame( 727680, $s->offset );
		$this->assertSame( base64_encode( QuickXorHash::fold( str_repeat( 'x', 727680 ), 0 ) ), $s->extra['xor'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function statusesOutOfStep(): array {
		return [
			'below what was confirmed' => [ '{"nextExpectedRanges":["100-"]}' ],
			'past what was sent'       => [ '{"nextExpectedRanges":["655361-"]}' ],
			'no range'                 => [ '{"nextExpectedRanges":[]}' ],
			'not a range'              => [ '{"nextExpectedRanges":["abc"]}' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'statusesOutOfStep' )]
	public function test_a_status_outside_what_was_sent_starts_over( string $body ): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, $body ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->one( $recorded )->resume( self::session( 3_000_000, self::K320 ) ) ) );
	}

	public function test_fragment_answers_out_of_step_start_over(): void {
		$beyond = new RecordedHttp( [ RecordedHttp::answer( 202, '{"nextExpectedRanges":["700000-"]}' ) ] );
		$early  = new RecordedHttp( [ RecordedHttp::answer( 201, '{"id":"item-1","name":"' . self::NAME . '","size":3000000}' ) ] );
		$still  = new RecordedHttp( [ RecordedHttp::answer( 202, '{"nextExpectedRanges":["327680-"]}' ) ] );

		foreach ( [ $beyond, $early, $still ] as $recorded ) {
			$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->one( $recorded )->uploadChunk( self::session( 3_000_000, self::K320 ), str_repeat( 'x', self::K320 ) ) ) );
		}
	}

	public function test_a_chunk_of_the_wrong_length_is_refused_before_sending(): void {
		$recorded = new RecordedHttp();
		try {
			$this->one( $recorded )->uploadChunk( self::session( 3_000_000 ), str_repeat( 'x', 300000 ) );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_fragment_puts_carry_no_authorization_and_a_refusal_there_is_never_reauth(): void {
		$recorded = new RecordedHttp( [ self::service() ] );

		$this->one( $recorded )->uploadChunk( self::session( 3_000_000 ), str_repeat( 'x', self::K320 ) );

		$this->assertSame( self::UP, $recorded->requests[0][0] );
		$this->assertSame( 'PUT', $recorded->requests[0][1]['method'] );
		$this->assertArrayNotHasKey( 'Authorization', $recorded->requests[0][1]['headers'] );
		foreach ( [ 401, 403, 404, 410 ] as $status ) {
			$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->one( new RecordedHttp( [ RecordedHttp::answer( $status ) ] ) )->uploadChunk( self::session( 3_000_000 ), str_repeat( 'x', self::K320 ) ) ), (string) $status );
		}
	}

	public function test_416_asks_for_the_status_instead_of_failing(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 416 ), RecordedHttp::answer( 200, '{"nextExpectedRanges":["655360-"]}' ) ] );

		$s = $this->one( $recorded )->uploadChunk( self::session( 3_000_000, self::K320 ), str_repeat( 'x', self::K320 ) );

		$this->assertSame( 655360, $s->offset );
		$this->assertSame( base64_encode( QuickXorHash::fold( str_repeat( 'x', 655360 ), 0 ) ), $s->extra['xor'] );
		$this->assertArrayNotHasKey( 'Authorization', $recorded->requests[1][1]['headers'] );
	}

	/**
	 * P-M3: a session stored by a process with more free memory sends no
	 * more than this process's cap; a status after a 416 may still adopt up
	 * to the whole fragment the step handed in (what an earlier process sent).
	 */
	public function test_a_fragment_above_the_memory_cap_sends_only_the_cap(): void {
		$recorded = new RecordedHttp( [ self::service() ] );

		$s = $this->one( $recorded, 4 * self::K320 + 1 )->uploadChunk( self::session( 3_000_000, self::K320, 2 * self::K320 ), str_repeat( 'x', 2 * self::K320 ) );

		$this->assertSame( self::K320, strlen( $recorded->requests[0][1]['body'] ) );
		$this->assertSame( 2 * self::K320, $s->offset );
		$this->assertSame( self::K320, $s->chunk );
		$held = new RecordedHttp( [ RecordedHttp::answer( 416 ), RecordedHttp::answer( 200, '{"nextExpectedRanges":["983040-"]}' ) ] );
		$this->assertSame( 3 * self::K320, $this->one( $held, 4 * self::K320 + 1 )->uploadChunk( self::session( 3_000_000, self::K320, 2 * self::K320 ), str_repeat( 'x', 2 * self::K320 ) )->offset );
	}

	/**
	 * E11 + H13: the session is gone after its last fragment was sent (a
	 * lost final answer): the fragment's bytes go into the hash, and the
	 * app folder's file of the name is this backup only with its size and
	 * quickXorHash; no file starts over, another file is never taken.
	 */
	public function test_a_gone_session_after_the_last_fragment_is_done_only_for_this_backups_file(): void {
		$bytes = str_repeat( 'x', 1000 );
		$check = function ( array $lookup ): string {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 404 ), $lookup ] );
			$one      = $this->one( $recorded );
			$s        = $one->resume( self::session( 1000, 655 ) );
			$this->assertSame( 655, $s->offset );
			try {
				$s = $one->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) );
			} catch ( CloudFailure $e ) {
				return ( $e instanceof PermanentFailure ? 'permanent' : 'transient' ) . ' ' . $e->reason;
			}
			$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org/' . self::NAME . '?$select=id,name,size,file', $recorded->requests[1][0] );
			$this->assertCount( 2, $recorded->requests, 'The last fragment is not sent again.' );
			$this->assertTrue( $s->isComplete() );
			return $s->extra['itemId'];
		};

		$this->assertSame( 'item-1', $check( self::item( $bytes ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, $check( RecordedHttp::answer( 404 ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, $check( self::item( str_repeat( 'y', 1000 ) ) ), 'Another file of the size.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, $check( self::item( $bytes, 999 ) ), 'Another size.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, $check( RecordedHttp::answer( 200, '{"id":"item-1","name":"' . self::NAME . '","size":1000,"file":{}}' ) ), 'No hash to prove it.' );
	}

	/**
	 * Review m2: a status answer that expects nothing more while the last
	 * fragment was in flight is treated like a gone session: the last bytes
	 * decide by the file of the name.
	 */
	public function test_a_status_without_ranges_after_the_last_fragment_decides_by_the_file(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"expirationDateTime":"2026-10-13T03:00:00Z"}' ), self::item( str_repeat( 'x', 1000 ) ) ] );
		$one      = $this->one( $recorded );

		$s = $one->resume( self::session( 1000, 655 ) );
		$s = $one->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) );

		$this->assertTrue( $s->isComplete() );
		$this->assertSame( 'item-1', $s->extra['itemId'] );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->one( new RecordedHttp( [ RecordedHttp::answer( 200, '{}' ) ] ) )->resume( self::session( 3_000_000, self::K320 ) ) ), 'Before the last fragment it is out of step.' );
	}

	public function test_a_gone_session_before_the_last_fragment_starts_over_without_a_lookup(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 404 ) ] );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $this->one( $recorded )->resume( self::session( 3_000_000, self::K320 ) ) ) );
		$this->assertCount( 1, $recorded->requests );
	}

	/**
	 * P-I5: with conflictBehavior fail a name taken meanwhile surfaces as
	 * 409 on the last fragment: this backup's own file (a lost final answer
	 * of an earlier session) is taken, any other is never replaced.
	 */
	public function test_a_name_conflict_on_the_last_fragment_takes_only_this_backups_file(): void {
		$conflict = RecordedHttp::answer( 409, '{"error":{"code":"nameAlreadyExists","message":"Secret provider text"}}' );
		$bytes    = str_repeat( 'x', 1000 );

		foreach ( [ RecordedHttp::answer( 204 ), RecordedHttp::answer( 500 ), new FakeWpError( 'cURL error 28: Operation timed out' ) ] as $cancelled ) {
			$recorded = new RecordedHttp( [ $conflict, self::item( $bytes ), $cancelled ] );
			$mine     = $this->one( $recorded )->uploadChunk( self::session( 1000, 655 ), str_repeat( 'x', 345 ) );
			$this->assertTrue( $mine->isComplete() );
			$this->assertSame( 'item-1', $mine->extra['itemId'] );
			$this->assertSame( [ 'DELETE', self::UP ], [ $recorded->requests[2][1]['method'], $recorded->requests[2][0] ], 'Review m3: the preserved session is cancelled, best effort.' );
			$this->assertArrayNotHasKey( 'Authorization', $recorded->requests[2][1]['headers'] );
		}

		try {
			$this->one( new RecordedHttp( [ $conflict, self::item( str_repeat( 'q', 1000 ) ) ] ) )->uploadChunk( self::session( 1000, 655 ), str_repeat( 'x', 345 ) );
			$this->fail( 'Another file.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'not replaced', $e->getMessage() );
			$this->assertStringNotContainsString( 'Secret', $e->getMessage() );
		}
	}

	public function test_finish_without_an_item_id_looks_up_the_file_by_its_hash(): void {
		$bytes = str_repeat( 'x', 1000 );
		$s     = self::session( 1000, 1000 );

		$this->assertSame( 'item-1', $this->one( new RecordedHttp( [ self::item( $bytes ) ] ) )->finishUpload( $s )->ref );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->one( new RecordedHttp( [ self::item( str_repeat( 'y', 1000 ) ) ] ) )->finishUpload( $s ) ) );
	}

	public function test_download_follows_the_location_with_a_range_and_no_authorization(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 302, '', [ 'Location' => 'https://b0mpua-by3301.files.1drv.com/y23vmag' ] ), RecordedHttp::answer( 206, 'bcd', [ 'Content-Range' => 'bytes 1-3/10' ] ) ] );

		$bytes = $this->one( $recorded )->readRange( new RemoteFile( self::NAME, 10, 0, 'item-1' ), 1, 3 );

		$this->assertSame( 'bcd', $bytes );
		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/items/item-1/content', $recorded->requests[0][0] );
		$this->assertSame( 'Bearer eyJ0', $recorded->requests[0][1]['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'Range', $recorded->requests[0][1]['headers'] );
		$this->assertSame( 0, $recorded->requests[0][1]['redirection'] );
		$this->assertSame( 'https://b0mpua-by3301.files.1drv.com/y23vmag', $recorded->requests[1][0] );
		$this->assertSame( 'bytes=1-3', $recorded->requests[1][1]['headers']['Range'] );
		$this->assertSame( 3, $recorded->requests[1][1]['limit_response_size'] );
		$this->assertArrayNotHasKey( 'Authorization', $recorded->requests[1][1]['headers'] );
	}

	public function test_a_download_location_off_https_or_on_an_ip_is_refused(): void {
		foreach ( [ 'http://files.example/x', 'https://10.0.0.1/x', '' ] as $location ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 302, '', '' === $location ? [] : [ 'Location' => $location ] ) ] );
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->one( $recorded )->readRange( new RemoteFile( self::NAME, 10, 0, 'item-1' ), 0, 3 ) ), $location );
			$this->assertCount( 1, $recorded->requests );
		}
	}

	public function test_a_ranged_read_returns_exactly_the_asked_bytes(): void {
		$file    = new RemoteFile( self::NAME, 10, 0, 'item-1' );
		$moved   = RecordedHttp::answer( 302, '', [ 'Location' => 'https://b0mpua-by3301.files.1drv.com/y23vmag' ] );
		$outcome = fn( array $answer, int $offset ): string => self::failure( fn() => $this->one( new RecordedHttp( [ $moved, $answer ] ) )->readRange( $file, $offset, 3 ) );

		$this->assertSame( 'none', $outcome( RecordedHttp::answer( 200, 'abc' ), 0 ), 'A whole-file answer is fine from byte 0.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, $outcome( RecordedHttp::answer( 200, 'abc' ), 1 ), 'P-I6: 200 from byte 1.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, $outcome( RecordedHttp::answer( 206, 'abc', [ 'Content-Range' => 'bytes 0-2/10' ] ), 1 ), 'Another part.' );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, $outcome( RecordedHttp::answer( 206, 'abc', [ 'Content-Range' => 'bytes one/10' ] ), 1 ), 'A Content-Range that does not parse.' );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, $outcome( RecordedHttp::answer( 206, 'ab' ), 1 ), 'Fewer bytes.' );
		foreach ( [ 401, 403, 404, 410 ] as $status ) {
			$this->assertSame( 'transient ' . TransientFailure::SERVER, $outcome( RecordedHttp::answer( $status ), 1 ), 'P-I2 + review m4: an expired download address (' . $status . ') is passing, never REAUTH or MISSING.' );
		}
		$this->assertSame( 'permanent ' . PermanentFailure::MISSING, self::failure( fn() => $this->one( new RecordedHttp( [ RecordedHttp::answer( 404, '{"error":{"code":"itemNotFound"}}' ) ] ) )->readRange( $file, 0, 3 ) ) );
	}

	public function test_list_keeps_only_backups_and_the_test_file_across_pages(): void {
		$recorded = new RecordedHttp(
			[
				RecordedHttp::answer( 200, '{"value":[{"id":"i1","name":"a-20261010-030000-001.wpress","size":5,"lastModifiedDateTime":"2026-10-10T03:00:00Z","file":{}},{"id":"d1","name":"sub","folder":{"childCount":0}},{"id":"n1","name":"notes.txt","size":3,"file":{}}],"@odata.nextLink":"https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org:/children?$skiptoken=X"}' ),
				RecordedHttp::answer( 200, '{"value":[{"id":"D4648F06C91D9D3D!54927","name":"b-20261011-030000-001.wpress","size":6,"lastModifiedDateTime":"2026-10-11T03:00:00Z","file":{}},{"id":"../x","name":"c-20261011-030000-001.wpress","size":1,"file":{}},{"id":"..","name":"f-20261011-030000-001.wpress","size":1,"file":{}},{"id":"i3","name":"d-20261011-030000-001.wpress","size":"7","file":{}},{"id":"p1","name":"lw-migration-test-0123456789abcdef.txt","size":4,"file":{}},{"id":"f1","name":"e-20261011-030000-001.wpress","size":4,"folder":{}}]}' ),
			]
		);

		$files = $this->one( $recorded )->list();

		$this->assertSame( [ 'i1', 'D4648F06C91D9D3D!54927', 'p1' ], array_map( static fn( RemoteFile $f ): string => $f->ref, $files ) );
		$this->assertSame( 1791687600, $files[1]->mtime );
		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org:/children?$select=id,name,size,lastModifiedDateTime,file&$top=200', $recorded->requests[0][0] );
		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org:/children?$skiptoken=X', $recorded->requests[1][0] );
		$this->assertSame( 'Bearer eyJ0', $recorded->requests[1][1]['headers']['Authorization'] );
	}

	public function test_a_missing_site_folder_lists_nothing_but_a_missing_later_page_does_not(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 404, '{"error":{"code":"itemNotFound","message":"Item does not exist"}}' ) ] );

		$this->assertSame( [], $this->one( $recorded )->list() );
		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org:/children?$select=id,name,size,lastModifiedDateTime,file&$top=200', $recorded->requests[0][0] );

		$other = new RecordedHttp( [ RecordedHttp::answer( 404, '{"error":{"code":"somethingElse"}}' ) ] );
		$this->assertSame( 'permanent ' . PermanentFailure::MISSING, self::failure( fn() => $this->one( $other )->list() ) );
	}

	public function test_two_destinations_with_different_folders_send_different_paths(): void {
		$a = new RecordedHttp( [ RecordedHttp::answer( 200, '{"value":[]}' ), RecordedHttp::answer( 200, '{"uploadUrl":"' . self::UP . '"}' ) ] );
		$b = new RecordedHttp( [ RecordedHttp::answer( 200, '{"value":[]}' ), RecordedHttp::answer( 200, '{"uploadUrl":"' . self::UP . '"}' ) ] );
		$x = $this->one( $a, null, 'one-site' );
		$y = $this->one( $b, null, 'two-site' );

		$x->list();
		$y->list();
		$x->startUpload( self::NAME, 10 );
		$y->startUpload( self::NAME, 10 );

		$this->assertStringContainsString( '/special/approot:/one-site:/children', $a->requests[0][0] );
		$this->assertStringContainsString( '/special/approot:/two-site:/children', $b->requests[0][0] );
		$this->assertStringContainsString( '/special/approot:/one-site/' . self::NAME . ':/createUploadSession', $a->requests[1][0] );
		$this->assertStringContainsString( '/special/approot:/two-site/' . self::NAME . ':/createUploadSession', $b->requests[1][0] );
	}

	public function test_without_a_folder_the_run_time_slug_is_used_never_the_app_folder_root(): void {
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org/blog' );
		foreach ( [ '', '../x', '/' ] as $folder ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"value":[]}' ) ] );

			$this->one( $recorded, null, $folder )->list();

			$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org-blog:/children?$select=id,name,size,lastModifiedDateTime,file&$top=200', $recorded->requests[0][0] );
		}
	}

	public function test_the_refusals_name_this_sites_folder(): void {
		$bad = new RemoteFile( 'notes.txt', 1, 0, 'item-1' );
		foreach ( [ fn() => $this->one( new RecordedHttp() )->delete( $bad ), fn() => $this->one( new RecordedHttp() )->readRange( $bad, 0, 1 ) ] as $call ) {
			try {
				$call();
				$this->fail( 'Refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertStringContainsString( "this site's folder", $e->getMessage() );
			}
		}
	}

	/**
	 * P-C2 + Task 9 m4: a next link off Graph is never followed, and a
	 * listing that cannot be read to its end is refused, not cut short.
	 */
	public function test_a_listing_that_cannot_be_read_to_its_end_is_refused(): void {
		$page = static fn( string $next ): array => RecordedHttp::answer( 200, '{"value":[],"@odata.nextLink":"' . $next . '"}' );

		$off = new RecordedHttp( [ $page( 'https://evil.example/next' ) ] );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->one( $off )->list() ), 'A next link off Graph.' );
		$this->assertCount( 1, $off->requests, 'Not followed.' );
		$capped = new RecordedHttp( array_fill( 0, 51, $page( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org:/children?$skiptoken=X' ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $this->one( $capped )->list() ), 'More pages than the cap.' );
		$this->assertCount( 50, $capped->requests );
		$gone = new RecordedHttp( [ $page( 'https://graph.microsoft.com/v1.0/me/drive/special/approot:/wphu-org:/children?$skiptoken=X' ), RecordedHttp::answer( 404 ) ] );
		$this->assertSame( 'permanent ' . PermanentFailure::MISSING, self::failure( fn() => $this->one( $gone )->list() ), 'Gone between two pages.' );
	}

	public function test_full_storage_and_throttling_are_classified_and_abort_cancels_the_session(): void {
		$this->assertSame( 'permanent ' . PermanentFailure::FULL, self::failure( fn() => $this->one( new RecordedHttp( [ RecordedHttp::answer( 507, '{"error":{"code":"quotaLimitReached"}}' ) ] ) )->startUpload( self::NAME, 10 ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::FULL, self::failure( fn() => $this->one( new RecordedHttp( [ RecordedHttp::answer( 403, '{"error":{"code":"quotaLimitReached"}}' ) ] ) )->startUpload( self::NAME, 10 ) ) );
		$busy = GraphErrors::classify( 429, '{"error":{"code":"activityLimitReached"}}', '30', 0 );
		$this->assertSame( [ TransientFailure::BUSY, 30 ], [ $busy->reason, $busy->retryAfter ] );
		$this->assertSame( TransientFailure::BUSY, GraphErrors::classify( 509, '', '', 0 )->reason );
		$this->assertSame( PermanentFailure::REAUTH, GraphErrors::classify( 401, '', '', 0 )->reason, 'Only a request with the token can be REAUTH.' );
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 204 ) ] );

		$this->one( $recorded )->abort( self::session( 3_000_000, self::K320 ) );

		$this->assertSame( 'DELETE', $recorded->requests[0][1]['method'] );
		$this->assertSame( self::UP, $recorded->requests[0][0] );
		$this->assertArrayNotHasKey( 'Authorization', $recorded->requests[0][1]['headers'] );
	}

	public function test_failure_messages_carry_no_provider_text(): void {
		try {
			$this->one( new RecordedHttp( [ RecordedHttp::answer( 400, '{"error":{"code":"invalidRequest","message":"Secret provider text"}}' ) ] ) )->startUpload( self::NAME, 10 );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( 'Secret', $e->getMessage() );
			$this->assertStringNotContainsString( 'invalidRequest', $e->getMessage() );
			$this->assertStringContainsString( 'OneDrive', $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array{0: UploadSession}>
	 */
	public static function foreignSessions(): array {
		$good = self::session( 3_000_000, self::K320 );

		return [
			'another type'          => [ new UploadSession( 'gdrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, $good->extra ) ],
			'a plain http address'  => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, $good->chunk, 'http://sn3302.up.1drv.com/up/x', $good->extra ) ],
			'an ip address'         => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, $good->chunk, 'https://169.254.169.254/up/x', $good->extra ) ],
			'not a multiple'        => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, 300000, $good->remote, $good->extra ) ],
			'over the largest part' => [ new UploadSession( 'onedrive', $good->name, 900000000, $good->offset, 192 * self::K320, $good->remote, $good->extra ) ],
			'other extras'          => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, $good->extra + [ 'key' => 'x' ] ) ],
			'a short hash fold'     => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, [ 'xor' => base64_encode( 'x' ) ] + $good->extra ) ],
			'held past the part'    => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, [ 'held' => 3 * self::K320 ] + $good->extra ) ],
			'held below the offset' => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, [ 'held' => 5 ] + $good->extra ) ],
			'a foreign item id'     => [ new UploadSession( 'onedrive', $good->name, $good->size, $good->offset, $good->chunk, $good->remote, [ 'itemId' => '../x' ] + $good->extra ) ],
			'not a backup name'     => [ new UploadSession( 'onedrive', 'notes.txt', $good->size, $good->offset, $good->chunk, $good->remote, $good->extra ) ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreignSessions' )]
	public function test_a_foreign_stored_session_starts_over_before_any_request( UploadSession $s ): void {
		$recorded = new RecordedHttp();
		$one      = $this->one( $recorded );

		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $one->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $one->resume( $s ) ) );
		$this->assertSame( 'permanent ' . PermanentFailure::SESSION_GONE, self::failure( fn() => $one->finishUpload( $s->with( $s->size, $s->extra ) ) ) );
		$one->abort( $s );
		$this->assertSame( [], $recorded->requests );
	}

	public function test_only_backups_with_a_drive_item_id_are_read_or_deleted(): void {
		$recorded = new RecordedHttp();
		$one      = $this->one( $recorded );
		foreach ( [ new RemoteFile( 'notes.txt', 10, 0, 'item-1' ), new RemoteFile( self::NAME, 10, 0, '../x' ), new RemoteFile( self::NAME, 10, 0, 'a/b' ), new RemoteFile( self::NAME, 10, 0, '.' ), new RemoteFile( self::NAME, 10, 0, '..' ) ] as $file ) {
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $one->readRange( $file, 1, 3 ) ) );
			$this->assertSame( 'permanent ' . PermanentFailure::INVALID, self::failure( fn() => $one->delete( $file ) ) );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_deleting_a_missing_file_is_fine(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 204 ), RecordedHttp::answer( 404 ), RecordedHttp::answer( 500 ) ] );
		$one      = $this->one( $recorded );

		$one->delete( new RemoteFile( self::NAME, 1, 0, 'D4648F06C91D9D3D!54927' ) );
		$one->delete( new RemoteFile( self::NAME, 1, 0, 'item-1' ) );

		$this->assertSame( 'DELETE', $recorded->requests[0][1]['method'] );
		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/drive/items/D4648F06C91D9D3D!54927', $recorded->requests[0][0] );
		$this->assertSame( 'transient ' . TransientFailure::SERVER, self::failure( fn() => $one->delete( new RemoteFile( self::NAME, 1, 0, 'item-1' ) ) ) );
	}
}

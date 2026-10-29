<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\S3;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\ChunkPolicy;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Client;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Destination;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Endpoint;
use LightweightPlugins\Migration\Storage\Destinations\S3\SigV4Signer;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class S3DestinationTest extends MonkeyTestCase {

	private const NAME = 'site-test-20261012-030000-123.wpress';

	private const STARTED = '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><UploadId>UP1</UploadId></InitiateMultipartUploadResult>';

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
	}

	private static function destination( RecordedHttp $http, ?int $memory = null ): S3Destination {
		$client = new S3Client( new S3Endpoint( 'http', '127.0.0.1:9000', 'us-east-1', 'bkt', true ), new SigV4Signer( 'AK', 'SK', 'us-east-1' ), $http->send(), static fn(): int => 1760241600 );

		return new S3Destination( $client, 'sites/one', static fn(): ?int => $memory );
	}

	public function test_a_two_part_upload_starts_sends_equal_parts_and_completes(): void {
		$size = ChunkPolicy::S3_MIN_PART + 3;
		$http = new RecordedHttp(
			[
				RecordedHttp::answer( 200, self::STARTED ),
				RecordedHttp::answer( 200, '', [ 'ETag' => '"e1"' ] ),
				RecordedHttp::answer( 200, '', [ 'ETag' => '"e2"' ] ),
				RecordedHttp::answer( 200, '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>' ),
			]
		);
		$s3 = self::destination( $http );

		$session = $s3->startUpload( self::NAME, $size );
		$session = $s3->uploadChunk( $session, str_repeat( 'a', $session->nextLength() ) );
		$session = $s3->uploadChunk( $session, str_repeat( 'b', $session->nextLength() ) );
		$file    = $s3->finishUpload( $session );

		$this->assertSame( ChunkPolicy::S3_MIN_PART, $session->chunk );
		$this->assertSame( 'http://127.0.0.1:9000/bkt/sites/one/' . self::NAME . '?uploads=', $http->requests[0][0] );
		$this->assertStringEndsWith( '?partNumber=1&uploadId=UP1', $http->requests[1][0] );
		$this->assertSame( ChunkPolicy::S3_MIN_PART, strlen( $http->requests[1][1]['body'] ) );
		$this->assertStringEndsWith( '?partNumber=2&uploadId=UP1', $http->requests[2][0] );
		$this->assertSame( 3, strlen( $http->requests[2][1]['body'] ) );
		$this->assertSame( '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>&quot;e1&quot;</ETag></Part><Part><PartNumber>2</PartNumber><ETag>&quot;e2&quot;</ETag></Part></CompleteMultipartUpload>', $http->requests[3][1]['body'] );
		$this->assertEquals( new RemoteFile( self::NAME, $size, 1760241600, 'sites/one/' . self::NAME ), $file );
	}

	/**
	 * Review Focus 1.
	 */
	public function test_a_part_of_another_length_than_the_session_is_refused_before_sending(): void {
		$http    = new RecordedHttp();
		$session = new UploadSession( 's3', self::NAME, 2 * ChunkPolicy::S3_MIN_PART + 1, 0, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME, 'parts' => [] ] );

		try {
			self::destination( $http )->uploadChunk( $session, str_repeat( 'a', ChunkPolicy::S3_MIN_PART - 1 ) );
			$this->fail( 'Refused.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( [], $http->requests );
		}
	}

	public function test_the_same_part_sent_again_keeps_its_number_and_its_newest_etag(): void {
		$http    = new RecordedHttp( [ RecordedHttp::answer( 200, '', [ 'ETag' => '"old"' ] ), RecordedHttp::answer( 200, '', [ 'ETag' => '"new"' ] ) ] );
		$session = new UploadSession( 's3', self::NAME, 2 * ChunkPolicy::S3_MIN_PART, ChunkPolicy::S3_MIN_PART, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME, 'parts' => [ 1 => '"e1"', 2 => '"old"' ] ] );
		$s3      = self::destination( $http );

		$s3->uploadChunk( $session, str_repeat( 'x', ChunkPolicy::S3_MIN_PART ) );
		$after = $s3->uploadChunk( $session, str_repeat( 'x', ChunkPolicy::S3_MIN_PART ) );

		$this->assertStringContainsString( 'partNumber=2', $http->requests[0][0] );
		$this->assertStringContainsString( 'partNumber=2', $http->requests[1][0] );
		$this->assertSame( [ 1 => '"e1"', 2 => '"new"' ], $after->extra['parts'] );
	}

	public function test_a_completion_that_already_happened_in_a_dead_request_is_recognised(): void {
		$http    = new RecordedHttp(
			[
				RecordedHttp::answer( 404, '<Error><Code>NoSuchUpload</Code></Error>' ),
				RecordedHttp::answer( 200, '', [ 'Content-Length' => '9' ] ),
			]
		);
		$session = new UploadSession( 's3', self::NAME, 9, 9, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME, 'parts' => [ 1 => '"e1"' ] ] );

		$file = self::destination( $http )->finishUpload( $session );

		$this->assertSame( 'HEAD', $http->requests[1][1]['method'] );
		$this->assertSame( 9, $file->size );
	}

	public function test_a_listing_reads_every_page_and_keeps_only_files_directly_in_the_folder(): void {
		$page = static fn( string $items, string $tail ): string => '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' . $items . $tail . '</ListBucketResult>';
		$item = static fn( string $key, int $size ): string => '<Contents><Key>' . $key . '</Key><Size>' . $size . '</Size><LastModified>2026-10-12T03:00:00.000Z</LastModified></Contents>';
		$http = new RecordedHttp(
			[
				RecordedHttp::answer( 200, $page( $item( 'sites/one/a.wpress', 5 ) . $item( 'sites/one/', 0 ), '<IsTruncated>true</IsTruncated><NextContinuationToken>T2</NextContinuationToken>' ) ),
				RecordedHttp::answer( 200, $page( $item( 'sites/one/b.wpress', 6 ) . $item( 'sites/oneother/c.wpress', 1 ), '<IsTruncated>false</IsTruncated>' ) ),
			]
		);

		$files = self::destination( $http )->list();

		$this->assertSame( [ 'a.wpress', 'b.wpress' ], array_map( static fn( RemoteFile $f ): string => $f->name, $files ) );
		$this->assertStringContainsString( 'delimiter=%2F', $http->requests[0][0] );
		$this->assertStringContainsString( 'prefix=sites%2Fone%2F', $http->requests[0][0] );
		$this->assertStringContainsString( 'continuation-token=T2', $http->requests[1][0] );
	}

	public function test_a_listing_that_cannot_be_read_to_its_end_is_refused_not_cut(): void {
		$page  = static fn( string $tail ): string => '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Contents><Key>sites/one/a.wpress</Key><Size>1</Size><LastModified>2026-10-12T03:00:00.000Z</LastModified></Contents>' . $tail . '</ListBucketResult>';
		$more  = RecordedHttp::answer( 200, $page( '<IsTruncated>true</IsTruncated><NextContinuationToken>T</NextContinuationToken>' ) );
		$cases = [
			'no token' => [ RecordedHttp::answer( 200, $page( '<IsTruncated>true</IsTruncated>' ) ) ],
			'too many' => array_fill( 0, 50, $more ),
		];

		foreach ( $cases as $label => $answers ) {
			try {
				self::destination( new RecordedHttp( $answers ) )->list();
				$this->fail( $label . ': refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason, $label );
			}
		}
	}

	public function test_delete_stays_inside_the_folder_and_a_missing_file_is_no_failure(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 404, '<Error><Code>NoSuchKey</Code></Error>' ) ] );
		$s3   = self::destination( $http );

		$s3->delete( new RemoteFile( 'a.wpress', 1, 0, 'sites/one/a.wpress' ) );
		$this->assertSame( 'DELETE', $http->requests[0][1]['method'] );

		try {
			$s3->delete( new RemoteFile( 'a.wpress', 1, 0, 'elsewhere/a.wpress' ) );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertCount( 1, $http->requests );
		}
	}

	public function test_a_ranged_read_asks_for_the_range_and_refuses_a_whole_file_answer(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 206, 'cde' ), RecordedHttp::answer( 200, 'abcdef' ) ] );
		$s3   = self::destination( $http );
		$file = new RemoteFile( 'a.wpress', 6, 0, 'sites/one/a.wpress' );

		$this->assertSame( 'cde', $s3->readRange( $file, 2, 3 ) );
		$this->assertSame( 'bytes=2-4', $http->requests[0][1]['headers']['Range'] );

		$this->expectException( PermanentFailure::class );
		$s3->readRange( $file, 2, 3 );
	}

	public function test_too_little_memory_for_a_part_refuses_before_any_request(): void {
		$http = new RecordedHttp();

		try {
			self::destination( $http, 1048576 )->startUpload( self::NAME, 100 * ChunkPolicy::S3_MIN_PART );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::TOO_LARGE, $e->reason );
			$this->assertSame( [], $http->requests );
		}
	}

	public function test_the_connection_test_compares_the_bytes_read_back_and_deletes_the_file(): void {
		$http = new RecordedHttp(
			[
				RecordedHttp::answer( 200, self::STARTED ),
				RecordedHttp::answer( 200, '', [ 'ETag' => '"e1"' ] ),
				RecordedHttp::answer( 200, '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>' ),
				static function ( string $url, array $args ): array {
					[ $from, $to ] = array_map( 'intval', explode( '-', substr( $args['headers']['Range'], 6 ) ) );
					return RecordedHttp::answer( 206, str_repeat( '?', $to - $from + 1 ) );
				},
				RecordedHttp::answer( 204 ),
			]
		);

		try {
			self::destination( $http )->test();
			$this->fail( 'Different bytes fail the test.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( 'DELETE', $http->requests[4][1]['method'] );
		$this->assertMatchesRegularExpression( '#/bkt/sites/one/lw-migration-test-[a-f0-9]{16}\.txt$#', $http->requests[4][0] );
	}

	public function test_abort_tolerates_an_upload_already_gone(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 404, '<Error><Code>NoSuchUpload</Code></Error>' ) ] );

		self::destination( $http )->abort( new UploadSession( 's3', self::NAME, 9, 0, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME ] ) );

		$this->assertStringEndsWith( '?uploadId=UP1', $http->requests[0][0] );
	}

	public function test_a_file_beyond_the_largest_multipart_upload_is_refused_before_any_request(): void {
		$http = new RecordedHttp();

		try {
			self::destination( $http )->startUpload( self::NAME, ChunkPolicy::S3_MAX_PART * ChunkPolicy::S3_MAX_PARTS + 1 );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::TOO_LARGE, $e->reason );
			$this->assertSame( [], $http->requests );
		}
	}

	public function test_a_dot_name_never_becomes_a_key(): void {
		$http = new RecordedHttp();
		$s3   = self::destination( $http );

		foreach ( [ '.', '..' ] as $name ) {
			try {
				$s3->delete( new RemoteFile( $name, 1, 0, 'sites/one/' . $name ) );
				$this->fail( 'Refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason );
			}
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_a_listing_skips_dot_names(): void {
		$body = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Contents><Key>sites/one/..</Key><Size>1</Size><LastModified>2026-10-12T03:00:00.000Z</LastModified></Contents><Contents><Key>sites/one/a.wpress</Key><Size>1</Size><LastModified>2026-10-12T03:00:00.000Z</LastModified></Contents><IsTruncated>false</IsTruncated></ListBucketResult>';
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, $body ) ] );

		$this->assertSame( [ 'a.wpress' ], array_map( static fn( RemoteFile $f ): string => $f->name, self::destination( $http )->list() ) );
	}

	/**
	 * @return array<string, array{0: UploadSession}>
	 */
	public static function foreign(): array {
		$key = 'sites/one/' . self::NAME;
		$min = ChunkPolicy::S3_MIN_PART;

		return [
			'other type'     => [ new UploadSession( 'gdrive', self::NAME, 2 * $min, 0, $min, 'UP1', [ 'key' => $key, 'parts' => [] ] ) ],
			'foreign key'    => [ new UploadSession( 's3', self::NAME, 2 * $min, 0, $min, 'UP1', [ 'key' => 'elsewhere/x.wpress', 'parts' => [] ] ) ],
			'missing key'    => [ new UploadSession( 's3', self::NAME, 2 * $min, 0, $min, 'UP1', [ 'parts' => [] ] ) ],
			'small part'     => [ new UploadSession( 's3', self::NAME, 100 * ChunkPolicy::MIB, 0, ChunkPolicy::MIB, 'UP1', [ 'key' => $key, 'parts' => [] ] ) ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'foreign' )]
	public function test_a_stored_session_that_is_not_this_uploads_is_refused_before_sending( UploadSession $session ): void {
		$http = new RecordedHttp();
		$s3   = self::destination( $http );

		try {
			$s3->uploadChunk( $session, str_repeat( 'a', $session->nextLength() ) );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
		$done = new UploadSession( $session->type, $session->name, $session->size, $session->size, $session->chunk, 'UP1', $session->extra + [ 'parts' => [] ] );
		try {
			$s3->finishUpload( $done );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
		$s3->abort( $session );
		$this->assertSame( [], $http->requests );
	}

	public function test_a_finish_with_parts_other_than_one_to_n_is_refused_before_sending(): void {
		$http    = new RecordedHttp();
		$session = new UploadSession( 's3', self::NAME, 2 * ChunkPolicy::S3_MIN_PART, 2 * ChunkPolicy::S3_MIN_PART, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME, 'parts' => [ 1 => '"a"', 3 => '"c"' ] ] );

		try {
			self::destination( $http )->finishUpload( $session );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
			$this->assertSame( [], $http->requests );
		}
	}

	public function test_a_replayed_completion_whose_object_has_another_size_rethrows(): void {
		$http    = new RecordedHttp( [ RecordedHttp::answer( 404, '<Error><Code>NoSuchUpload</Code></Error>' ), RecordedHttp::answer( 200, '', [ 'Content-Length' => '8' ] ) ] );
		$session = new UploadSession( 's3', self::NAME, 9, 9, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME, 'parts' => [ 1 => '"e1"' ] ] );

		try {
			self::destination( $http )->finishUpload( $session );
			$this->fail( 'Rethrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
	}

	public function test_an_off_boundary_offset_is_refused_before_sending(): void {
		$http    = new RecordedHttp();
		$session = new UploadSession( 's3', self::NAME, 3 * ChunkPolicy::S3_MIN_PART, 7, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME, 'parts' => [] ] );

		try {
			self::destination( $http )->uploadChunk( $session, str_repeat( 'a', ChunkPolicy::S3_MIN_PART ) );
			$this->fail( 'Refused.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( [], $http->requests );
		}
	}

	public function test_a_whole_file_answer_at_offset_zero_is_accepted(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, 'abc' ) ] );

		$this->assertSame( 'abc', self::destination( $http )->readRange( new RemoteFile( 'a.wpress', 3, 0, 'sites/one/a.wpress' ), 0, 3 ) );
	}

	public function test_a_range_answer_that_starts_elsewhere_is_refused(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 206, 'cde', [ 'Content-Range' => 'bytes 0-2/6' ] ) ] );

		try {
			self::destination( $http )->readRange( new RemoteFile( 'a.wpress', 6, 0, 'sites/one/a.wpress' ), 2, 3 );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
	}

	public function test_a_read_outside_the_folder_or_of_a_slash_name_sends_nothing(): void {
		$http = new RecordedHttp();
		$s3   = self::destination( $http );

		foreach ( [ new RemoteFile( 'a.wpress', 1, 0, 'elsewhere/a.wpress' ), new RemoteFile( 'x/a.wpress', 1, 0, 'sites/one/x/a.wpress' ) ] as $file ) {
			try {
				$s3->readRange( $file, 0, 1 );
				$this->fail( 'Refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason );
			}
		}
		$this->assertSame( [], $http->requests );
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function timeouts(): array {
		return [ 'default' => [ 120, 120 ], 'low' => [ 1, 10 ], 'high' => [ 9999, 600 ] ];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'timeouts' )]
	public function test_the_cloud_timeout_filter_is_clamped( int $filtered, int $used ): void {
		Functions\when( 'apply_filters' )->justReturn( $filtered );
		$seen = 0;
		$http = new RecordedHttp( [ static function ( string $url, array $args ) use ( &$seen ): array {
			$seen = $args['timeout'];
			return RecordedHttp::answer( 200, '', [ 'ETag' => '"e"' ] );
		} ] );
		$session = new UploadSession( 's3', self::NAME, ChunkPolicy::S3_MIN_PART, 0, ChunkPolicy::S3_MIN_PART, 'UP1', [ 'key' => 'sites/one/' . self::NAME, 'parts' => [] ] );

		self::destination( $http )->uploadChunk( $session, str_repeat( 'a', ChunkPolicy::S3_MIN_PART ) );

		$this->assertSame( $used, $seen );
	}
}

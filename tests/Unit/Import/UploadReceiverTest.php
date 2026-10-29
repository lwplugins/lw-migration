<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Import\UploadException;
use LightweightPlugins\Migration\Import\UploadReceiver;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class UploadReceiverTest extends TestCase {

	private string $dir;

	private string $target;

	protected function setUp(): void {
		$this->dir    = TempDir::create();
		$this->target = $this->dir . '/upload.wpress';
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/** The start of a .wpress archive followed by filler bytes. */
	private static function archive( int $tail ): string {
		return Header::pack( 'package.json', 2, 1 ) . '{}' . str_repeat( 'x', $tail );
	}

	private function refused( int $size, int $offset, string $bytes ): UploadException {
		return $this->refusedAt( $this->target, $size, $offset, $bytes );
	}

	private function refusedAt( string $target, int $size, int $offset, string $bytes ): UploadException {
		try {
			UploadReceiver::append( $target, $size, $offset, $bytes );
		} catch ( UploadException $e ) {
			return $e;
		}
		$this->fail( 'The chunk was accepted.' );
	}

	public function test_chunks_append_in_order_and_complete_into_the_target(): void {
		$bytes = self::archive( 6000 );
		$size  = strlen( $bytes );

		$this->assertSame( 0, UploadReceiver::received( $this->target ) );
		$this->assertSame( 5000, UploadReceiver::append( $this->target, $size, 0, substr( $bytes, 0, 5000 ) ) );
		$this->assertSame( 5000, UploadReceiver::received( $this->target ) );
		$this->assertFileDoesNotExist( $this->target );
		$this->assertSame( $size, UploadReceiver::append( $this->target, $size, 5000, substr( $bytes, 5000 ) ) );
		$this->assertSame( $bytes, file_get_contents( $this->target ) );
		$this->assertFileDoesNotExist( $this->target . UploadReceiver::PART_SUFFIX );
		$this->assertSame( 0600, fileperms( $this->target ) & 0777 );
		$this->assertSame( $size, UploadReceiver::received( $this->target ) );
	}

	public function test_a_wrong_offset_reports_what_arrived(): void {
		$bytes = self::archive( 6000 );
		UploadReceiver::append( $this->target, strlen( $bytes ), 0, substr( $bytes, 0, 5000 ) );

		foreach ( [ 0, 4999, 9999 ] as $offset ) {
			$e = $this->refused( strlen( $bytes ), $offset, 'abc' );
			$this->assertSame( UploadException::OFFSET, $e->reason, (string) $offset );
			$this->assertSame( 5000, $e->received );
		}
		$this->assertSame( 5000, UploadReceiver::received( $this->target ), 'Nothing was written.' );
	}

	public function test_too_much_data_is_refused(): void {
		$bytes = self::archive( 6000 );
		$e     = $this->refused( strlen( $bytes ) - 1, 0, $bytes );

		$this->assertSame( UploadException::TOO_LARGE, $e->reason );
		$this->assertSame( 0, UploadReceiver::received( $this->target ) );
		$this->assertFileDoesNotExist( $this->target . UploadReceiver::PART_SUFFIX, 'No stray empty part is left.' );
	}

	public function test_the_first_chunk_must_start_with_package_json(): void {
		$cases = [
			'another first entry' => [ Header::pack( 'index.php', 1, 1 ) . str_repeat( 'x', 6000 ), 20000 ],
			'header cut short'    => [ substr( self::archive( 0 ), 0, 100 ), 20000 ],
			'too small to be one' => [ self::archive( 0 ), 4379 ],
		];
		foreach ( $cases as $label => [ $bytes, $size ] ) {
			$this->assertSame( UploadException::NOT_ARCHIVE, $this->refused( $size, 0, $bytes )->reason, $label );
			$this->assertFileDoesNotExist( $this->target . UploadReceiver::PART_SUFFIX, $label );
		}
	}

	public function test_an_empty_part_and_a_finished_upload_are_refused(): void {
		$bytes = self::archive( 6000 );
		$this->assertSame( UploadException::EMPTY_CHUNK, $this->refused( strlen( $bytes ), 0, '' )->reason );

		UploadReceiver::append( $this->target, strlen( $bytes ), 0, $bytes );
		$done = $this->refused( strlen( $bytes ), strlen( $bytes ), 'x' );
		$this->assertSame( UploadException::OFFSET, $done->reason );
		$this->assertSame( strlen( $bytes ), $done->received );
	}

	public function test_only_the_fixed_upload_name_is_ever_written(): void {
		$bytes = self::archive( 6000 );
		foreach ( [ 'index.php', 'upload.wpress.php', 'other.wpress' ] as $name ) {
			$e = $this->refusedAt( $this->dir . '/' . $name, strlen( $bytes ), 0, $bytes );
			$this->assertSame( UploadException::WRITE, $e->reason, $name );
			$this->assertSame( [ '.', '..' ], scandir( $this->dir ), $name );
		}
	}

	public function test_a_linked_part_is_never_followed(): void {
		$outside = $this->dir . '/outside';
		file_put_contents( $outside, '' );
		symlink( $outside, $this->target . UploadReceiver::PART_SUFFIX );
		$bytes = self::archive( 6000 );

		$this->assertSame( UploadException::WRITE, $this->refused( strlen( $bytes ), 0, $bytes )->reason );
		$this->assertSame( '', file_get_contents( $outside ) );
	}

	public function test_an_occupied_target_keeps_the_part_and_stays_quiet(): void {
		$bytes = self::archive( 6000 );
		mkdir( $this->target );

		$e = $this->refused( strlen( $bytes ), 0, $bytes );
		$this->assertSame( UploadException::WRITE, $e->reason );
		$this->assertSame( strlen( $bytes ), $e->received );
		$this->assertSame( $bytes, file_get_contents( $this->target . UploadReceiver::PART_SUFFIX ) );
	}

	public function test_a_full_part_whose_rename_failed_completes_on_a_resend(): void {
		$bytes = self::archive( 6000 );
		$size  = strlen( $bytes );
		UploadReceiver::append( $this->target, $size, 0, substr( $bytes, 0, 5000 ) );
		mkdir( $this->target );
		$this->assertSame( UploadException::WRITE, $this->refused( $size, 5000, substr( $bytes, 5000 ) )->reason );
		rmdir( $this->target );

		$this->assertSame( $size, UploadReceiver::append( $this->target, $size, 5000, substr( $bytes, 5000 ) ) );
		$this->assertSame( $bytes, file_get_contents( $this->target ) );
		$this->assertFileDoesNotExist( $this->target . UploadReceiver::PART_SUFFIX );
	}

	public function test_finish_renames_a_full_part_only(): void {
		$bytes = self::archive( 6000 );
		$size  = strlen( $bytes );
		UploadReceiver::append( $this->target, $size, 0, substr( $bytes, 0, 5000 ) );
		$this->assertFalse( UploadReceiver::finish( $this->target, $size ) );
		$this->assertFileDoesNotExist( $this->target );

		file_put_contents( $this->target . UploadReceiver::PART_SUFFIX, substr( $bytes, 5000 ), FILE_APPEND );
		$this->assertTrue( UploadReceiver::finish( $this->target, $size ) );
		$this->assertSame( $bytes, file_get_contents( $this->target ) );
		$this->assertTrue( UploadReceiver::finish( $this->target, $size ), 'A finished upload stays finished.' );
	}

	public function test_a_hard_linked_part_is_refused(): void {
		$outside = $this->dir . '/outside';
		file_put_contents( $outside, '' );
		link( $outside, $this->target . UploadReceiver::PART_SUFFIX );
		$bytes = self::archive( 6000 );

		$this->assertSame( UploadException::WRITE, $this->refused( strlen( $bytes ), 0, $bytes )->reason );
		$this->assertSame( '', file_get_contents( $outside ) );
	}

	public function test_a_linked_target_is_never_taken_as_the_upload(): void {
		$bytes   = self::archive( 6000 );
		$outside = $this->dir . '/outside';
		file_put_contents( $outside, $bytes );
		symlink( $outside, $this->target );

		$this->assertSame( 0, UploadReceiver::received( $this->target ) );
		$this->assertFalse( UploadReceiver::finish( $this->target, strlen( $bytes ) ) );
		$this->assertSame( UploadException::WRITE, $this->refused( strlen( $bytes ), 0, $bytes )->reason );
		$this->assertTrue( is_link( $this->target ) );
		$this->assertSame( $bytes, file_get_contents( $outside ) );
	}

	public function test_a_target_of_another_size_is_refused_and_kept(): void {
		file_put_contents( $this->target, 'abc' );
		$bytes = self::archive( 6000 );

		$this->assertSame( UploadException::WRITE, $this->refused( strlen( $bytes ), 0, $bytes )->reason );
		$this->assertSame( 'abc', file_get_contents( $this->target ) );
		$this->assertFileDoesNotExist( $this->target . UploadReceiver::PART_SUFFIX );
	}
}

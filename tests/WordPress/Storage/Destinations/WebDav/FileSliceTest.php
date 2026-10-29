<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Cloud\CloudJobException;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\FileSlice;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Plan D21 (Task 1 carry): a slice is sent only when all its bytes are
 * there, like CloudUploadStep::read() — a short body never reaches a server.
 */
final class FileSliceTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		file_put_contents( $this->dir . '/b.wpress', 'abcdef' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_opens_the_file_at_the_offset(): void {
		$in = ( new FileSlice( $this->dir . '/b.wpress', 2, 4 ) )->open();

		$this->assertSame( 'cdef', fread( $in, 10 ) );
		fclose( $in );
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function shortSlices(): array {
		return [
			'one byte missing' => [ 2, 5 ],
			'past the end'     => [ 7, 1 ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'shortSlices' )]
	public function test_fewer_bytes_than_the_slice_needs_stop_the_job( int $offset, int $length ): void {
		$this->expectException( CloudJobException::class );
		$this->expectExceptionMessage( 'b.wpress' );

		( new FileSlice( $this->dir . '/b.wpress', $offset, $length ) )->open();
	}

	public function test_a_missing_backup_stops_the_job_without_its_folder(): void {
		try {
			( new FileSlice( $this->dir . '/gone.wpress', 0, 1 ) )->open();
			$this->fail( 'Thrown.' );
		} catch ( CloudJobException $e ) {
			$this->assertStringContainsString( 'gone.wpress', $e->getMessage() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
		}
	}
}

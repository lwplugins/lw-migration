<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ArchiveDigest;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ArchiveDigestTest extends TestCase {

	private const BLOCK = 1000;

	private string $dir;

	private string $file;

	protected function setUp(): void {
		$this->dir  = TempDir::create();
		$this->file = $this->dir . '/archive.wpress';
		file_put_contents( $this->file, random_bytes( 3 * self::BLOCK + 500 ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_a_hash_resumed_over_several_ticks_equals_the_one_shot_hash(): void {
		$progress = [];
		$offsets  = [];
		$calls    = 0;
		do {
			$step     = ArchiveDigest::advance( $this->file, $progress, new CountdownDeadline( 0 ), self::BLOCK );
			$offsets[] = $step['offset'];
			$progress = json_decode( (string) json_encode( [ 'offset' => $step['offset'], 'context' => $step['context'] ] ), true ); // As job.json keeps it between ticks.
			++$calls;
		} while ( null === $step['digest'] && $calls < 20 );

		$this->assertSame( hash_file( 'sha256', $this->file ), $step['digest'] );
		$this->assertSame( [ 1000, 2000, 3000, 3500, 3500 ], $offsets, 'One block per tick once the deadline is reached.' );
	}

	public function test_without_a_deadline_one_call_finishes(): void {
		$step = ArchiveDigest::advance( $this->file, [], new NoDeadline(), self::BLOCK );

		$this->assertSame( hash_file( 'sha256', $this->file ), $step['digest'] );
		$this->assertSame( 3500, $step['offset'] );
	}

	public function test_the_deadline_is_checked_after_every_block(): void {
		$step = ArchiveDigest::advance( $this->file, [], new CountdownDeadline( 2 ), self::BLOCK );

		$this->assertNull( $step['digest'] );
		$this->assertSame( 3000, $step['offset'], 'Two checks passed, the third stopped it.' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function damagedProgress(): array {
		return [
			'garbage context'  => [ [ 'offset' => 2000, 'context' => 'bm90IGEgY29udGV4dA==' ] ],
			'another object'   => [ [ 'offset' => 2000, 'context' => base64_encode( serialize( new \ArrayObject() ) ) ] ],
			'negative offset'  => [ [ 'offset' => -5, 'context' => base64_encode( serialize( hash_init( 'sha256' ) ) ) ] ],
			'offset past file' => [ [ 'offset' => 99999, 'context' => base64_encode( serialize( hash_init( 'sha256' ) ) ) ] ],
			'another algo'     => [ [ 'offset' => 0, 'context' => base64_encode( serialize( hash_init( 'md5' ) ) ) ] ],
		];
	}

	/**
	 * @param array<string, mixed> $progress Saved progress.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'damagedProgress' )]
	public function test_damaged_progress_starts_again_from_the_first_byte( array $progress ): void {
		$step = ArchiveDigest::advance( $this->file, $progress, new NoDeadline(), self::BLOCK );

		$this->assertSame( hash_file( 'sha256', $this->file ), $step['digest'] );
	}

	public function test_a_missing_file_throws(): void {
		$this->expectException( \RuntimeException::class );

		ArchiveDigest::advance( $this->dir . '/missing.wpress', [], new NoDeadline(), self::BLOCK );
	}

	public function test_only_an_equal_well_formed_digest_matches(): void {
		$digest = hash( 'sha256', 'x' );

		$this->assertTrue( ArchiveDigest::same( $digest, $digest ) );
		$this->assertFalse( ArchiveDigest::same( hash( 'sha256', 'y' ), $digest ) );
		$this->assertFalse( ArchiveDigest::same( strtoupper( $digest ), strtoupper( $digest ) ) );
		$this->assertFalse( ArchiveDigest::same( null, $digest ) );
		$this->assertFalse( ArchiveDigest::same( $digest, null ) );
		$this->assertFalse( ArchiveDigest::same( '', '' ) );
	}
}

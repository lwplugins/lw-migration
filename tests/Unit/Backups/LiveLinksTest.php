<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\LiveLinks;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class LiveLinksTest extends TestCase {

	private const NOW = 1790000000;

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		file_put_contents( $this->dir . '/a.wpress', 'aaaa' );
		file_put_contents( $this->dir . '/b.wpress', 'bbbb' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function backup( string $name, string $folder = Backup::OWN ): Backup {
		return new Backup( $name, $this->dir . '/' . $name, 4, 1, $folder );
	}

	/**
	 * @return array{id: string, folder: string, name: string, identity: string, createdAt: int, createdBy: int, expiresAt: int, uses: int, lastUsedAt: int}
	 */
	private static function row( string $folder, string $name, string $identity, int $expiresAt ): array {
		return [
			'id'         => substr( md5( $folder . $name . $identity . $expiresAt ), 0, 8 ),
			'folder'     => $folder,
			'name'       => $name,
			'identity'   => $identity,
			'createdAt'  => 1,
			'createdBy'  => 1,
			'expiresAt'  => $expiresAt,
			'uses'       => 0,
			'lastUsedAt' => 0,
		];
	}

	public function test_counts_only_live_links_bound_to_the_file_as_it_is_now(): void {
		$a    = Fingerprint::of( $this->dir . '/a.wpress' );
		$rows = [
			self::row( Backup::OWN, 'a.wpress', $a, self::NOW + 60 ),
			self::row( Backup::OWN, 'a.wpress', $a, self::NOW + 3600 ),
			self::row( Backup::OWN, 'a.wpress', $a, self::NOW ),
			self::row( Backup::OWN, 'a.wpress', str_repeat( 'f', 32 ), self::NOW + 60 ),
			self::row( Backup::OWN, 'a.wpress', '', self::NOW + 60 ),
			self::row( Backup::EXTERNAL, 'a.wpress', $a, self::NOW + 60 ),
			self::row( Backup::OWN, 'gone.wpress', $a, self::NOW + 60 ),
		];

		$counts = LiveLinks::count( [ $this->backup( 'a.wpress' ), $this->backup( 'b.wpress' ) ], $rows, self::NOW );

		$this->assertSame( [ 'own:a.wpress' => 2 ], $counts, 'Expired, replaced-file, legacy and other-folder links do not count.' );
	}

	public function test_no_file_is_read_without_a_live_link(): void {
		$rows   = [ self::row( Backup::OWN, 'b.wpress', 'x', self::NOW - 1 ) ];
		$counts = LiveLinks::count( [ new Backup( 'b.wpress', $this->dir . '/missing/b.wpress', 4, 1, Backup::OWN ) ], $rows, self::NOW );

		$this->assertSame( [], $counts );
	}
}

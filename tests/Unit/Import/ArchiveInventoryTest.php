<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Import\ArchiveInventory;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use PHPUnit\Framework\TestCase;

final class ArchiveInventoryTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress/';

	private const OWN = 'lw-migration/lw-migration.php';

	private static function walked( string $fixture, string $own = self::OWN ): ArchiveInventory {
		$inventory = ArchiveInventory::fromArray( [] );
		$reader    = new Reader( self::FIXTURES . $fixture );
		try {
			$inventory->walk( $reader, new NoDeadline(), $own );
		} finally {
			$reader->close();
		}

		return $inventory;
	}

	private static function package( string $fixture ): PackageConfig {
		return PackageCodec::read( self::FIXTURES . $fixture );
	}

	public function test_a_plain_archive_is_counted_from_its_headers(): void {
		$inventory = self::walked( 'plain.wpress' );

		$this->assertTrue( $inventory->isFinished() );
		$this->assertSame( 1767225600, $inventory->createdAt(), 'The time package.json was written.' );
		$this->assertSame( 14 + 32 + 600000 + 26, $inventory->expandedSize( self::package( 'plain.wpress' ) ) );
		$this->assertSame(
			[
				'database'  => [ 'included' => true, 'bytes' => 26, 'count' => null ],
				'media'     => [ 'included' => true, 'bytes' => 32, 'count' => 1 ],
				'plugins'   => [ 'included' => true, 'bytes' => 600000, 'count' => 1 ],
				'themes'    => [ 'included' => false, 'bytes' => 0, 'count' => 0 ],
				'muPlugins' => [ 'included' => false, 'bytes' => 0, 'count' => 0 ],
			],
			$inventory->parts( self::package( 'plain.wpress' ) )
		);
	}

	public function test_an_encrypted_archive_reports_stored_sizes_and_no_expanded_size(): void {
		$inventory = self::walked( 'encrypted.wpress' );
		$parts     = $inventory->parts( self::package( 'encrypted.wpress' ) );

		$this->assertSame( [ 'included' => true, 'bytes' => 64, 'count' => 1 ], $parts['media'] );
		$this->assertSame( [ 'included' => true, 'bytes' => 48, 'count' => null ], $parts['database'] );
		$this->assertNull( $inventory->expandedSize( self::package( 'encrypted.wpress' ) ), 'Encrypted chunks are larger than the files.' );
	}

	public function test_a_compressed_archive_has_no_expanded_size(): void {
		$this->assertNull( self::walked( 'gzip.wpress' )->expandedSize( self::package( 'gzip.wpress' ) ) );
	}

	public function test_items_are_counted_without_the_placeholder_files(): void {
		$parts = self::walked( 'full-site.wpress' )->parts( self::package( 'full-site.wpress' ) );

		$this->assertSame( [ 'included' => true, 'bytes' => 28 + 45, 'count' => 1 ], $parts['plugins'], 'plugins/index.php is no plugin.' );
		$this->assertSame( [ 'included' => true, 'bytes' => 28 + 18 + 32, 'count' => 1 ], $parts['themes'], 'Only a folder is a theme.' );
		$this->assertSame( [ 'included' => true, 'bytes' => 41, 'count' => 1 ], $parts['muPlugins'] );
		$this->assertSame( [ 'included' => true, 'bytes' => 17, 'count' => 1 ], $parts['media'] );
	}

	public function test_a_network_archive_counts_every_sites_media(): void {
		$parts = self::walked( 'network.wpress' )->parts( self::package( 'network.wpress' ) );

		$this->assertSame( [ 'included' => true, 'bytes' => 32, 'count' => 3 ], $parts['media'] );
		$this->assertSame( 2, $parts['plugins']['count'] );
		$this->assertSame( 1, $parts['themes']['count'] );
		$this->assertSame( [ 'included' => true, 'bytes' => 213656, 'count' => null ], $parts['database'] );
	}

	public function test_this_plugin_in_the_archive_is_not_counted(): void {
		$parts = self::walked( 'plain.wpress', 'sample/sample.php' )->parts( self::package( 'plain.wpress' ) );

		$this->assertSame( [ 'included' => false, 'bytes' => 0, 'count' => 0 ], $parts['plugins'], 'Its folder is never written.' );
	}

	public function test_the_walk_resumes_from_its_saved_state(): void {
		$saved    = [];
		$progress = [];
		do {
			$inventory = ArchiveInventory::fromArray( json_decode( (string) json_encode( $saved ), true ) );
			$reader    = new Reader( self::FIXTURES . 'network.wpress' );
			try {
				$inventory->walk( $reader, new CountdownDeadline( 1 ), self::OWN );
			} finally {
				$reader->close();
			}
			$saved      = $inventory->toArray();
			$progress[] = $inventory->progress();
		} while ( ! $inventory->isFinished() && count( $progress ) < 50 );

		$this->assertGreaterThan( 2, count( $progress ), 'Each run reads a few headers only.' );
		$this->assertSame( $progress, array_values( array_unique( $progress ) ), 'Every run moves on.' );
		$this->assertSame( self::walked( 'network.wpress' )->toArray(), $inventory->toArray() );
	}

	public function test_an_unread_archive_tells_only_what_package_json_says(): void {
		$package = PackageConfig::fromArray( [ 'NoMedia' => true, 'NoMustUsePlugins' => true ] );

		foreach ( [ ArchiveInventory::fromArray( [] ), ArchiveInventory::unreadable() ] as $inventory ) {
			$this->assertNull( $inventory->createdAt() );
			$this->assertNull( $inventory->expandedSize( $package ) );
			$this->assertSame(
				[
					'database'  => [ 'included' => null, 'bytes' => null, 'count' => null ],
					'media'     => [ 'included' => false, 'bytes' => null, 'count' => null ],
					'plugins'   => [ 'included' => null, 'bytes' => null, 'count' => null ],
					'themes'    => [ 'included' => null, 'bytes' => null, 'count' => null ],
					'muPlugins' => [ 'included' => false, 'bytes' => null, 'count' => null ],
				],
				$inventory->parts( $package )
			);
		}
		$this->assertFalse( ArchiveInventory::fromArray( [] )->isFinished() );
		$this->assertTrue( ArchiveInventory::unreadable()->isFinished(), 'Given up: the confirmation goes on without it.' );
	}
}

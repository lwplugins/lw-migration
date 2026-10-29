<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use PHPUnit\Framework\TestCase;

/**
 * The refusals (translated) are in tests/WordPress/Multisite/NetworkPlanRefusalTest.php.
 */
final class BlogMapTest extends TestCase {

	public function test_a_network_archive_keeps_ids_and_computes_addresses(): void {
		$map = BlogMap::plan( ArchiveKind::NETWORK, self::network(), 'http://old.test', self::target( true ) );

		$this->assertSame( 3, $map->count() );
		$this->assertSame( [ 1, 2, 3 ], array_column( $map->all(), 'newId' ) );
		$this->assertSame( [ 'https://new.test', 'https://b2.new.test', 'https://b3.new.test' ], array_column( $map->all(), 'url' ) );
		$this->assertTrue( $map->isResolved() );
		$this->assertFalse( $map->get( 1 )['created'] );
		$this->assertSame( 2, $map->get( 1 )['old']['BlogID'] );
		$this->assertSame( 'http://old.test/b2', $map->get( 1 )['old']['HomeURL'] );

		$main = $map->get( 0 );
		$this->assertSame( [ 'https://new.test', 'https://new.test' ], [ $main['newSiteUrl'], $main['newHomeUrl'] ] );
		$this->assertSame( 'https://new.test/wp-content/uploads/', $main['newUploadsUrl'] );
		$this->assertSame( '/srv/wp-content/uploads', $main['newUploadsDir'] );
		$this->assertSame( [ '', '' ], [ $main['newUploadPath'], $main['newUploadUrlPath'] ] );

		$b2 = $map->get( 1 );
		$this->assertSame( 'https://b2.new.test/wp-content/uploads/sites/2/', $b2['newUploadsUrl'] );
		$this->assertSame( '/srv/wp-content/uploads/sites/2', $b2['newUploadsDir'] );
		$this->assertSame( [ '', '' ], [ $b2['newUploadPath'], $b2['newUploadUrlPath'] ] );
	}

	public function test_uploads_dir_values_with_ms_files(): void {
		$map = BlogMap::plan( ArchiveKind::NETWORK, self::network(), 'http://old.test', self::target( true, true ) );

		$b2 = $map->get( 1 );
		$this->assertSame( 'wp-content/blogs.dir/2/files', $b2['newUploadPath'] );
		$this->assertSame( '', $b2['newUploadUrlPath'], 'The files rewrite serves the URL; upload_url_path stays empty.' );
		$this->assertSame( 'https://b2.new.test/files/', $b2['newUploadsUrl'] );
		$this->assertSame( '/srv/wp-content/blogs.dir/2/files', $b2['newUploadsDir'] );
		$this->assertSame( '', $map->get( 0 )['newUploadPath'], 'The main site keeps the main uploads folder.' );
	}

	public function test_a_sites_archive_waits_for_resolution(): void {
		$map = BlogMap::plan( ArchiveKind::SITES, [ self::network()[1] ], 'http://old.test', self::target( false ) );

		$this->assertNull( $map->get( 0 )['newId'] );
		$this->assertFalse( $map->isResolved() );
		$this->assertSame( 'https://new.test/b2', $map->get( 0 )['url'] );
		$this->assertSame( 'https://new.test/b2', $map->get( 0 )['proposal'] );
		$this->assertSame( '', $map->get( 0 )['newUploadsUrl'] );
		$this->assertNull( $map->newIdOf( 2 ) );

		$done = $map->resolved(
			0,
			[
				'newId'         => 7,
				'created'       => true,
				'newSiteUrl'    => 'https://new.test/b2',
				'newUploadsDir' => '/srv/wp-content/uploads/sites/7',
				'unknown'       => 'x',
			]
		);
		$this->assertTrue( $done->isResolved() );
		$this->assertSame( 7, $done->newIdOf( 2 ) );
		$this->assertTrue( $done->get( 0 )['created'] );
		$this->assertSame( '/srv/wp-content/uploads/sites/7', $done->get( 0 )['newUploadsDir'] );
		$this->assertArrayNotHasKey( 'unknown', $done->get( 0 ) );
		$this->assertFalse( $map->isResolved(), 'resolved() returns a new map.' );
	}

	public function test_given_addresses_replace_proposals_and_are_checked(): void {
		$map   = BlogMap::plan( ArchiveKind::SITES, [ self::network()[1], self::network()[2] ], 'http://old.test', self::target( false ) );
		$given = $map->withAddresses( [ 0 => 'https://New.Test/X/' ], self::target( false ) );

		$this->assertSame( [ 'https://new.test/x', 'https://new.test/b3' ], array_column( $given->all(), 'url' ) );
		$this->assertSame( 'https://new.test/b2', $given->get( 0 )['proposal'], 'The proposal is remembered.' );
		$this->assertSame( 'https://new.test/b2', $map->get( 0 )['url'], 'withAddresses() returns a new map.' );
		$this->assertSame( [], $given->problems( self::target( false ) ) );
	}

	public function test_round_trip(): void {
		$map = BlogMap::plan( ArchiveKind::NETWORK, self::network(), 'http://old.test', self::target( true ) );

		$this->assertEquals( $map, BlogMap::fromArray( $map->toArray() ) );
		$this->assertSame( $map->toArray(), BlogMap::fromArray( json_decode( (string) json_encode( $map->toArray() ), true ) )->toArray() );
	}

	public function test_new_id_of_an_old_id(): void {
		$single = BlogMap::plan( ArchiveKind::SINGLE, [ SiteEntry::fromArray( [ 'HomeURL' => 'https://shop.example' ] ) ], 'https://shop.example', self::target( false ) );
		$this->assertSame( [ null ], $single->oldIds() );
		$this->assertSame( 'https://new.test/shop', $single->get( 0 )['url'] );
		$this->assertSame( 9, $single->resolved( 0, [ 'newId' => 9 ] )->newIdOf( null ) );

		$network = BlogMap::plan( ArchiveKind::NETWORK, self::network(), 'http://old.test', self::target( true ) );
		$this->assertSame( [ 1, 2, 3 ], $network->oldIds() );
		$this->assertSame( 2, $network->newIdOf( 2 ) );
		$this->assertNull( $network->newIdOf( 9 ) );
	}

	/** @return array<int, SiteEntry> */
	private static function network(): array {
		return [
			SiteEntry::fromArray( [ 'BlogID' => 1, 'HomeURL' => 'http://old.test', 'Domain' => 'old.test', 'Path' => '/' ] ),
			SiteEntry::fromArray( [ 'BlogID' => 2, 'HomeURL' => 'http://old.test/b2', 'Domain' => 'old.test', 'Path' => '/b2/' ] ),
			SiteEntry::fromArray( [ 'BlogID' => 3, 'HomeURL' => 'http://old.test/b3', 'Domain' => 'old.test', 'Path' => '/b3/' ] ),
		];
	}

	private static function target( bool $subdomain, bool $blogsDir = false ): TargetNetwork {
		return TargetNetwork::fromArray(
			[
				'subdomain'      => $subdomain,
				'domain'         => 'new.test',
				'path'           => '/',
				'scheme'         => 'https',
				'uploadBlogsDir' => $blogsDir,
				'uploadsDir'     => '/srv/wp-content/uploads',
				'uploadsUrl'     => 'https://new.test/wp-content/uploads',
				'reserved'       => [ 'page' ],
			]
		);
	}
}

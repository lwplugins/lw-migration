<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Package\PackageConfig;
use PHPUnit\Framework\TestCase;

final class SiteEntryTest extends TestCase {

	public function test_the_array_has_the_reference_keys_in_order(): void {
		$entry = new SiteEntry( 2, 1, 0, 'http://n.test/b2', 'http://n.test/b2', 'n.test', '/b2/', [ 'a/a.php' ], 'tt', 'tt', false, '', '/abs/wp-content/uploads/sites/2', 'http://n.test/b2/wp-content/uploads/sites/2/' );

		$this->assertSame(
			[ 'BlogID', 'SiteID', 'LangID', 'SiteURL', 'HomeURL', 'Domain', 'Path', 'Plugins', 'Template', 'Stylesheet', 'Uploads', 'UploadsURL', 'WordPress' ],
			array_keys( $entry->toArray() )
		);
		$this->assertSame( [ 'Uploads', 'UploadsURL' ], array_keys( $entry->toArray()['WordPress'] ) );
		$this->assertFalse( $entry->toArray()['Uploads'], 'An upload_path of false stays false, as get_option returned it.' );
	}

	public function test_an_archive_entry_reads_leniently_and_round_trips(): void {
		$raw   = [ 'BlogID' => '3', 'SiteID' => 1, 'SiteURL' => 'http://n.test/b3', 'HomeURL' => 'http://n.test/b3', 'Domain' => 'n.test', 'Path' => '/b3/', 'Unknown' => 1 ];
		$entry = SiteEntry::fromArray( $raw );

		$this->assertSame( 3, $entry->blogId );
		$this->assertNull( $entry->langId );
		$this->assertSame( [], $entry->plugins );
		$this->assertFalse( $entry->isMain() );
		$this->assertEquals( $entry, SiteEntry::fromArray( $entry->state() ) );
	}

	public function test_main_site_ids_and_odd_values(): void {
		$this->assertTrue( SiteEntry::fromArray( [ 'BlogID' => 1 ] )->isMain() );
		$this->assertTrue( SiteEntry::fromArray( [ 'BlogID' => 0 ] )->isMain() );
		$this->assertTrue( SiteEntry::fromArray( [ 'BlogID' => 'x' ] )->isMain(), 'An id that is not a number reads as null.' );
		$this->assertSame( [ 'a/a.php' ], SiteEntry::fromArray( [ 'Plugins' => [ 'a/a.php', 5, null ] ] )->plugins );
		$this->assertSame( '', SiteEntry::fromArray( [ 'Domain' => [ 'x' ] ] )->domain );
		$this->assertNull( SiteEntry::fromArray( [ 'BlogID' => -4 ] )->blogId, 'A negative id reads as null.' );
		$this->assertFalse( SiteEntry::fromArray( [ 'BlogID' => 2 ] )->isMain() );
	}

	public function test_a_single_site_archive_becomes_one_main_entry(): void {
		$package = PackageConfig::fromArray(
			[
				'SiteURL'         => 'https://www.shop.example/wp',
				'HomeURL'         => 'https://www.shop.example',
				'InternalHomeURL' => 'https://www.shop.example',
				'Plugins'         => [ 'woo/woo.php' ],
				'Template'        => 'storefront',
				'Stylesheet'      => 'storefront',
				'WordPress'       => [ 'Uploads' => '/x/wp-content/uploads', 'UploadsURL' => 'https://www.shop.example/wp-content/uploads/' ],
			]
		);
		$entry   = SiteEntry::fromPackage( $package );

		$this->assertNull( $entry->blogId );
		$this->assertTrue( $entry->isMain() );
		$this->assertSame( [ 'www.shop.example', '/' ], [ $entry->domain, $entry->path ] );
		$this->assertSame( [ 'woo/woo.php' ], $entry->plugins );
		$this->assertSame( 'https://www.shop.example', $entry->internalHomeUrl );
		$this->assertSame( '/x/wp-content/uploads', $entry->wpUploads );
	}

	public function test_a_single_site_address_keeps_its_port(): void {
		$entry = SiteEntry::fromPackage( PackageConfig::fromArray( [ 'HomeURL' => 'http://127.0.0.1:8093/sub' ] ) );

		$this->assertSame( [ '127.0.0.1:8093', '/sub/' ], [ $entry->domain, $entry->path ] );
	}
}

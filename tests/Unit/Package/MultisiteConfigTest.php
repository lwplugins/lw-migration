<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Package;

use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use PHPUnit\Framework\TestCase;

final class MultisiteConfigTest extends TestCase {

	public function test_lists_are_read_and_odd_values_dropped(): void {
		$config = MultisiteConfig::fromJson(
			'{"Network":true,"Networks":[{"SiteID":1,"Domain":"n.test","Path":"/"},"x"],"Plugins":["a/a.php",3,null,"b/b.php"],"Admins":["admin",false]}'
		);

		$this->assertSame( [ [ 'SiteID' => 1, 'Domain' => 'n.test', 'Path' => '/' ] ], $config->networks() );
		$this->assertSame( [ 'a/a.php', 'b/b.php' ], $config->plugins() );
		$this->assertSame( [ 'admin' ], $config->admins() );
	}

	public function test_non_lists_become_empty(): void {
		$config = MultisiteConfig::fromJson( '{"Networks":"n","Plugins":{"x":1},"Admins":7}' );

		$this->assertSame( [], $config->networks() );
		$this->assertSame( [], $config->plugins() );
		$this->assertSame( [], $config->admins() );
		$this->assertSame( [], MultisiteConfig::fromJson( '{}' )->entries() );
	}

	public function test_entries_map_the_sites(): void {
		$config  = MultisiteConfig::fromJson( '{"Sites":[{"BlogID":2,"Domain":"n.test","Path":"/b2/"},5,{"BlogID":"3"}]}' );
		$entries = $config->entries();

		$this->assertCount( 2, $entries );
		$this->assertContainsOnlyInstancesOf( SiteEntry::class, $entries );
		$this->assertSame( [ 2, 3 ], [ $entries[0]->blogId, $entries[1]->blogId ] );
		$this->assertSame( '/b2/', $entries[0]->path );
	}
}

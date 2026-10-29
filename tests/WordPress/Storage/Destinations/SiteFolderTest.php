<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SiteFolderTest extends MonkeyTestCase {

	public function test_a_single_site_folder_is_the_slug_of_the_home_url(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://Shop.Example.com/blog/' );
		$this->assertSame( 'shop-example-com-blog', SiteFolder::name() );
	}

	public function test_a_network_folder_is_the_slug_of_the_network_home_url(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://sub.example.com' );
		Functions\when( 'network_home_url' )->justReturn( 'https://example.com' );
		$this->assertSame( 'example-com', SiteFolder::name() );
	}

	public function test_a_valid_pinned_folder_wins(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		$this->assertSame( 'old-site-9', SiteFolder::of( [ 'folder' => 'old-site-9' ] ) );
	}

	/**
	 * @dataProvider invalidFolders
	 * @param mixed $folder Pinned value.
	 */
	public function test_a_missing_or_invalid_pinned_folder_falls_back_to_the_name( $folder ): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		$this->assertSame( 'wphu-org', SiteFolder::of( [ 'folder' => $folder ] ) );
	}

	public function test_no_folder_key_falls_back_to_the_name(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		$this->assertSame( 'wphu-org', SiteFolder::of( [] ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function invalidFolders(): array {
		return [
			'slash'      => [ 'a/b' ],
			'parent'     => [ '..' ],
			'upper case' => [ 'Site' ],
			'dash first' => [ '-site' ],
			'newline'    => [ "site\n" ],
			'too long'   => [ str_repeat( 'a', 101 ) ],
			'empty'      => [ '' ],
			'int'        => [ 5 ],
			'array'      => [ [ 'x' ] ],
			'null'       => [ null ],
		];
	}
}

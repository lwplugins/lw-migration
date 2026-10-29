<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\FolderSetting;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FolderSettingTest extends MonkeyTestCase {

	public function test_cleans_slashes_and_keeps_a_root_only_where_allowed(): void {
		$this->assertSame( 'backups/my site', FolderSetting::clean( ' /backups//my site/ ', 'folder' ) );
		$this->assertSame( '/var/backups', FolderSetting::clean( '/var//backups/', 'folder', true ) );
		$this->assertSame( '', FolderSetting::clean( null, 'folder' ) );
		$this->assertSame( '/', FolderSetting::clean( '/', 'folder', true ) );
	}

	/** @return array<string, array{string}> */
	public static function siteFolderSlugs(): array {
		return [
			'plain'             => [ 'wphu-org' ],
			'with a path'       => [ 'www-example-com-blog' ],
			'safety lookalike'  => [ 'site-pre-import-x' ],
			'the longest slug'  => [ str_repeat( 'a', 100 ) ],
		];
	}

	/** @dataProvider siteFolderSlugs */
	public function test_accepts_site_folder_slugs_unchanged( string $slug ): void {
		$this->assertSame( $slug, FolderSetting::clean( $slug, 'folder' ) );
	}

	public function test_refuses_dot_parts_and_other_characters_naming_the_field(): void {
		foreach ( [ 'a/../b', './a', 'a/b?c', str_repeat( 'a', 201 ) ] as $bad ) {
			try {
				FolderSetting::clean( $bad, 'folder' );
				$this->fail( $bad );
			} catch ( FieldException $e ) {
				$this->assertSame( 'folder', $e->field );
			}
		}
	}
}

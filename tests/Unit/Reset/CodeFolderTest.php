<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\CodeFolder;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class CodeFolderTest extends TestCase {

	public function test_a_plugin_occupies_its_folder_or_its_single_file(): void {
		$this->assertSame( '/p/akismet', CodeFolder::plugin( '/p', 'akismet/akismet.php' ) );
		$this->assertSame( '/p/hello.php', CodeFolder::plugin( '/p', 'hello.php' ), 'Never the plugins folder itself.' );
		foreach ( [ '', '.', '..', '../x/x.php', 'a/b/c.php', '/abs.php', 'a\\b.php', "nul\0/x.php", 'readme.txt', './x.php' ] as $bad ) {
			$this->assertNull( CodeFolder::plugin( '/p', $bad ), $bad );
		}
	}

	public function test_a_theme_is_one_plain_folder_name(): void {
		$this->assertSame( '/t/twentytwentyfive', CodeFolder::theme( '/t', 'twentytwentyfive' ) );
		$this->assertSame( '/t/my theme', CodeFolder::theme( '/t/', 'my theme' ) );
		foreach ( [ '', '.', '..', 'a/b', '.hidden', "nul\0" ] as $name ) {
			$this->assertNull( CodeFolder::theme( '/t', $name ), $name );
		}
	}

	public function test_only_the_path_itself_counts_as_a_link(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/real' );
			symlink( $dir . '/real', $dir . '/linked' );
			mkdir( $dir . '/real/inside' );
			$this->assertTrue( CodeFolder::isLink( $dir . '/linked' ) );
			$this->assertFalse( CodeFolder::isLink( $dir . '/real' ) );
			$this->assertFalse( CodeFolder::isLink( $dir . '/missing' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}
}

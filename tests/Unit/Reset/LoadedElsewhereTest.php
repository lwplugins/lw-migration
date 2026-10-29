<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\LoadedElsewhere;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class LoadedElsewhereTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/mu-plugins' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function file( string $name, string $text ): string {
		file_put_contents( $this->dir . '/' . $name, $text );

		return $this->dir . '/' . $name;
	}

	public function test_a_drop_in_keeps_the_plugin_folder_it_names_and_no_other(): void {
		$db = $this->file( 'db.php', "<?php require WP_CONTENT_DIR . '/plugins/dbx/load.php';" );
		$this->assertSame(
			[ 'dbx/dbx.php' => 'db.php' ],
			LoadedElsewhere::find( [ 'dbx/dbx.php', 'dbx-pro/dbx.php', 'db/db.php', 'akismet/akismet.php' ], [ 'db.php' => $db ] )
		);
	}

	public function test_a_mu_plugin_naming_the_plugins_constant_keeps_that_plugin(): void {
		$mu = $this->file( 'mu-plugins/boot.php', "<?php require_once WP_PLUGIN_DIR . '/firewall/boot.php';" );
		$this->assertSame( [ 'firewall/firewall.php' => 'mu-plugins/boot.php' ], LoadedElsewhere::find( [ 'firewall/firewall.php', 'firewall2/firewall.php' ], [ 'mu-plugins/boot.php' => $mu ] ) );
		$spaced = $this->file( 'mu-plugins/two.php', '<?php include WP_PLUGIN_DIR."/guard/x.php";' );
		$this->assertSame( [ 'guard/guard.php' => 'mu-plugins/two.php' ], LoadedElsewhere::find( [ 'guard/guard.php' ], [ 'mu-plugins/two.php' => $spaced ] ) );
	}

	public function test_an_auto_prepend_file_keeps_the_plugin_it_names(): void {
		$prepend = $this->file( 'waf.php', "<?php include '/var/www/site/wp-content/plugins/waf/bootstrap.php';" );
		$this->assertSame( [ 'waf/waf.php' => 'auto_prepend_file' ], LoadedElsewhere::find( [ 'waf/waf.php', 'hello.php' ], [ 'auto_prepend_file' => $prepend ] ) );
	}

	public function test_the_sqlite_integration_is_always_kept(): void {
		$this->assertSame( [ 'sqlite-database-integration/load.php' => 'db.php' ], LoadedElsewhere::find( [ 'sqlite-database-integration/load.php', 'other/other.php' ], [] ) );
	}

	public function test_a_large_file_is_read_only_to_its_first_mebibyte(): void {
		$big = $this->file( 'object-cache.php', '<?php ' . str_repeat( ' ', LoadedElsewhere::READ_BYTES ) . "require WP_CONTENT_DIR . '/plugins/late/x.php';" );
		$this->assertSame( [], LoadedElsewhere::find( [ 'late/late.php' ], [ 'object-cache.php' => $big ] ) );
	}

	public function test_loaders_lists_present_drop_ins_top_level_mu_plugins_and_the_prepend_file(): void {
		$this->file( 'db.php', '<?php' );
		$this->file( 'mu-plugins/a.php', '<?php' );
		$this->file( 'mu-plugins/readme.txt', 'x' );
		mkdir( $this->dir . '/mu-plugins/sub' );
		$this->file( 'mu-plugins/sub/b.php', '<?php' );
		$prepend = $this->file( 'prepend.php', '<?php' );
		$this->assertSame(
			[
				'db.php'              => $this->dir . '/db.php',
				'mu-plugins/a.php'    => $this->dir . '/mu-plugins/a.php',
				'auto_prepend_file'   => $prepend,
			],
			LoadedElsewhere::loaders( $this->dir, $this->dir . '/mu-plugins', [ 'db.php', 'advanced-cache.php' ], $prepend )
		);
		$this->assertSame( [], LoadedElsewhere::loaders( $this->dir . '/none', $this->dir . '/none', [ 'db.php' ], '' ) );
	}
}

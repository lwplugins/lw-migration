<?php
/**
 * Tests for SharedCode.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Import\SharedCode;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharedCodeTest extends TestCase {

	/** @return array<string, array{0: string, 1: string|null}> */
	public static function entries(): array {
		return [
			'must-use plugin'      => [ 'mu-plugins/tools.php', SharedCode::SHARED ],
			'must-use folder'      => [ 'mu-plugins/tools/a.php', SharedCode::SHARED ],
			'wp-content root file' => [ 'index.php', SharedCode::SHARED ],
			'network plugin'       => [ 'plugins/akismet/akismet.php', 'kept:plugins/akismet' ],
			'network plugin case'  => [ 'plugins/Akismet/akismet.php', 'kept:plugins/Akismet' ],
			'network plugin file'  => [ 'plugins/hello.php', 'kept:plugins/hello.php' ],
			'new plugin'           => [ 'plugins/shop/shop.php', 'new:plugins/shop' ],
			'new plugin file'      => [ 'plugins/evil.php', 'new:plugins/evil.php' ],
			'network theme'        => [ 'themes/kept/style.css', 'kept:themes/kept' ],
			'new theme'            => [ 'themes/fresh/style.css', 'new:themes/fresh' ],
			'uploads'              => [ 'uploads/2026/a.jpg', null ],
			'other folder'         => [ 'languages/hu_HU.mo', null ],
		];
	}

	#[DataProvider( 'entries' )]
	public function test_the_networks_code_is_never_replaced( string $entry, ?string $expected ): void {
		$code = SharedCode::fromArray(
			[
				'plugins' => [ 'akismet', 'hello.php' ],
				'themes'  => [ 'kept' ],
			]
		);

		$this->assertSame( $expected, $code->reason( $entry ) );
	}

	public function test_without_a_snapshot_every_plugin_and_theme_is_kept(): void {
		$code = SharedCode::fromArray( null );

		$this->assertSame( 'kept:plugins/shop', $code->reason( 'plugins/shop/shop.php' ) );
		$this->assertSame( 'kept:themes/fresh', $code->reason( 'themes/fresh/style.css' ) );
		$this->assertNull( $code->reason( 'uploads/a.jpg' ) );
	}

	public function test_an_unreadable_folder_keeps_everything(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/plugins/akismet', 0755, true );
			mkdir( $dir . '/themes', 0755, true );
			chmod( $dir . '/plugins', 0 );
			if ( is_readable( $dir . '/plugins' ) ) {
				$this->markTestSkipped( 'Running as a user who reads every folder.' );
			}

			$snapshot = SharedCode::snapshot( $dir . '/plugins', $dir . '/themes' );

			$this->assertSame(
				[
					'plugins' => null,
					'themes'  => [],
				],
				$snapshot
			);
			$this->assertSame( 'kept:plugins/shop', SharedCode::fromArray( $snapshot )->reason( 'plugins/shop/shop.php' ), 'Fails closed.' );
			$this->assertSame( 'new:themes/fresh', SharedCode::fromArray( $snapshot )->reason( 'themes/fresh/style.css' ) );
		} finally {
			chmod( $dir . '/plugins', 0755 );
			TempDir::remove( $dir );
		}
	}

	public function test_files_of_other_shared_folders_are_only_added(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/languages', 0755, true );
			file_put_contents( $dir . '/languages/hu_HU.mo', 'x' );
			$code = SharedCode::fromArray( [], $dir );

			$this->assertSame( SharedCode::EXISTING, $code->reason( 'languages/hu_HU.mo' ) );
			$this->assertNull( $code->reason( 'languages/de_DE.mo' ) );
			$this->assertNull( $code->reason( 'uploads/2026/a.jpg' ), 'Uploads go by blog (UploadRoutes).' );
			$this->assertNull( $code->reason( 'blogs.dir/2/files/a.jpg' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	/**
	 * Final review I1: a new file that PHP or the web server would run is
	 * never added to a shared folder; other new files still are.
	 */
	public function test_new_code_files_in_other_shared_folders_are_skipped(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/languages', 0755, true );
			file_put_contents( $dir . '/languages/hu_HU.l10n.php', '<?php' );
			$code = SharedCode::fromArray(
				[
					'plugins' => [],
					'themes'  => [],
				],
				$dir
			);

			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'languages/de_DE.l10n.php' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'languages/plugins/x-de_DE.l10n.PHP' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/x.phtml' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/x.phar' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/x.php7' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/.htaccess' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/.user.ini' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/x.pht' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/x.PHPS' ) );
			$this->assertSame( SharedCode::NEW_CODE, $code->reason( 'fonts/web.config' ) );
			$this->assertSame( SharedCode::EXISTING, $code->reason( 'languages/hu_HU.l10n.php' ), 'One the network has is kept.' );
			$this->assertNull( $code->reason( 'languages/de_DE.mo' ), 'Other new files are still added.' );
			$this->assertNull( $code->reason( 'fonts/php.woff2' ) );
			$this->assertSame( 'new:plugins/shop', $code->reason( 'plugins/shop/shop.php' ), 'Re-review: a plugin the network does not have is not written at all.' );
			$this->assertNull( $code->reason( 'uploads/2026/a.php' ), 'Uploads go by blog (UploadRoutes).' );
			$this->assertSame( SharedCode::NEW_CODE, SharedCode::fromArray( [] )->reason( 'languages/de_DE.l10n.php' ), 'Also without an existence check.' );
		} finally {
			TempDir::remove( $dir );
		}
	}

	/**
	 * Final review I1: only code the network had before the import is activated.
	 */
	public function test_only_code_the_network_had_before_counts_as_its_own(): void {
		$code = SharedCode::fromArray(
			[
				'plugins' => [ 'akismet', 'hello.php' ],
				'themes'  => [ 'kept' ],
			]
		);

		$this->assertTrue( $code->hadPlugin( 'akismet/akismet.php' ) );
		$this->assertTrue( $code->hadPlugin( 'Akismet/akismet.php' ) );
		$this->assertTrue( $code->hadPlugin( 'hello.php' ) );
		$this->assertFalse( $code->hadPlugin( 'evil/evil.php' ) );
		$this->assertFalse( $code->hadPlugin( 'akismet.php' ), 'A single-file plugin is its own name.' );
		$this->assertTrue( $code->hadTheme( 'Kept' ) );
		$this->assertFalse( $code->hadTheme( 'fresh' ) );
		$this->assertSame(
			[ 'evil/evil.php' ],
			$code->newPlugins( [ 'akismet/akismet.php', 'evil/evil.php', 'gone/gone.php' ], [ 'akismet/akismet.php' => '1', 'evil/evil.php' => '1' ] ),
			'Installed now but not before; a missing one is left to the plugin policy.'
		);
	}

	public function test_without_a_snapshot_everything_on_disk_was_there_before(): void {
		$code = SharedCode::fromArray( null );

		$this->assertTrue( $code->hadPlugin( 'evil/evil.php' ), 'No plugin of the archive was written (reason() keeps them all).' );
		$this->assertTrue( $code->hadTheme( 'fresh' ) );
		$this->assertSame( [], $code->newPlugins( [ 'evil/evil.php' ], [ 'evil/evil.php' => '1' ] ) );
		$this->assertTrue( SharedCode::fromArray( [ 'plugins' => null, 'themes' => [] ] )->hadPlugin( 'evil/evil.php' ), 'An unlisted folder kept everything.' );
		$this->assertFalse( SharedCode::fromArray( [ 'plugins' => null, 'themes' => [] ] )->hadTheme( 'fresh' ) );
	}

	public function test_the_snapshot_lists_what_the_network_has_now(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/plugins/akismet', 0755, true );
			file_put_contents( $dir . '/plugins/hello.php', '<?php' );
			mkdir( $dir . '/themes/Kept', 0755, true );

			$snapshot = SharedCode::snapshot( $dir . '/plugins', $dir . '/themes' );

			$this->assertSame(
				[
					'plugins' => [ 'akismet', 'hello.php' ],
					'themes'  => [ 'kept' ],
				],
				$snapshot
			);
			$this->assertSame( 'kept:themes/kept', SharedCode::fromArray( $snapshot )->reason( 'themes/kept/style.css' ) );
			$this->assertSame( [ 'plugins' => [], 'themes' => [ 'kept' ] ], SharedCode::snapshot( $dir . '/none', $dir . '/themes' ), 'A missing folder has nothing to keep.' );
		} finally {
			TempDir::remove( $dir );
		}
	}
}

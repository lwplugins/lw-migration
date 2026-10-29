<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Reset\DefaultTheme;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DefaultThemeTest extends MonkeyTestCase {

	private function theme( array $installed, ?string $core, bool $downloads, bool $mayDownload = true, bool $mayWrite = true, array &$log = [] ): DefaultTheme {
		return new DefaultTheme(
			static fn( string $slug ): bool => in_array( $slug, $installed, true ),
			static fn(): ?string => $core,
			static function ( string $slug ) use ( $downloads, &$log ): bool {
				$log[] = 'download:' . $slug;
				return $downloads;
			},
			static function () use ( &$log ): string {
				$log[] = 'stub';
				return 'lw-migration-blank';
			},
			$mayDownload,
			$mayWrite
		);
	}

	public function test_the_order_installed_core_default_download_stub(): void {
		$this->assertSame( [ 'slug' => WP_DEFAULT_THEME, 'source' => 'installed' ], $this->theme( [ WP_DEFAULT_THEME ], null, false )->choose() );
		$this->assertSame( [ 'slug' => 'twentytwentythree', 'source' => 'installed' ], $this->theme( [ 'twentytwentythree' ], 'twentytwentythree', false )->choose() );
		$this->assertSame( [ 'slug' => WP_DEFAULT_THEME, 'source' => 'download' ], $this->theme( [], null, true )->choose() );
		$this->assertSame( [ 'slug' => 'lw-migration-blank', 'source' => 'stub' ], $this->theme( [], null, true, false )->choose() );
	}

	public function test_choose_has_no_side_effect(): void {
		$log = [];
		$this->theme( [], null, true, true, true, $log )->choose();
		$this->assertSame( [], $log );
	}

	public function test_a_download_that_works_is_used(): void {
		$log = [];
		$this->assertSame( [ 'slug' => WP_DEFAULT_THEME, 'source' => 'download' ], $this->theme( [], null, true, true, true, $log )->ensure() );
		$this->assertSame( [ 'download:' . WP_DEFAULT_THEME ], $log );
	}

	public function test_a_failed_download_falls_back_to_the_stub(): void {
		$log    = [];
		$result = $this->theme( [], null, false, true, true, $log )->ensure();
		$this->assertSame( 'stub', $result['source'] );
		$this->assertSame( 'The default theme could not be downloaded, so a minimal blank theme was installed instead.', $result['note'] );
		$this->assertSame( [ 'download:' . WP_DEFAULT_THEME, 'stub' ], $log );
	}

	public function test_root_never_writes_a_theme(): void {
		$this->expectException( JobException::class );
		$this->expectExceptionMessage( 'run it as the site\'s owner' );
		$this->theme( [], null, true, true, false )->ensure();
	}

	public function test_root_may_use_an_installed_theme(): void {
		$this->assertSame( 'installed', $this->theme( [ WP_DEFAULT_THEME ], null, false, true, false )->ensure()['source'] );
	}

	public function test_a_theme_with_errors_is_not_usable(): void {
		$theme = static fn( bool $exists, $errors ): object => new class( $exists, $errors ) {
			public function __construct( private bool $exists, private $errors ) {
			}

			public function exists(): bool {
				return $this->exists;
			}

			public function errors() {
				return $this->errors;
			}
		};
		$this->assertTrue( DefaultTheme::usable( $theme( true, false ) ) );
		$this->assertFalse( DefaultTheme::usable( $theme( true, new \stdClass() ) ), 'A broken theme (a missing parent, no template) leaves the site blank.' );
		$this->assertFalse( DefaultTheme::usable( $theme( false, false ) ) );
	}
}

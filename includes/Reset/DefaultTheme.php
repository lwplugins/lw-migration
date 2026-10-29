<?php
/**
 * Picks and, when needed, installs the theme a theme reset activates.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * In this order: the installed WP_DEFAULT_THEME, the newest installed core
 * default theme, WP_DEFAULT_THEME downloaded from wordpress.org (when file
 * changes are allowed), the minimal blank theme. choose() has no side effect
 * (the reset summary uses it); ensure() downloads or writes. A command run
 * as root never writes a theme (it would leave root-owned files the site
 * cannot update, or write into a tree another user controls).
 */
final class DefaultTheme {

	/**
	 * @param \Closure $installed   fn( string $slug ): bool.
	 * @param \Closure $coreDefault fn(): ?string, the newest installed core default theme.
	 * @param \Closure $download    fn( string $slug ): bool, installs it from wordpress.org.
	 * @param \Closure $stub        fn(): string, writes the blank theme and returns its slug.
	 * @param bool     $mayDownload Whether file changes (and downloads) are allowed.
	 * @param bool     $mayWrite    False when this process is root.
	 */
	public function __construct(
		private \Closure $installed,
		private \Closure $coreDefault,
		private \Closure $download,
		private \Closure $stub,
		private bool $mayDownload,
		private bool $mayWrite
	) {
	}

	/**
	 * @param Site $site Site snapshot.
	 */
	public static function fromWordPress( Site $site ): self {
		return new self(
			static fn( string $slug ): bool => self::usable( wp_get_theme( $slug ) ),
			static function (): ?string {
				$theme = \WP_Theme::get_core_default_theme();
				return false === $theme || ! self::usable( $theme ) ? null : $theme->get_stylesheet();
			},
			static fn( string $slug ): bool => self::install( $slug ),
			static fn(): string => StubTheme::write( $site->themeRoot ),
			wp_is_file_mod_allowed( 'lw_migration_reset' ),
			! RootGuard::current()->isRoot()
		);
	}

	/**
	 * A theme WordPress can show: it exists and has no errors (a missing
	 * parent or template would leave the site blank).
	 *
	 * @param object $theme A WP_Theme.
	 */
	public static function usable( object $theme ): bool {
		return method_exists( $theme, 'exists' ) && method_exists( $theme, 'errors' ) && $theme->exists() && false === $theme->errors();
	}

	/**
	 * @param string $slug Theme stylesheet.
	 * @return bool Whether it is installed and usable now.
	 */
	public function installed( string $slug ): bool {
		return (bool) ( $this->installed )( $slug );
	}

	/**
	 * @return array{slug: string, source: string} Source: installed, download or stub.
	 */
	public function choose(): array {
		if ( ( $this->installed )( WP_DEFAULT_THEME ) ) {
			return [
				'slug'   => WP_DEFAULT_THEME,
				'source' => 'installed',
			];
		}
		$core = ( $this->coreDefault )();
		if ( is_string( $core ) && '' !== $core ) {
			return [
				'slug'   => $core,
				'source' => 'installed',
			];
		}

		return $this->mayDownload ? [
			'slug'   => WP_DEFAULT_THEME,
			'source' => 'download',
		] : [
			'slug'   => StubTheme::SLUG,
			'source' => 'stub',
		];
	}

	/**
	 * @return array{slug: string, source: string, note?: string} A failed download falls back to the stub, with a note why.
	 * @throws JobException When a theme would have to be written as root, or the stub cannot be written.
	 */
	public function ensure(): array {
		$choice = $this->choose();
		if ( 'installed' === $choice['source'] ) {
			return $choice;
		}
		if ( ! $this->mayWrite ) {
			throw new JobException( __( 'The default theme has to be installed, but this command runs as root: run it as the site\'s owner (for example with sudo -u and the web server\'s user).', 'lw-migration' ) );
		}
		if ( 'download' === $choice['source'] && ( $this->download )( $choice['slug'] ) ) {
			return $choice;
		}
		$stub = [
			'slug'   => ( $this->stub )(),
			'source' => 'stub',
		];
		if ( 'download' === $choice['source'] ) {
			$stub['note'] = __( 'The default theme could not be downloaded, so a minimal blank theme was installed instead.', 'lw-migration' );
		}

		return $stub;
	}

	/**
	 * Installs a theme from wordpress.org; any failure is false.
	 *
	 * @param string $slug Theme slug.
	 */
	private static function install( string $slug ): bool {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		ob_start();
		try {
			$api = themes_api(
				'theme_information',
				[
					'slug'   => $slug,
					'fields' => [ 'sections' => false ],
				]
			);
			if ( is_wp_error( $api ) || ! is_object( $api ) || ! is_string( $api->download_link ?? null ) ) {
				return false;
			}

			return true === ( new \Theme_Upgrader( new \Automatic_Upgrader_Skin() ) )->install( $api->download_link );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			ob_end_clean();
		}
	}
}

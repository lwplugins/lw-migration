<?php
/**
 * Which plugins stay active after an import.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Lists follow the established .wpress importer: plugins that lock the admin
 * out after a move, HTTPS-only plugins on an http address, and Slider
 * Revolution versions that break on PHP 7.3+.
 */
final class PluginPolicy {

	/** Plugins that only work over HTTPS. */
	/** Reasons a plugin of the archive stays inactive (PluginMessages words them). */
	public const MISSING = 'missing';

	public const NEEDS_SSL = 'ssl';

	public const LOCKS_OUT = 'lockout';

	public const OUTDATED = 'outdated';

	public const UNKNOWN_VERSION = 'unknown-version';

	public const DUPLICATE = 'duplicate';

	public const SSL_ONLY = [
		'really-simple-ssl/rlrsssl-really-simple-ssl.php',
		'wordpress-https/wordpress-https.php',
		'wp-force-ssl/wp-force-ssl.php',
		'force-https-littlebizzy/force-https.php',
	];

	/** Login-hiding, firewall, network and host-specific plugins that can lock you out after a move. */
	public const LOCKOUT = [
		'invisible-recaptcha/invisible-recaptcha.php',
		'wps-hide-login/wps-hide-login.php',
		'hide-my-wp/index.php',
		'hide-my-wordpress/index.php',
		'mycustomwidget/my_custom_widget.php',
		'lockdown-wp-admin/lockdown-wp-admin.php',
		'rename-wp-login/rename-wp-login.php',
		'wp-simple-firewall/icwp-wpsf.php',
		'join-my-multisite/joinmymultisite.php',
		'multisite-clone-duplicator/multisite-clone-duplicator.php',
		'wordpress-mu-domain-mapping/domain_mapping.php',
		'wordpress-starter/siteground-wizard.php',
		'pro-sites/pro-sites.php',
		'wpide/WPide.php',
		'page-optimize/page-optimize.php',
		'update-services/update-services.php',
	];

	private const OLD_SLIDER = 'revslider/revslider.php';

	private const OLD_SLIDER_BEFORE = '5.4.8.3';

	/**
	 * @param array<int, string>    $packagePlugins Active plugins listed in package.json.
	 * @param array<string, string> $installed      Basename => version, on disk after the files were restored.
	 * @param bool                  $https          The new home URL uses https.
	 * @param string                $own            This plugin's basename (always active).
	 * @return array{active: array<int, string>, removed: array<string, string>} Removed reasons: duplicate, missing, ssl, lockout, unknown-version, outdated.
	 */
	public static function decide( array $packagePlugins, array $installed, bool $https, string $own ): array {
		$ssl     = $https ? [] : self::discover( self::SSL_ONLY, $installed );
		$lockout = self::discover( self::LOCKOUT, $installed );
		$slider  = self::discover( [ self::OLD_SLIDER ], $installed );
		$active  = [ $own ];
		$removed = [];
		foreach ( $packagePlugins as $plugin ) {
			if ( in_array( $plugin, $active, true ) ) {
				continue;
			}
			$reason = self::reason( $plugin, $installed, $own, $ssl, $lockout, $slider );
			if ( null === $reason ) {
				$active[] = $plugin;
			} else {
				$removed[ $plugin ] = $reason;
			}
		}

		return [
			'active'  => $active,
			'removed' => $removed,
		];
	}

	/**
	 * Why a package plugin must stay inactive, or null when it can be activated.
	 *
	 * @param string                $plugin    Package plugin basename.
	 * @param array<string, string> $installed Installed plugins.
	 * @param string                $own       This plugin's basename.
	 * @param array<int, string>    $ssl       Installed HTTPS-only plugins (empty on an https target).
	 * @param array<int, string>    $lockout   Installed lock-out plugins.
	 * @param array<int, string>    $slider    Installed old-slider copies.
	 * @return string|null
	 */
	private static function reason( string $plugin, array $installed, string $own, array $ssl, array $lockout, array $slider ): ?string {
		if ( basename( $plugin ) === basename( $own ) ) {
			return self::DUPLICATE;
		}
		if ( ! isset( $installed[ $plugin ] ) ) {
			return self::MISSING;
		}
		if ( in_array( $plugin, $ssl, true ) ) {
			return self::NEEDS_SSL;
		}
		if ( in_array( $plugin, $lockout, true ) ) {
			return self::LOCKS_OUT;
		}
		if ( in_array( $plugin, $slider, true ) ) {
			if ( 1 !== preg_match( '/^\d+(\.\d+)*/', $installed[ $plugin ] ) ) {
				return self::UNKNOWN_VERSION;
			}
			if ( version_compare( $installed[ $plugin ], self::OLD_SLIDER_BEFORE, '<' ) ) {
				return self::OUTDATED;
			}
		}

		return null;
	}

	/**
	 * Installed basenames matching a list entry: the same basename, or the same
	 * file name in a renamed folder that starts with the listed folder name or
	 * contains it as whole '-'-separated tokens.
	 *
	 * @param array<int, string>    $list      Listed basenames.
	 * @param array<string, string> $installed Installed plugins.
	 * @return array<int, string>
	 */
	private static function discover( array $list, array $installed ): array {
		$found = [];
		foreach ( $list as $listed ) {
			foreach ( array_keys( $installed ) as $plugin ) {
				$plugin = (string) $plugin;
				if ( $plugin === $listed || ( basename( $plugin ) === basename( $listed ) && self::renamedFolder( dirname( $plugin ), dirname( $listed ) ) ) ) {
					$found[] = $plugin;
				}
			}
		}

		return $found;
	}

	/**
	 * The folder starts with the listed folder name, or holds it as whole '-'-separated tokens.
	 *
	 * @param string $folder Installed plugin folder.
	 * @param string $listed Listed plugin folder.
	 * @return bool
	 */
	private static function renamedFolder( string $folder, string $listed ): bool {
		if ( '.' === $listed || '' === $listed ) {
			return false;
		}

		return str_starts_with( $folder, $listed ) || str_contains( '-' . $folder . '-', '-' . $listed . '-' );
	}
}

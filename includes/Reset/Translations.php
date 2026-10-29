<?php
/**
 * Removes the translation files of one plugin or theme.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * The language-pack files of a slug in the languages folder: <slug>-<locale>
 * .po, .mo, .l10n.php and the per-script -<md5>.json files. A link is removed
 * as a link; a folder is never touched. WordPress-free.
 */
final class Translations {

	/**
	 * @param string $langDir The languages folder.
	 * @param string $kind    'plugins' or 'themes'.
	 * @param string $slug    Plugin folder (without .php for a single file) or theme stylesheet.
	 * @return int Files removed.
	 */
	public static function remove( string $langDir, string $kind, string $slug ): int {
		$dir = rtrim( $langDir, '/' ) . '/' . $kind;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing or unreadable folder removes nothing; a warning would carry the path.
		$names = '' === $slug ? false : @scandir( $dir );
		if ( false === $names ) {
			return 0;
		}
		$pattern = '/^' . preg_quote( $slug, '/' ) . '-[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?(?:\.po|\.mo|\.l10n\.php|-[a-f0-9]{32}\.json)\z/';
		$removed = 0;
		foreach ( $names as $name ) {
			$path = $dir . '/' . $name;
			clearstatcache( true, $path );
			if ( 1 !== preg_match( $pattern, $name ) || ( is_dir( $path ) && ! is_link( $path ) ) ) {
				continue;
			}
			if ( Files::removeFile( $path ) ) {
				++$removed;
			}
		}

		return $removed;
	}
}

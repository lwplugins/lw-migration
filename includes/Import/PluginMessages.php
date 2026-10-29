<?php
/**
 * What the user is told about archive plugins an import leaves inactive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

/**
 * One line per PluginPolicy reason; an unknown reason still gets a general
 * line, so no plugin is left inactive without a word.
 */
final class PluginMessages {

	/**
	 * @param string $plugin Plugin basename.
	 * @param string $reason PluginPolicy reason.
	 */
	public static function removed( string $plugin, string $reason ): string {
		switch ( $reason ) {
			case PluginPolicy::MISSING:
				/* translators: %s: plugin file */
				$text = __( '%s stays inactive: it is not installed on this site.', 'lw-migration' );
				break;
			case PluginPolicy::NEEDS_SSL:
				/* translators: %s: plugin file */
				$text = __( '%s stays inactive: it needs HTTPS, and this site\'s address is http.', 'lw-migration' );
				break;
			case PluginPolicy::LOCKS_OUT:
				/* translators: %s: plugin file */
				$text = __( '%s stays inactive: it could lock you out after a move. Turn it on again if you need it.', 'lw-migration' );
				break;
			case PluginPolicy::OUTDATED:
				/* translators: %s: plugin file */
				$text = __( '%s stays inactive: this version does not work on current PHP versions.', 'lw-migration' );
				break;
			case PluginPolicy::UNKNOWN_VERSION:
				/* translators: %s: plugin file */
				$text = __( '%s stays inactive: its version could not be read, and old versions do not work on current PHP versions.', 'lw-migration' );
				break;
			case PluginPolicy::DUPLICATE:
				/* translators: %s: plugin file */
				$text = __( '%s stays inactive: it is a second copy of this migration plugin.', 'lw-migration' );
				break;
			default:
				/* translators: %s: plugin file */
				$text = __( '%s stays inactive.', 'lw-migration' );
		}

		return sprintf( $text, $plugin );
	}
}

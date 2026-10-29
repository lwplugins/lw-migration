<?php
/**
 * One line on a subsite where the plugin is active but not network-active.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * On a network the plugin works only from Network Admin, on the main site
 * (decision 3); activated on a subsite alone it does nothing there. The
 * subsite's Plugins screen says so once, to whoever can activate plugins.
 */
final class SubsiteNotice {

	/**
	 * Hooked by AdminPage::register() on a subsite only.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) || is_plugin_active_for_network( plugin_basename( LW_MIGRATION_FILE ) ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning lw-notice"><p>%s</p></div>',
			esc_html__( 'LW Migration does nothing on this site: on a network it works only when it is network-activated, from Network Admin.', 'lw-migration' )
		);
	}
}

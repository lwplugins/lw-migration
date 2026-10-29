<?php
/**
 * Hook wiring.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's hooks on plugins_loaded. Nothing heavy runs here:
 * jobs only start from an explicit command. REST routes are registered on
 * rest_api_init (every request may be a REST request); the admin page and
 * the notice isolation of the LW Plugins screens on admin requests only.
 */
final class Plugin {

	public static function boot(): void {
		add_action( 'init', [ self::class, 'loadTextdomain' ] );
		// Every request: the hub negotiates the shared "LW Plugins" page and its REST routes.
		Admin\Hub\Hub::init( LW_MIGRATION_FILE );
		Maintenance\Cleanup::register();
		Schedules\Events::register();
		add_action( 'rest_api_init', [ Rest\RouteMap::class, 'register' ] );
		if ( is_admin() ) {
			Admin\NoticeManager::register();
			Admin\AdminPage::register();
			Admin\ConnectRedirect::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI\Commands::register();
		}
	}

	public static function loadTextdomain(): void {
		load_plugin_textdomain( 'lw-migration', false, dirname( plugin_basename( LW_MIGRATION_FILE ) ) . '/languages' );
	}
}

<?php
/**
 * The main-site context of a network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 3: our options (lock, storage folder, schedules, run registry)
 * live in the main site's options table, so on a network every migration
 * request, cron run and command runs on the main site.
 */
final class MainSite {

	/**
	 * The main site of the main network (a single site always is). It follows
	 * the current blog, so callers check it before any switch_to_blog().
	 */
	public static function isContext(): bool {
		return ! is_multisite() || is_main_site( null, get_main_network_id() );
	}

	public static function cliMessage(): string {
		return __( 'Run this command on the network\'s main site: leave out --url, or give the main site\'s address.', 'lw-migration' );
	}

	public static function restError(): \WP_Error {
		return new \WP_Error( 'lw_migration_main_site', __( 'Use the network\'s main site address for migrations.', 'lw-migration' ), [ 'status' => 403 ] );
	}
}

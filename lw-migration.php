<?php
/**
 * Plugin Name:       LW Migration
 * Plugin URI:        https://github.com/lwplugins/lw-migration
 * Description:       Lightweight backup, restore and migration for WordPress, compatible with .wpress archives.
 * Version:           1.0.2
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            LW Plugins
 * Author URI:        https://lwplugins.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lw-migration
 * Domain Path:       /languages
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LW_MIGRATION_VERSION', '1.0.2' );
define( 'LW_MIGRATION_FILE', __FILE__ );
define( 'LW_MIGRATION_PATH', plugin_dir_path( __FILE__ ) );
define( 'LW_MIGRATION_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( LW_MIGRATION_PATH . 'vendor/autoload.php' ) ) {
	require_once LW_MIGRATION_PATH . 'vendor/autoload.php';
} else {
	require_once LW_MIGRATION_PATH . 'includes/Autoloader.php';
	Autoloader::register();
}

// Archive offsets and CRC arithmetic need 64-bit integers: tell the admin instead of failing silently.
if ( PHP_INT_SIZE < 8 ) {
	PlatformNotice::register();
	return;
}

register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Activator::class, 'deactivate' ] );
add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );

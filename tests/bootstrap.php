<?php
/**
 * PHPUnit bootstrap: no WordPress; Brain Monkey stubs WordPress functions per test.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Constants WordPress or the plugin bootstrap would define; unit tests load neither.
$lw_migration_test_constants = [
	'MINUTE_IN_SECONDS'    => 60,
	'HOUR_IN_SECONDS'      => 3600,
	'DAY_IN_SECONDS'       => 86400,
	'WP_CONTENT_DIR'       => sys_get_temp_dir() . '/lwm-unit-wp-content',
	'LW_MIGRATION_FILE'    => dirname( __DIR__ ) . '/lw-migration.php',
	'LW_MIGRATION_VERSION' => '1.0.0',
	'LW_MIGRATION_PATH'    => dirname( __DIR__ ) . '/',
	'LW_MIGRATION_URL'     => 'https://site.test/wp-content/plugins/lw-migration/',
	'WP_DEFAULT_THEME'     => 'twentytwentyfive',
];
foreach ( $lw_migration_test_constants as $lw_migration_name => $lw_migration_value ) {
	if ( ! defined( $lw_migration_name ) ) {
		define( $lw_migration_name, $lw_migration_value );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

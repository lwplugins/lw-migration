<?php
/**
 * Plugin Name: LWM E2E backup folder probe (test only)
 *
 * End-to-end tests only (BackupDirE2ETest copies it into site a's mu-plugins).
 * With the option lwm_e2e_move_copies = 1 a move copies every backup instead
 * of hard-linking it (the copy, verify and reservation path). With the option
 * lwm_e2e_slow_move = 1 a web request waits 0.3 s at every read of the
 * backups folder setting (each step of a move reads it) and works one second
 * per tick, so a browser tick moves at most one backup and a move can be
 * stopped half way, however fast the machine is. WP-CLI is never slowed.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$lwm_e2e_on = static fn( string $name ): bool => '1' === (string) get_option( $name, '' );

add_filter( 'lw_migration_move_links', static fn( $links ) => $lwm_e2e_on( 'lwm_e2e_move_copies' ) ? false : $links );
add_filter( 'lw_migration_web_tick_seconds', static fn( $seconds ) => $lwm_e2e_on( 'lwm_e2e_slow_move' ) ? 1.0 : $seconds );
add_filter(
	'query',
	static function ( $query ) use ( $lwm_e2e_on ) {
		if ( is_string( $query ) && str_contains( $query, 'lw_migration_backup_dir' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) && $lwm_e2e_on( 'lwm_e2e_slow_move' ) ) {
			usleep( 300000 );
		}
		return $query;
	}
);

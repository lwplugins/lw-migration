<?php
/**
 * Uninstall: removes the temporary import tables of this site's own runs,
 * settings, schedules and their run logs, transfer links, site connections
 * (links, pairing code, replay cache), cloud destinations (profiles, sealed
 * secrets, upload records), the backup size estimate, the cached backup
 * contents, scheduled events, transients and job folders (the estimate's
 * work files are in jobs/).
 * Backups are kept: deleting the plugin must not delete the site's backups,
 * local or on a cloud destination (no network call from here). A backups
 * folder chosen in Settings is never touched: not its backups, not its marker
 * or deny files. On a network all of that lives on the main site (of the
 * main network), so an uninstall that runs elsewhere switches there; then a
 * cleanup or schedule event left in another site's cron is cleared too.
 *
 * WordPress does not load the plugin for this file: the autoloader is loaded
 * here only for the table removal; option names stay literal (UninstallTest
 * pins them to the Options constants).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$lw_migration_network  = is_multisite();
$lw_migration_main     = $lw_migration_network ? (int) get_main_site_id( get_main_network_id() ) : 0;
$lw_migration_switched = $lw_migration_network && ! is_main_site( null, get_main_network_id() );
if ( $lw_migration_switched ) {
	switch_to_blog( $lw_migration_main );
}
try {
	$lw_migration_dir = get_option( 'lw_migration_storage_dir' );

	/*
	 * Before the run registry (lw_migration_tmp_runs) goes: every table of the import
	 * runs this site registered, the replaced ones included; never another site's in a
	 * shared database. While an import still holds the site lock nothing is dropped, and
	 * a run with a table that could not be dropped stays registered; in both cases the
	 * registry row stays, so the daily sweep of a later install can finish it.
	 */
	$lw_migration_keep_runs = false;
	if ( PHP_INT_SIZE >= 8 ) {
		if ( is_file( __DIR__ . '/vendor/autoload.php' ) ) {
			require_once __DIR__ . '/vendor/autoload.php';
		} else {
			require_once __DIR__ . '/includes/Autoloader.php';
			\LightweightPlugins\Migration\Autoloader::register();
		}
		try {
			$lw_migration_registry  = new \LightweightPlugins\Migration\WordPress\TmpRunRegistry();
			$lw_migration_dropped   = \LightweightPlugins\Migration\Maintenance\TableOwners::dropForUninstall(
				\LightweightPlugins\Migration\WordPress\WpConnection::fromWpdb(),
				new \LightweightPlugins\Migration\WordPress\SiteLock(),
				$lw_migration_registry,
				(string) $wpdb->base_prefix
			);
			$lw_migration_keep_runs = null === $lw_migration_dropped || [] !== $lw_migration_registry->ids(); // Only fully removed runs are forgotten.
		} catch ( \Throwable $lw_migration_error ) {
			unset( $lw_migration_error ); // No usable database link from here: nothing can be dropped.
		}
	}

	// The run log of every stored schedule (the LIKE cleanup below catches any left over).
	$lw_migration_schedules = get_option( 'lw_migration_schedules', [] );
	foreach ( is_array( $lw_migration_schedules ) ? $lw_migration_schedules : [] as $lw_migration_schedule ) {
		if ( is_array( $lw_migration_schedule ) && is_string( $lw_migration_schedule['id'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{8}\z/', $lw_migration_schedule['id'] ) ) {
			delete_option( 'lw_migration_schedule_log_' . $lw_migration_schedule['id'] );
		}
	}

	// delete_option() reads the row from the table and drops its cache entries, autoloaded or not.
	foreach ( [ 'lw_migration_storage_dir', 'lw_migration_lock', 'lw_migration_backup_labels', 'lw_migration_retention', 'lw_migration_tmp_runs', 'lw_migration_schedules', 'lw_migration_schedule_queue', 'lw_migration_loopback', 'lw_migration_notifications', 'lw_migration_backup_dir', 'lw_migration_shares', 'lw_migration_links', 'lw_migration_link_code', 'lw_migration_link_nonces', 'lw_migration_reset_tries', 'lw_migration_destinations', 'lw_migration_destination_secrets', 'lw_migration_cloud_copies', 'lw_migration_export_estimate', 'lw_migration_backup_contents' ] as $lw_migration_option ) {
		if ( ! $lw_migration_keep_runs || 'lw_migration_tmp_runs' !== $lw_migration_option ) {
			delete_option( $lw_migration_option );
		}
	}
	wp_clear_scheduled_hook( 'lw_migration_cleanup' );

	// Every event of every schedule (each carries its schedule id as argument) and the continuation event.
	wp_unschedule_hook( 'lw_migration_schedule' );
	wp_unschedule_hook( 'lw_migration_schedule_continue' );

	/*
	 * With a persistent object cache, transients live in the cache, not in this table, so
	 * this delete cannot reach them; they expire with their own lifetime. The run logs of
	 * the stored schedules went through delete_option() above (cache included); the log
	 * prefix here only catches rows of schedules no longer in the list.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time removal of this plugin's transients and run logs by prefix.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_lw_migration_' ) . '%', $wpdb->esc_like( '_transient_timeout_lw_migration_' ) . '%', $wpdb->esc_like( 'lw_migration_schedule_log_' ) . '%' ) );

	/*
	 * Removes a file or folder tree without following symlinks. A closure, not a named
	 * function, so the file can run more than once in one process without a redeclare.
	 */
	$lw_migration_remove = static function ( string $path ) use ( &$lw_migration_remove ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$lw_migration_items = scandir( $path );
		if ( false === $lw_migration_items ) {
			return;
		}
		foreach ( $lw_migration_items as $lw_migration_item ) {
			if ( '.' !== $lw_migration_item && '..' !== $lw_migration_item ) {
				$lw_migration_remove( $path . '/' . $lw_migration_item );
			}
		}
		rmdir( $path );
	};

	// Only our own folder: a valid name and a real directory, never a link to somewhere else.
	if ( is_string( $lw_migration_dir ) && 1 === preg_match( '/^lw-migration-[a-f0-9]{12}\z/', $lw_migration_dir ) && ! is_link( WP_CONTENT_DIR . '/' . $lw_migration_dir ) ) {
		$lw_migration_remove( WP_CONTENT_DIR . '/' . $lw_migration_dir . '/jobs' );
	}
} finally {
	if ( $lw_migration_switched ) {
		restore_current_blog();
	}
}

/*
 * The other sites of a network never hold our options; only a cron event can be
 * left there (the daily cleanup was planned on every site before network support).
 * Clearing writes nothing where there is no event. Pages of 100 sites, with the
 * runtime cache let go after each page so a large network does not fill memory.
 */
if ( $lw_migration_network ) {
	for ( $lw_migration_offset = 0; ; $lw_migration_offset += 100 ) {
		$lw_migration_ids = get_sites(
			[
				'fields'       => 'ids',
				'number'       => 100,
				'offset'       => $lw_migration_offset,
				'site__not_in' => [ $lw_migration_main ],
				'orderby'      => 'id',
				'order'        => 'ASC',
			]
		);
		foreach ( $lw_migration_ids as $lw_migration_id ) {
			switch_to_blog( (int) $lw_migration_id );
			try {
				wp_clear_scheduled_hook( 'lw_migration_cleanup' );
				wp_unschedule_hook( 'lw_migration_schedule' );
				wp_unschedule_hook( 'lw_migration_schedule_continue' );
			} finally {
				restore_current_blog();
			}
		}
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		if ( count( $lw_migration_ids ) < 100 ) {
			break;
		}
	}
}

<?php
/**
 * Fixes after the imported database went live.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\PostImport;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Each task is idempotent (a tick may die between tasks). Follows the
 * established .wpress importer's "done" fixes, plus the object cache flush.
 * They run only inside a confirmed import, on the database just imported;
 * every setting they change on the user's behalf is reported as a warning
 * (caches and generated files are rebuilt by their plugins and are not).
 * Plugins left inactive are reported by the preserve step, not here.
 *
 * Known gap: a fix writes the setting first and adds its warning after; when
 * the request dies in between, the replay finds the setting already changed
 * and adds no warning (Jetpack, WooCommerce, Freemius). The window is a few
 * statements wide; the change itself is never repeated or lost.
 */
final class Fixes {

	public const TASKS = [ 'flushCaches', 'elementor', 'builderCaches', 'jetpack', 'woocommerce', 'dbVersion', 'freemius', 'rewrite' ];

	public const NO_DATABASE_TASKS = [ 'flushCaches', 'rewrite' ];

	private const ELEMENTOR_META = [ '_elementor_css', '_elementor_element_cache', '_elementor_page_assets' ];

	private const ELEMENTOR_OPTIONS = [ '_elementor_global_css', '_elementor_assets_data', 'elementor-custom-breakpoints-files' ];

	/** Jetpack modules bound to the old address (image CDN, WordPress.com login). */
	private const JETPACK_MOVED_MODULES = [ 'photon', 'sso' ];

	private const FREEMIUS_OPTIONS = [ 'fs_accounts', 'fs_dbg_accounts', 'fs_active_plugins', 'fs_api_cache', 'fs_dbg_api_cache', 'fs_debug_mode' ];

	/** Page-builder cache folders under uploads (generated CSS with the old addresses). */
	private const BUILDER_CACHE_DIRS = [ 'elementor/css', 'bb-plugin/cache' ];

	public static function flushCaches(): void {
		wp_cache_flush();
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * @param string $task One of TASKS.
	 * @param Job    $job  Import job.
	 * @return void
	 * @throws ImportException For an unknown task.
	 */
	public function run( string $task, Job $job ): void {
		switch ( $task ) {
			case 'flushCaches':
				self::flushCaches();
				return;
			case 'elementor':
				$this->elementor();
				return;
			case 'builderCaches':
				self::removeBuilderCaches( ImportState::site( $job )->uploadsDir );
				return;
			case 'jetpack':
				$this->jetpack( $job );
				return;
			case 'woocommerce':
				$this->woocommerceFor( $job, self::https( $job ) );
				return;
			case 'dbVersion':
				if ( ! get_option( 'db_version' ) ) {
					update_option( 'db_version', get_option( 'initial_db_version' ) );
				}
				return;
			case 'freemius':
				$this->freemius( $job );
				return;
			case 'rewrite':
				$this->rewrite( $job );
				return;
		}
		/* translators: %s: internal task name */
		throw new ImportException( sprintf( __( 'Unknown post-import task: %s', 'lw-migration' ), $task ) );
	}

	/**
	 * The per-site fixes on a network (decision 21), with the blog's own
	 * address and uploads folder; the caller has switched to the blog.
	 *
	 * @param string $task       elementor, builderCaches, jetpack, woocommerce or dbVersion.
	 * @param Job    $job        Import job.
	 * @param string $homeUrl    The blog's home URL.
	 * @param string $uploadsDir The blog's uploads folder.
	 * @throws ImportException For any other task.
	 */
	public function runOnBlog( string $task, Job $job, string $homeUrl, string $uploadsDir ): void {
		switch ( $task ) {
			case 'builderCaches':
				self::removeBuilderCaches( $uploadsDir );
				return;
			case 'woocommerce':
				$https = 'https' === strtolower( (string) parse_url( $homeUrl, PHP_URL_SCHEME ) );
				$this->woocommerceFor( $job, $https );
				return;
			case 'elementor':
			case 'jetpack':
			case 'dbVersion':
				$this->run( $task, $job );
				return;
		}
		/* translators: %s: internal task name */
		throw new ImportException( sprintf( __( 'Unknown post-import task: %s', 'lw-migration' ), $task ) );
	}

	private static function removeBuilderCaches( string $uploadsDir ): void {
		foreach ( self::BUILDER_CACHE_DIRS as $dir ) {
			Files::removeTree( $uploadsDir . '/' . $dir );
		}
	}

	private function elementor(): void {
		foreach ( self::ELEMENTOR_META as $key ) {
			delete_post_meta_by_key( $key );
		}
		foreach ( self::ELEMENTOR_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	private function jetpack( Job $job ): void {
		$modules = get_option( 'jetpack_active_modules' );
		if ( ! is_array( $modules ) ) {
			return;
		}
		$removed = array_values( array_intersect( self::JETPACK_MOVED_MODULES, $modules ) );
		if ( [] === $removed ) {
			return;
		}
		update_option( 'jetpack_active_modules', array_values( array_diff( $modules, self::JETPACK_MOVED_MODULES ) ) );
		/* translators: %s: Jetpack module names, comma separated */
		$job->addWarning( sprintf( __( 'Switched off Jetpack modules that are bound to the old address: %s. Turn them on again in Jetpack if you need them.', 'lw-migration' ), implode( ', ', $removed ) ) );
	}

	/**
	 * @param Job  $job   Import job.
	 * @param bool $https The site's address is https.
	 */
	private function woocommerceFor( Job $job, bool $https ): void {
		if ( $https || 'yes' !== get_option( 'woocommerce_force_ssl_checkout' ) ) {
			return;
		}
		update_option( 'woocommerce_force_ssl_checkout', 'no' );
		$job->addWarning( __( 'Turned off the WooCommerce setting that forces a secure checkout: this site\'s address is http.', 'lw-migration' ) );
	}

	/**
	 * A multisite sub-site archive carries the network's Freemius accounts in
	 * <prefix>sitemeta; merge them into this site's option (licences survive).
	 * Without account data there, the Freemius options are reset.
	 *
	 * @param Job $job Import job.
	 */
	private function freemius( Job $job ): void {
		global $wpdb;
		if ( is_multisite() ) {
			return;
		}
		$table = $wpdb->base_prefix . 'sitemeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time check right after an import.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->base_prefix; one-time read.
		$raw = $wpdb->get_var( "SELECT meta_value FROM `{$table}` WHERE meta_key = 'fs_accounts' LIMIT 1" );
		if ( ! is_string( $raw ) ) {
			return;
		}
		$network = self::decode( $raw );
		if ( ! is_array( $network ) ) {
			return; // Unreadable network value: nothing is merged or reset.
		}
		$current = get_option( 'fs_accounts', [] );
		$merged  = array_merge( is_array( $current ) ? $current : [], $network );
		if ( [] === $merged ) {
			return;
		}
		if ( isset( $merged['users'], $merged['sites'] ) ) {
			update_option( 'fs_accounts', $merged );
			return;
		}
		$deleted = false;
		foreach ( self::FREEMIUS_OPTIONS as $option ) {
			$deleted = delete_option( $option ) || $deleted;
		}
		if ( $deleted ) {
			$job->addWarning( __( 'Reset the licence data of plugins that use Freemius: it could not be carried over from the archive. Activate those licences again.', 'lw-migration' ) );
		}
	}

	/**
	 * The only unserialize() of imported data: no class is instantiated.
	 *
	 * @param string $raw Serialized value from the imported database.
	 * @return mixed
	 */
	private static function decode( string $raw ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- imported data: allowed_classes=false creates no objects; a broken value is ignored.
		return @unserialize( $raw, [ 'allowed_classes' => false ] );
	}

	private function rewrite( Job $job ): void {
		delete_option( 'rewrite_rules' );
		$rewrite = $GLOBALS['wp_rewrite'] ?? null;
		if ( ! is_object( $rewrite ) || ! method_exists( $rewrite, 'init' ) || ! method_exists( $rewrite, 'using_permalinks' ) || ! method_exists( $rewrite, 'set_permalink_structure' ) ) {
			return;
		}
		$rewrite->init();
		if ( ! empty( $job->state['usingPermalinks'] ) || ! $rewrite->using_permalinks() || empty( $GLOBALS['is_apache'] ) || apache_mod_loaded( 'mod_rewrite', false ) ) {
			return;
		}
		$rewrite->set_permalink_structure( '' );
		$job->addWarning( __( 'Pretty permalinks were switched off: this server cannot rewrite addresses (mod_rewrite is missing).', 'lw-migration' ) );
	}

	private static function https( Job $job ): bool {
		return 'https' === strtolower( (string) parse_url( ImportState::site( $job )->homeUrl, PHP_URL_SCHEME ) );
	}
}

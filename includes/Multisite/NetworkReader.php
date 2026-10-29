<?php
/**
 * Reads the sites of the current network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the network; never writes. Blog ids come from wp_blogs, one site's
 * settings are read inside switch_to_blog() and the blog is always restored.
 */
final class NetworkReader {

	public function count(): int {
		$db = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb; no value in the query.
		return (int) $db->get_var( "SELECT COUNT(*) FROM {$db->blogs}" );
	}

	/**
	 * @param int $afterId Last id of the previous page (0 for the first).
	 * @param int $limit   Page size.
	 * @return array<int, int> Blog ids above $afterId, ascending.
	 */
	public function ids( int $afterId, int $limit ): array {
		$db = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $db->get_col( $db->prepare( "SELECT blog_id FROM {$db->blogs} WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d", $afterId, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @param array<int, mixed> $ids Requested blog ids.
	 * @return array<int, int> The whole positive ids that exist in the main network, in the given order, each once.
	 */
	public function existing( array $ids ): array {
		$wanted = array_values( array_unique( array_filter( $ids, static fn( $id ): bool => is_int( $id ) && $id > 0 ) ) );
		if ( [] === $wanted ) {
			return [];
		}
		$db    = $this->db();
		$marks = implode( ', ', array_fill( 0, count( $wanted ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $db->get_col( $db->prepare( "SELECT blog_id FROM {$db->blogs} WHERE blog_id IN ({$marks}) AND site_id = %d", ...array_merge( $wanted, [ get_main_network_id() ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from $wpdb; one %d per id, then the network id.
		$known = array_map( 'intval', (array) $found );

		return array_values( array_filter( $wanted, static fn( int $id ): bool => in_array( $id, $known, true ) ) );
	}

	/**
	 * @param int    $blogId    Site to read.
	 * @param string $ownPlugin This plugin's basename (left out of the site's plugins).
	 * @return SiteEntry
	 * @throws \RuntimeException When the site does not exist.
	 */
	public function entry( int $blogId, string $ownPlugin ): SiteEntry {
		$site = get_site( $blogId );
		if ( null === $site ) {
			throw new \RuntimeException( sprintf( 'Site %d does not exist.', $blogId ) );
		}
		switch_to_blog( $blogId );
		try {
			$uploads = wp_upload_dir( null, false );
			return new SiteEntry(
				$blogId,
				(int) $site->site_id,
				(int) $site->lang_id,
				(string) get_site_url( $blogId ),
				(string) get_home_url( $blogId ),
				(string) $site->domain,
				(string) $site->path,
				array_values( array_diff( self::strings( get_option( 'active_plugins', [] ) ), [ $ownPlugin ] ) ),
				(string) get_option( 'template' ),
				(string) get_option( 'stylesheet' ),
				self::asStored( get_option( 'upload_path' ) ),
				self::asStored( get_option( 'upload_url_path' ) ),
				untrailingslashit( (string) $uploads['basedir'] ),
				trailingslashit( (string) $uploads['baseurl'] )
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Lets go of what WordPress cached in memory for the sites read so far
	 * (switch_to_blog() leaves each site's options in the runtime cache, and a
	 * WP-CLI run keeps one process for every slice). Only where the object
	 * cache can flush its runtime part alone: a persistent cache is never emptied.
	 */
	public function releaseMemory(): void {
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * @return array<int, array{SiteID: int, Domain: string, Path: string}>
	 */
	public function networks(): array {
		$rows = [];
		foreach ( (array) get_networks(
			[
				'number'  => 0,
				'orderby' => 'id',
				'order'   => 'ASC',
			]
		) as $network ) {
			$rows[] = [
				'SiteID' => (int) $network->id,
				'Domain' => (string) $network->domain,
				'Path'   => (string) $network->path,
			];
		}

		return $rows;
	}

	/**
	 * @param string $ownPlugin This plugin's basename.
	 * @return array<int, string> Network-active plugins without this one.
	 */
	public function sitewidePlugins( string $ownPlugin ): array {
		$active = get_site_option( 'active_sitewide_plugins', [] );
		$names  = self::strings( is_array( $active ) ? array_keys( $active ) : [] );

		return array_values( array_diff( $names, [ $ownPlugin ] ) );
	}

	/**
	 * @return array<int, string> Super admin logins.
	 */
	public function superAdmins(): array {
		return self::strings( get_super_admins() );
	}

	/**
	 * Sites of the main network only (the one this plugin works in).
	 *
	 * @param int    $page    1-based page.
	 * @param int    $perPage Sites per page.
	 * @param string $search  Search text ('' for all).
	 * @return array{items: array<int, array{id: int, url: string, title: string}>, total: int}
	 */
	public function page( int $page, int $perPage, string $search ): array {
		$perPage = max( 1, $perPage );
		$network = get_main_network_id();
		$sites   = get_sites(
			[
				'network_id' => $network,
				'number'     => $perPage,
				'offset'     => ( max( 1, $page ) - 1 ) * $perPage,
				'search'     => $search,
				'orderby'    => 'id',
				'order'      => 'ASC',
			]
		);
		$items   = [];
		foreach ( (array) $sites as $site ) {
			$id      = (int) $site->blog_id;
			$items[] = [
				'id'    => $id,
				'url'   => (string) get_home_url( $id ),
				'title' => (string) $site->blogname,
			];
		}
		$total = get_sites(
			[
				'network_id' => $network,
				'search'     => $search,
				'count'      => true,
			]
		);

		return [
			'items' => $items,
			'total' => (int) $total,
		];
	}

	/**
	 * @param mixed $value Option value as get_option() returned it.
	 * @return string|bool Strings and booleans as they are, anything else ''.
	 */
	private static function asStored( $value ): string|bool {
		return is_string( $value ) || is_bool( $value ) ? $value : '';
	}

	/**
	 * @param mixed $value A list.
	 * @return array<int, string>
	 */
	private static function strings( $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * @return \wpdb
	 */
	private function db() {
		return $GLOBALS['wpdb'];
	}
}

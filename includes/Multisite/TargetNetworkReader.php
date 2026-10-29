<?php
/**
 * Reads the network an import restores into.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only (decision 9). Called in the main-site context: the uploads
 * folder read here is the main site's.
 */
final class TargetNetworkReader {

	/** Names WordPress routes itself, added to its reserved list (decision 12). */
	private const CORE_NAMES = [ 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'files' ];

	public static function read(): TargetNetwork {
		$mainId  = (int) get_main_site_id();
		$main    = get_site( $mainId );
		$home    = (string) get_home_url( $mainId );
		$domain  = strtolower( null === $main ? '' : (string) $main->domain );
		$port    = null;
		$matches = [];
		if ( 1 === preg_match( '/^(.*):(\d+)\z/', $domain, $matches ) ) {
			[ , $domain, $port ] = $matches;
		}
		$scheme = parse_url( $home, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of the site's own address.
		$up     = wp_upload_dir( null, false );

		return new TargetNetwork(
			(bool) is_subdomain_install(),
			$domain,
			null === $main ? '/' : (string) $main->path,
			'https' === $scheme ? 'https' : 'http',
			null === $port ? null : (int) $port, // Only wp_blogs.domain decides: the sites are stored with it (review M3).
			(int) get_current_network_id(),
			(int) get_networks( [ 'count' => true ] ),
			defined( 'UPLOADBLOGSDIR' ) && (bool) get_site_option( 'ms_files_rewriting' ),
			untrailingslashit( (string) $up['basedir'] ),
			untrailingslashit( (string) $up['baseurl'] ),
			(string) $GLOBALS['wpdb']->base_prefix,
			$mainId,
			self::reserved()
		);
	}

	/**
	 * The network's sites a network archive does not contain (decision 18: they are listed, never touched).
	 *
	 * @param array<int, int|null> $archiveIds Blog ids of the archive.
	 * @return array<int, array{id: int, url: string}>
	 */
	public static function unlisted( array $archiveIds ): array {
		$ids  = array_map(
			'intval',
			(array) get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			)
		);
		$list = [];
		foreach ( array_diff( $ids, array_filter( $archiveIds, 'is_int' ) ) as $id ) {
			$list[] = [
				'id'  => $id,
				'url' => (string) get_home_url( $id ),
			];
		}

		return $list;
	}

	/**
	 * @param string $domain    Domain as wp_blogs holds it (with the port).
	 * @param string $path      Path with slashes on both ends.
	 * @param int    $networkId Network id.
	 * @return int|null Id of the site at that address.
	 */
	public static function siteAt( string $domain, string $path, int $networkId ): ?int {
		$id = domain_exists( $domain, $path, $networkId );

		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	/**
	 * @return array<int, string>
	 */
	private static function reserved(): array {
		$names = array_filter( (array) get_subdirectory_reserved_names(), 'is_string' );

		return array_values( array_unique( array_merge( array_map( 'strtolower', $names ), self::CORE_NAMES ) ) );
	}
}

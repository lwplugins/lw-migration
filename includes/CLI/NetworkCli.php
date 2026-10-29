<?php
/**
 * WP-CLI on a multisite network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Multisite\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * Every `wp lw-migration …` command runs in the main site's context (decision
 * 3): the guard runs before each one (WP-CLI's before_invoke hook).
 */
final class NetworkCli {

	/**
	 * Stops the command outside the main site of the main network.
	 */
	public static function guard(): void {
		if ( ! MainSite::isContext() ) {
			Console::error( MainSite::cliMessage() );
		}
	}

	/**
	 * --site-url=<url>[,<url>...]: the new addresses of an archive's sites, in archive order.
	 *
	 * @param array<string, mixed> $assoc Flags.
	 * @return array<int, string> Addresses; none when the flag is not given.
	 * @throws \InvalidArgumentException For a bare flag or an empty address.
	 */
	public static function siteUrls( array $assoc ): array {
		if ( ! array_key_exists( 'site-url', $assoc ) ) {
			return [];
		}
		$urls = is_string( $assoc['site-url'] ) ? array_map( 'trim', explode( ',', $assoc['site-url'] ) ) : [];
		if ( [] === $urls || in_array( '', $urls, true ) ) {
			throw new \InvalidArgumentException( __( 'Give the new addresses: --site-url=<url>[,<url>...]', 'lw-migration' ) );
		}

		return $urls;
	}
}

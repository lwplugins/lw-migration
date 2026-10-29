<?php
/**
 * Writes multisite.json.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * The reference layout (decision 5), plain json_encode like package.json.
 */
final class MultisiteJson {

	/**
	 * @param bool                             $network  Whole network (true) or chosen sites.
	 * @param array<int, array<string, mixed>> $networks {SiteID, Domain, Path} rows.
	 * @param array<int, SiteEntry>            $sites    Exported sites, by blog id.
	 * @param string                           $version  This plugin's version.
	 * @param array<int, string>               $plugins  Network-active plugins.
	 * @param array<int, string>               $admins   Super admin logins.
	 * @return string
	 * @throws \RuntimeException When a value is not valid UTF-8.
	 */
	public static function encode( bool $network, array $networks, array $sites, string $version, array $plugins, array $admins ): string {
		$json = json_encode(
			[
				'Network'  => $network,
				'Networks' => array_values( $networks ),
				'Sites'    => array_map( static fn( SiteEntry $s ): array => $s->toArray(), array_values( $sites ) ),
				'Plugin'   => [ 'Version' => $version ],
				'Plugins'  => array_values( $plugins ),
				'Admins'   => array_values( $admins ),
			]
		);
		if ( false === $json ) {
			throw new \RuntimeException( 'Cannot encode multisite.json.' );
		}
		return $json;
	}
}

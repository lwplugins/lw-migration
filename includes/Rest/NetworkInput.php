<?php
/**
 * Checks the network values the browser sends.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Multisite\NetworkReader;

defined( 'ABSPATH' ) || exit;

/**
 * Exact JSON types, like JobInput: the chosen sites of a backup (whole
 * positive blog ids, each once, every one a site of this network) and the new
 * addresses of an archive's sites in a confirmation (index => address). A
 * value is never dropped: an id left out would widen the choice, since no
 * chosen site means the whole network. Every refusal is a RestException with
 * the field `sites`.
 */
final class NetworkInput {

	public const MAX_SITES = 1000;

	public const MAX_ADDRESS = 2000;

	/**
	 * @param mixed $raw The `sites` list of an export request.
	 * @return array<int, int> The ids as given ([] = the whole network).
	 * @throws RestException On anything but a list of distinct positive ids of
	 *                       existing sites (and on any id on a single site).
	 */
	public static function siteIds( $raw ): array {
		if ( ! is_array( $raw ) || array_keys( $raw ) !== array_keys( array_values( $raw ) ) || count( $raw ) > self::MAX_SITES ) {
			throw RestException::invalidField( 'sites' );
		}
		foreach ( $raw as $id ) {
			if ( ! is_int( $id ) || $id <= 0 ) {
				throw RestException::invalidField( 'sites' );
			}
		}
		if ( count( array_unique( $raw ) ) !== count( $raw ) ) {
			throw RestException::invalidField( 'sites' );
		}
		if ( [] === $raw ) {
			return [];
		}
		if ( ! is_multisite() ) {
			throw RestException::invalid( __( 'Choosing sites works only on a multisite network.', 'lw-migration' ), 'sites' );
		}
		$existing = ( new NetworkReader() )->existing( $raw );
		foreach ( $raw as $id ) {
			if ( ! in_array( $id, $existing, true ) ) {
				/* translators: %d: site ID */
				throw RestException::invalid( sprintf( __( 'There is no site with ID %d in this network.', 'lw-migration' ), $id ), 'sites' );
			}
		}

		return array_values( $raw );
	}

	/**
	 * @param mixed $raw The `sites` object of a confirmation ({"<index>": "<address>"}).
	 * @return array<int, string> Address by the site's index in the archive (checked later by the import).
	 * @throws RestException On a key that is not an index, a value that is not a
	 *                       string, an overlong address or too many entries.
	 */
	public static function siteAddresses( $raw ): array {
		if ( ! is_array( $raw ) || count( $raw ) > self::MAX_SITES ) {
			throw RestException::invalidField( 'sites' );
		}
		foreach ( $raw as $index => $address ) {
			if ( ! is_int( $index ) || $index < 0 || ! is_string( $address ) || strlen( $address ) > self::MAX_ADDRESS || 1 === preg_match( '/[\x00-\x1F\x7F]/', $address ) ) {
				throw RestException::invalidField( 'sites' );
			}
		}

		return $raw;
	}
}

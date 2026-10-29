<?php
/**
 * The destination ids a request names.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * One reading of the `destinations` list for every route that takes one (a
 * backup upload, a manual backup): a JSON array of distinct ids of the shape
 * Profile::ID_PATTERN allows. The refusal is ours and never repeats the value.
 */
final class DestinationIds {

	/**
	 * @param mixed $raw Submitted list.
	 * @return array<int, string>
	 * @throws RestException 400 with field `destinations`.
	 */
	public static function checked( $raw ): array {
		if ( ! is_array( $raw ) ) {
			throw RestException::invalidField( 'destinations' );
		}
		$ids = [];
		foreach ( $raw as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( Profile::ID_PATTERN, $id ) || in_array( $id, $ids, true ) ) {
				throw RestException::invalid( __( 'Choose each destination once, from the stored destinations.', 'lw-migration' ), 'destinations' );
			}
			$ids[] = $id;
		}

		return $ids;
	}
}

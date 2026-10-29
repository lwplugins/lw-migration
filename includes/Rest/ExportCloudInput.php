<?php
/**
 * Where a manual backup of the browser goes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * The `destinations` and `keepLocal` keys of an export request, the browser's
 * `export --to` / `--[no-]keep-local`: no destinations (or an empty list) is no
 * plan at all; `keepLocal` (a real boolean, default true) without destinations
 * is refused, and every id must be a stored destination.
 */
final class ExportCloudInput {

	/** The request keys this class reads. */
	public const KEYS = [ 'destinations', 'keepLocal' ];

	/**
	 * @param array<string, mixed> $body         Export request.
	 * @param Destinations         $destinations Stored destinations.
	 * @return CloudPlan|null Null = the backup stays where it is.
	 * @throws RestException 400 for a malformed list or a keepLocal without destinations, 404 for an unknown destination.
	 */
	public static function plan( array $body, Destinations $destinations ): ?CloudPlan {
		$ids = array_key_exists( 'destinations', $body ) ? DestinationIds::checked( $body['destinations'] ) : [];
		if ( [] === $ids ) {
			if ( array_key_exists( 'keepLocal', $body ) ) {
				throw RestException::invalid( __( 'Choose a destination first, or leave out the local copy setting.', 'lw-migration' ), 'keepLocal' );
			}

			return null;
		}
		$keep = $body['keepLocal'] ?? true;
		if ( ! is_bool( $keep ) ) {
			throw RestException::invalidField( 'keepLocal' );
		}
		foreach ( $ids as $id ) {
			if ( null === $destinations->find( $id ) ) {
				throw RestException::destinationMissing();
			}
		}

		return JobInput::checked( static fn(): CloudPlan => CloudPlan::manual( $ids, $keep ), 'destinations' );
	}
}

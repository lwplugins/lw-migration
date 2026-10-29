<?php
/**
 * The connection entries an OAuth type answers after a connect.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * The type's connection entries, checked before OAuthApi writes anything:
 * Profile::connection() would drop what does not fit without a word.
 */
final class ConnectionEntries {

	/** Connection keys the callback writes itself (and the reconnect mail's), never a type's. */
	public const FIXED = [ 'client', 'id', 'connectedAt', 'redirectUri', 'noticed' ];

	/**
	 * @param array<string, mixed> $extra Entries from the type's connect().
	 * @param string               $label Provider name.
	 * @return array<string, int|string>
	 * @throws PermanentFailure INVALID for an account label too long to keep (our text; the label is never repeated).
	 * @throws \LogicException For a reserved, malformed or over-long entry, or too many (a type's bug).
	 */
	public static function check( array $extra, string $label ): array {
		if ( count( $extra ) > Profile::CONNECTION_ENTRIES - count( self::FIXED ) ) {
			throw new \LogicException( 'A destination type answered more connection entries than fit.' );
		}
		foreach ( $extra as $key => $value ) {
			if ( ! Profile::isConnectionKey( $key ) || in_array( $key, self::FIXED, true ) || ! ( is_int( $value ) || is_string( $value ) ) ) {
				throw new \LogicException( 'A destination type answered a reserved or malformed connection entry.' );
			}
			if ( is_string( $value ) && strlen( $value ) > Profile::CONNECTION_TEXT ) {
				if ( OAuthDestinationType::ACCOUNT !== $key ) {
					throw new \LogicException( 'A destination type answered a connection entry too long to keep.' );
				}
				/* translators: %s: provider name */
				throw new PermanentFailure( sprintf( __( '%s answered an account name too long to keep, so the account was not connected.', 'lw-migration' ), $label ), PermanentFailure::INVALID );
			}
		}

		return $extra;
	}
}

<?php
/**
 * The display claims of an OpenID Connect id_token.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E14: only for the account label on the card. The token came straight
 * from the token endpoint over verified TLS and is never used to decide
 * anything, so its signature is not checked. A label is shown only when it
 * is short visible text (no control character).
 */
final class IdToken {

	/** Longest label shown (an e-mail address is at most 254 bytes). */
	private const LABEL = 254;

	/** Deepest payload read. */
	private const DEPTH = 16;

	/**
	 * @param string $jwt Compact JWT.
	 * @return array<string, mixed> The payload object; [] for anything else.
	 */
	public static function claims( #[\SensitiveParameter] string $jwt ): array {
		$parts = explode( '.', $jwt );
		$json  = 3 === count( $parts ) ? Pkce::decode( $parts[1] ) : null;
		$data  = null === $json || ! str_starts_with( ltrim( $json ), '{' ) ? null : json_decode( $json, true, self::DEPTH );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * @param string $jwt Compact JWT.
	 * @return string preferred_username, else email, else ''.
	 */
	public static function label( #[\SensitiveParameter] string $jwt ): string {
		$claims = self::claims( $jwt );
		foreach ( [ 'preferred_username', 'email' ] as $claim ) {
			$value = $claims[ $claim ] ?? null;
			if ( is_string( $value ) && '' !== $value && strlen( $value ) <= self::LABEL && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				return $value;
			}
		}

		return '';
	}
}

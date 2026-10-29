<?php
/**
 * Proof Key for Code Exchange.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 7636 with S256 (plan E4): the verifier is 32 random bytes in
 * base64url (43 characters, the RFC minimum); the challenge is the
 * base64url SHA-256 of the verifier, without padding.
 */
final class Pkce {

	public const METHOD = 'S256';

	private const PATTERN = '/^[A-Za-z0-9._~-]{43,128}\z/';

	public static function verifier(): string {
		return self::encode( random_bytes( 32 ) );
	}

	public static function challenge( string $verifier ): string {
		return self::encode( hash( 'sha256', $verifier, true ) );
	}

	public static function isVerifier( string $value ): bool {
		return 1 === preg_match( self::PATTERN, $value );
	}

	public static function encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 base64url, not obfuscation.
	}

	public static function decode( string $text ): ?string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]*\z/', $text ) ) {
			return null;
		}
		$plain = base64_decode( strtr( $text, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url of a token part.

		return false === $plain ? null : $plain;
	}
}

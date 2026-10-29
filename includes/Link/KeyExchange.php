<?php
/**
 * The secret two sites agree on while pairing.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

defined( 'ABSPATH' ) || exit;

/**
 * Pure (sodium: PHP's extension or the compat library WordPress bundles).
 * Each side makes an X25519 key pair for one pairing; the link secret is an
 * HMAC keyed by the code's nonce over the link id, both public keys
 * (initiator first) and the shared X25519 value, so it never travels and
 * belongs to this one code and link. The proof lets the side that typed the
 * code check that the other side derived the same secret.
 */
final class KeyExchange {

	public const KEY_BYTES = 32;

	private const CONTEXT = "lw-migration/link/v1\n";

	private const PROOF_CONTEXT = "lw-migration/link-pair-ok/v1\n";

	private const LOOKUP_CONTEXT = 'lw-migration/code-id/v1';

	private const MAC_CONTEXT = "lw-migration/link-pair/v1\n";

	public static function available(): bool {
		return function_exists( 'sodium_crypto_box_keypair' ) && function_exists( 'sodium_crypto_box_publickey' )
			&& function_exists( 'sodium_crypto_box_secretkey' ) && function_exists( 'sodium_crypto_scalarmult' );
	}

	/**
	 * @return array{public: string, secret: string} Raw 32-byte keys.
	 */
	public static function keypair(): array {
		$pair = sodium_crypto_box_keypair();

		return [
			'public' => sodium_crypto_box_publickey( $pair ),
			'secret' => sodium_crypto_box_secretkey( $pair ),
		];
	}

	/**
	 * @param string $ownSecret  This side's X25519 secret key.
	 * @param string $peerPublic The other side's public key.
	 * @param string $linkId     Link id (chosen by the side that made the code).
	 * @param string $initiator  Public key of the side that typed the code.
	 * @param string $responder  Public key of the side that made the code.
	 * @param string $nonce      The code's nonce (32 hex).
	 * @return string 32 raw bytes.
	 * @throws \InvalidArgumentException For keys of the wrong size or a degenerate exchange.
	 */
	public static function secret( string $ownSecret, string $peerPublic, string $linkId, string $initiator, string $responder, string $nonce ): string {
		foreach ( [ $ownSecret, $peerPublic, $initiator, $responder ] as $key ) {
			if ( self::KEY_BYTES !== strlen( $key ) ) {
				throw new \InvalidArgumentException( 'The key exchange failed.' );
			}
		}
		try {
			$shared = sodium_crypto_scalarmult( $ownSecret, $peerPublic );
		} catch ( \SodiumException $e ) {
			throw new \InvalidArgumentException( 'The key exchange failed.' );
		}
		if ( hash_equals( str_repeat( "\0", self::KEY_BYTES ), $shared ) ) {
			throw new \InvalidArgumentException( 'The key exchange failed.' );
		}

		return hash_hmac( 'sha256', self::CONTEXT . $linkId . "\n" . $initiator . $responder . $shared, $nonce, true );
	}

	public static function proof( string $secret, string $linkId, string $initiator, string $responder ): string {
		return hash_hmac( 'sha256', self::PROOF_CONTEXT . $linkId . "\n" . $initiator . $responder, $secret );
	}

	/**
	 * How a pairing request names its code without sending the nonce (ruling I6).
	 *
	 * @param string $nonce The code's nonce (32 hex).
	 * @return string 64 hex.
	 */
	public static function lookup( string $nonce ): string {
		return hash( 'sha256', self::LOOKUP_CONTEXT . $nonce );
	}

	/**
	 * Proves the pairing request was made by someone who holds the code, over
	 * what that request declares (public key, address, name).
	 *
	 * @param string $nonce     The code's nonce (32 hex).
	 * @param string $initiator Public key of the side that typed the code.
	 * @param string $address   Address the requesting site declares.
	 * @param string $name      Name the requesting site declares.
	 * @return string 64 hex.
	 * @throws \InvalidArgumentException For an initiator key of the wrong size.
	 */
	public static function pairMac( string $nonce, string $initiator, string $address, string $name ): string {
		if ( self::KEY_BYTES !== strlen( $initiator ) ) {
			throw new \InvalidArgumentException( 'The key exchange failed.' );
		}
		$text = self::MAC_CONTEXT . $initiator . pack( 'N', strlen( $address ) ) . $address . pack( 'N', strlen( $name ) ) . $name;

		return hash_hmac( 'sha256', $text, $nonce );
	}
}

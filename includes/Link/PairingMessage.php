<?php
/**
 * The shape of the pairing request and its answer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. Allow-lists of the two unsigned messages of a pairing (protocol 1):
 * exactly the known keys, the protocol number, 64-hex values, a public key
 * that is not all zeros, an address of PairingCode's allow-list, rights as
 * Links stores them, and a declared name of valid UTF-8 without control
 * characters and at most MAX_NAME bytes (Pairing clips it before storing).
 * Whether the request proves the code (its MAC) and the answer the secret
 * (its proof) is Pairing's job.
 */
final class PairingMessage {

	/** Keys of the pairing request, sorted. */
	public const REQUEST_KEYS = [ 'address', 'code', 'mac', 'name', 'protocol', 'public' ];

	/** Keys of the answer, sorted. */
	public const ANSWER_KEYS = [ 'address', 'allow', 'id', 'name', 'proof', 'protocol', 'public' ];

	/** Most bytes of a declared name. */
	public const MAX_NAME = 1024;

	private const HEX64 = '/^[a-f0-9]{64}\z/';

	/**
	 * @param mixed $body Decoded JSON body.
	 * @return array{code: string, public: string, address: string, name: string, mac: string}|null The public key raw; null for any other shape.
	 */
	public static function request( $body ): ?array {
		if ( ! is_array( $body ) || self::REQUEST_KEYS !== self::keys( $body ) || Pairing::PROTOCOL !== $body['protocol']
			|| ! self::hex64( $body['code'] ) || ! self::hex64( $body['mac'] ) || ! self::publicKey( $body['public'] )
			|| ! is_string( $body['address'] ) || ! PairingCode::isAddress( $body['address'] ) || ! self::name( $body['name'] ) ) {
			return null;
		}

		return [
			'code'    => $body['code'],
			'public'  => (string) hex2bin( $body['public'] ),
			'address' => $body['address'],
			'name'    => $body['name'],
			'mac'     => $body['mac'],
		];
	}

	/**
	 * @param mixed $answer Decoded answer.
	 * @return array{id: string, public: string, name: string, allow: array<int, string>, proof: string}|null The public key raw; null for any other shape.
	 */
	public static function answer( $answer ): ?array {
		if ( ! is_array( $answer ) || self::ANSWER_KEYS !== self::keys( $answer ) || Pairing::PROTOCOL !== $answer['protocol']
			|| ! is_string( $answer['id'] ) || 1 !== preg_match( Signer::LINK_PATTERN, $answer['id'] )
			|| ! self::publicKey( $answer['public'] ) || ! self::hex64( $answer['proof'] ) || ! is_string( $answer['address'] )
			|| ! is_array( $answer['allow'] ) || [] === $answer['allow'] || Links::allow( $answer['allow'] ) !== $answer['allow']
			|| ! self::name( $answer['name'] ) ) {
			return null;
		}

		return [
			'id'     => $answer['id'],
			'public' => (string) hex2bin( $answer['public'] ),
			'name'   => $answer['name'],
			'allow'  => $answer['allow'],
			'proof'  => $answer['proof'],
		];
	}

	/**
	 * @param array<mixed> $data Map.
	 * @return array<int, int|string> Its keys, sorted.
	 */
	private static function keys( array $data ): array {
		$keys = array_keys( $data );
		sort( $keys );

		return $keys;
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function hex64( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::HEX64, $value );
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function publicKey( $value ): bool {
		return self::hex64( $value ) && str_repeat( '0', 64 ) !== $value;
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function name( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= self::MAX_NAME && LinkRow::utf8( $value ) && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value );
	}
}

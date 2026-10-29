<?php
/**
 * An SSH server's host key fingerprint.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D31. The fingerprint is what `ssh-keygen -lf` prints: "SHA256:" and
 * the unpadded base64 of the SHA-256 of the key blob. check() runs before
 * any login, so a password or a key is never offered to a server whose key
 * is not the confirmed one. A fingerprint is not a secret.
 */
final class HostKey {

	public const PATTERN = '/^SHA256:[A-Za-z0-9+\/]{43}\z/';

	/**
	 * @param string $serverKey "<type> <base64 blob>" as the server sent it.
	 * @return string|null Null when the blob is not base64.
	 */
	public static function fingerprint( string $serverKey ): ?string {
		$parts = explode( ' ', trim( $serverKey ) );
		$blob  = base64_decode( (string) ( $parts[1] ?? '' ), true );
		if ( false === $blob || '' === $blob ) {
			return null;
		}

		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' );
	}

	/**
	 * @param string $serverKey "<type> <base64 blob>".
	 * @return string The key type (e.g. ssh-ed25519), '' when it has an unexpected shape.
	 */
	public static function type( string $serverKey ): string {
		$type = explode( ' ', trim( $serverKey ) )[0];

		return 1 === preg_match( '/^[a-z0-9@.-]{1,64}\z/', $type ) ? $type : '';
	}

	/**
	 * The command that prints the fingerprint of a host key of this type in
	 * OpenSSH's default place (the twin of hostKeyCommand() in
	 * src/destinations/destinations.js; never translated).
	 *
	 * @param string $keyType Key or signature type ('' = unknown: the Ed25519 key).
	 */
	public static function command( string $keyType ): string {
		$file = 'ed25519';
		if ( str_starts_with( $keyType, 'ecdsa' ) ) {
			$file = 'ecdsa';
		} elseif ( str_contains( $keyType, 'rsa' ) ) {
			$file = 'rsa';
		}

		return 'ssh-keygen -lf /etc/ssh/ssh_host_' . $file . '_key.pub';
	}

	public static function isFingerprint( string $value ): bool {
		return 1 === preg_match( self::PATTERN, $value );
	}

	/**
	 * @param string $expected  The confirmed fingerprint ('' = none yet).
	 * @param string $serverKey What the server shows now.
	 * @param string $where     sftp://host:port for messages.
	 * @throws PermanentFailure HOST_KEY_UNKNOWN, HOST_KEY_CHANGED (with data), or INVALID for an unreadable key.
	 */
	public static function check( string $expected, string $serverKey, string $where ): void {
		$shown = self::fingerprint( $serverKey );
		if ( null === $shown ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s sent a host key this site cannot read.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
		}
		if ( $shown === $expected ) {
			return;
		}
		$data = [
			'hostKey'     => $shown,
			'hostKeyType' => self::type( $serverKey ),
		];
		if ( '' === $expected ) {
			/* translators: 1: server address, 2: SHA256 fingerprint, 3: key type such as ssh-ed25519 */
			throw new PermanentFailure( sprintf( __( '%1$s shows the host key %2$s (%3$s). Compare it with the key on your server, then confirm it to connect.', 'lw-migration' ), $where, $shown, $data['hostKeyType'] ), PermanentFailure::HOST_KEY_UNKNOWN, 0, null, $data );
		}
		$data['expected'] = $expected;
		/* translators: 1: server address, 2: fingerprint shown now, 3: confirmed fingerprint */
		throw new PermanentFailure( sprintf( __( 'The host key of %1$s changed: it shows %2$s instead of the confirmed %3$s. If the server was not reinstalled, someone may be in between; confirm the new key only when you are sure.', 'lw-migration' ), $where, $shown, $expected ), PermanentFailure::HOST_KEY_CHANGED, 0, null, $data );
	}
}

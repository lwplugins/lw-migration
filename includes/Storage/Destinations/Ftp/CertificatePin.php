<?php
/**
 * An FTPS server's certificate fingerprint.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #5, the twin of Sftp\HostKey for a certificate the system's CA list
 * cannot vouch for (a self-signed one, or one for another name): the SHA-256
 * of the certificate (DER) in the form `openssl x509 -fingerprint -sha256`
 * prints, 32 upper-case hex pairs joined by colons. check() runs before any
 * login, so the password is never sent to a server whose certificate is not
 * the confirmed one. A fingerprint is not a secret.
 */
final class CertificatePin {

	public const PATTERN = '/^[0-9A-F]{2}(:[0-9A-F]{2}){31}\z/';

	/** What prints the fingerprint of the certificate file on the server (never translated; the twin of CERTIFICATE_COMMAND in src/destinations/destinations.js). */
	public const COMMAND = 'openssl x509 -noout -fingerprint -sha256 -in cert.pem';

	/**
	 * @param string $der The certificate, DER-encoded.
	 */
	public static function fingerprint( string $der ): string {
		return implode( ':', str_split( strtoupper( hash( 'sha256', $der ) ), 2 ) );
	}

	/**
	 * A typed fingerprint in the stored form: lower-case hex is accepted.
	 *
	 * @param string $typed As typed.
	 * @return string|null Null when it is not a SHA-256 fingerprint.
	 */
	public static function normalize( string $typed ): ?string {
		$value = strtoupper( trim( $typed ) );

		return self::isFingerprint( $value ) ? $value : null;
	}

	public static function isFingerprint( string $value ): bool {
		return 1 === preg_match( self::PATTERN, $value );
	}

	/**
	 * @param string $expected The confirmed fingerprint ('' = none yet).
	 * @param string $shown    What the server shows now.
	 * @param string $where    ftps://host:port for messages.
	 * @throws PermanentFailure CERT_UNKNOWN or CERT_CHANGED (data: certFingerprint, expected).
	 */
	public static function check( string $expected, string $shown, string $where ): void {
		if ( $shown === $expected ) {
			return;
		}
		$data = [ 'certFingerprint' => $shown ];
		if ( '' === $expected ) {
			/* translators: 1: server address, 2: SHA-256 fingerprint */
			throw new PermanentFailure( sprintf( __( 'The certificate of %1$s cannot be checked against the known certificate authorities (it may be self-signed). It shows the fingerprint %2$s: compare it with the certificate on your server, then confirm it to connect.', 'lw-migration' ), $where, $shown ), PermanentFailure::CERT_UNKNOWN, 0, null, $data );
		}
		$data['expected'] = $expected;
		/* translators: 1: server address, 2: fingerprint shown now, 3: confirmed fingerprint */
		throw new PermanentFailure( sprintf( __( 'The certificate of %1$s changed: it shows %2$s instead of the confirmed %3$s. If the certificate was not renewed, someone may be in between; confirm the new one only when you are sure.', 'lw-migration' ), $where, $shown, $expected ), PermanentFailure::CERT_CHANGED, 0, null, $data );
	}
}

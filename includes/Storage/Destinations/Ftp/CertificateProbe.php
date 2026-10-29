<?php
/**
 * Reads an FTPS server's certificate without logging in.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #5. A control connection of its own to the checked address: the
 * greeting, AUTH TLS, a TLS handshake that only reads the certificate (no
 * check: that is what the fingerprint is for), then QUIT. No user name or
 * password is ever sent on it. The certificate gives the fingerprint the
 * admin confirms (CertificatePin) and the SHA-256 of its public key, which
 * CurlFtpsConnection pins on the connection that logs in, so that
 * connection reaches only a server holding the confirmed certificate's key.
 */
final class CertificateProbe {

	/**
	 * @param string $ip      The checked address.
	 * @param int    $port    Port.
	 * @param string $name    The host as stored (sent as SNI when it is a name).
	 * @param int    $timeout Seconds.
	 * @param string $where   ftps://host:port for messages.
	 * @return array{fingerprint: string, publicKey: string} publicKey in cURL's pin form (sha256//base64).
	 * @throws TransientFailure NETWORK when the server cannot be reached or the connection breaks (and a PermanentFailure AUTH when the server does not offer FTPS, INVALID when its certificate cannot be read).
	 */
	public static function fetch( string $ip, int $port, string $name, int $timeout, string $where ): array {
		$ssl     = [
			'verify_peer'       => false, // Read, not checked: the fingerprint is compared by the caller.
			'verify_peer_name'  => false,
			'allow_self_signed' => true,
			'capture_peer_cert' => true,
			'SNI_enabled'       => true,
			'peer_name'         => $name,
		];
		$address = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		$socket  = @stream_socket_client( 'tcp://' . $address . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, stream_context_create( [ 'ssl' => $ssl ] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below in our words.
		if ( false === $socket ) {
			/* translators: %s: server address */
			throw new TransientFailure( sprintf( __( '%s cannot be reached.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
		}
		try {
			return self::read( $socket, $timeout, $where );
		} finally {
			@fclose( $socket ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket the server may have dropped.
		}
	}

	/**
	 * @param resource $socket  Connected control connection.
	 * @param int      $timeout Seconds.
	 * @param string   $where   For messages.
	 * @return array{fingerprint: string, publicKey: string}
	 * @throws PermanentFailure AUTH when the server does not offer FTPS, INVALID when its certificate cannot be read.
	 * @throws TransientFailure NETWORK when the connection breaks.
	 */
	private static function read( $socket, int $timeout, string $where ): array {
		stream_set_timeout( $socket, $timeout );
		$greeted = '220' === self::reply( $socket );
		if ( $greeted ) {
			self::send( $socket, 'AUTH TLS' );
		}
		$auth = $greeted ? self::reply( $socket ) : '';
		if ( '' !== $auth && '234' !== $auth ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s did not start an encrypted (FTPS) connection: the server may not offer FTPS on this port.', 'lw-migration' ), $where ), PermanentFailure::AUTH ); // Nothing was logged in: never a password to blame.
		}
		if ( '234' !== $auth || true !== @stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed handshake is reported below.
			throw self::brokenOff( $where );
		}
		$certificate = stream_context_get_params( $socket )['options']['ssl']['peer_certificate'] ?? null;
		$seen        = null === $certificate ? null : self::identity( $certificate );
		self::send( $socket, 'QUIT' );
		if ( null === $seen ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s sent a certificate this site cannot read.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
		}

		return $seen;
	}

	/**
	 * @param mixed $certificate What the TLS layer captured (an OpenSSLCertificate).
	 * @return array{fingerprint: string, publicKey: string}|null
	 */
	private static function identity( $certificate ): ?array {
		$pem = '';
		$key = @openssl_pkey_get_public( $certificate ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable key is answered with null.
		if ( ! @openssl_x509_export( $certificate, $pem ) || false === $key ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			return null;
		}
		$details = openssl_pkey_get_details( $key );
		$cert    = self::der( (string) $pem );
		$spki    = self::der( is_array( $details ) ? (string) ( $details['key'] ?? '' ) : '' );
		if ( '' === $cert || '' === $spki ) {
			return null;
		}

		return [
			'fingerprint' => CertificatePin::fingerprint( $cert ),
			'publicKey'   => 'sha256//' . base64_encode( hash( 'sha256', $spki, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- cURL's pinned-key format.
		];
	}

	/**
	 * @param string $pem One PEM block.
	 * @return string Its DER bytes ('' when it is not base64).
	 */
	private static function der( string $pem ): string {
		$der = base64_decode( (string) preg_replace( '/-----[^-]+-----|\s+/', '', $pem ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- PEM to DER.

		return false === $der ? '' : $der;
	}

	/**
	 * The code of the server's next reply ('' when none came), its continuation lines read past.
	 *
	 * @param resource $socket Control connection.
	 */
	private static function reply( $socket ): string {
		for ( $i = 0; $i < 100; $i++ ) {
			$line = fgets( $socket, 8192 );
			if ( false === $line ) {
				return '';
			}
			if ( 1 === preg_match( '/^(\d{3}) /', $line, $m ) ) {
				return $m[1];
			}
		}

		return '';
	}

	/**
	 * @param resource $socket  Control connection.
	 * @param string   $command FTP command.
	 */
	private static function send( $socket, string $command ): void {
		@fwrite( $socket, $command . "\r\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a dropped connection shows in the reply.
	}

	private static function brokenOff( string $where ): TransientFailure {
		/* translators: %s: server address */
		return new TransientFailure( sprintf( __( 'The connection to %s broke off.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
	}
}

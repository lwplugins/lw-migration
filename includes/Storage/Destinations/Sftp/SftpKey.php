<?php
/**
 * An SSH private key from the vault.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common\PrivateKey;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\EC;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\RSA;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4: RSA, Ed25519 and ECDSA keys in OpenSSH or PEM format, with an
 * optional passphrase. EC is tried, then RSA (not phpseclib's
 * PublicKeyLoader, which falls through to DSA and X.509 — left out of the
 * bundled subset, plan D30). A curve left out of the bundle (Ed448,
 * secp256k1) makes phpseclib fail with an Error, so every Throwable counts
 * as "not opened". A key that does not open is a wrong passphrase when it is
 * encrypted (AUTH), else not a usable key (INVALID). Messages never repeat
 * the key, the passphrase or phpseclib's text; the key is held in memory
 * only, never written to a file.
 */
final class SftpKey {

	/**
	 * @param string $text       The key as pasted.
	 * @param string $passphrase Its passphrase ('' = none).
	 * @throws PermanentFailure AUTH or INVALID.
	 */
	public static function load( #[\SensitiveParameter] string $text, #[\SensitiveParameter] string $passphrase ): PrivateKey {
		foreach ( [ EC::class, RSA::class ] as $algorithm ) {
			try {
				return $algorithm::loadPrivateKey( $text, $passphrase );
			} catch ( \Throwable $e ) {
				unset( $e ); // Another algorithm, a curve not bundled, or not opened with this passphrase.
			}
		}
		if ( self::encrypted( $text ) ) {
			throw new PermanentFailure( __( 'The passphrase does not open the private key. Enter the right one, or the key again.', 'lw-migration' ), PermanentFailure::AUTH );
		}

		throw new PermanentFailure( __( 'This is not a private key LW Migration can use: paste an RSA, Ed25519 or ECDSA private key in OpenSSH or PEM format.', 'lw-migration' ), PermanentFailure::INVALID );
	}

	/**
	 * Whether the key is protected by a passphrase: PEM says ENCRYPTED; an
	 * OpenSSH key names its cipher ("none" when it is not).
	 *
	 * @param string $text The key.
	 */
	public static function encrypted( #[\SensitiveParameter] string $text ): bool {
		if ( str_contains( $text, 'ENCRYPTED' ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/-----BEGIN OPENSSH PRIVATE KEY-----(.+?)-----END OPENSSH PRIVATE KEY-----/s', $text, $m ) ) {
			return false;
		}
		$blob = (string) base64_decode( (string) preg_replace( '/\s+/', '', $m[1] ), true );
		if ( ! str_starts_with( $blob, "openssh-key-v1\0" ) || strlen( $blob ) < 19 ) {
			return false;
		}
		$header = unpack( 'N', substr( $blob, 15, 4 ) );
		$length = is_array( $header ) ? (int) $header[1] : 0;

		return 'none' !== substr( $blob, 19, $length );
	}
}

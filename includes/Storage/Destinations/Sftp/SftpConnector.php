<?php
/**
 * Opens a checked, logged-in SFTP session.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ServerAddress;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common\PrivateKey;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D31: resolve and check the host (P-C2, H1–H2) and open the session to
 * that address, never the name; compare the host key with the confirmed
 * one; then — only then — open the key (a wrong passphrase stops here) and
 * log in, and compare the key once more; then make sure the folder exists.
 * Any failure closes the session.
 * The confirmed key's type is handed to the session so the server is asked
 * for that kind of key first (P-M8).
 */
final class SftpConnector {

	/**
	 * @param array<string, mixed>  $settings The profile's settings (host already checked by the type).
	 * @param array<string, string> $secrets  Its opened secrets.
	 * @param \Closure              $open     fn( string $ip, int $port, int $timeout, string $where, string $hostKeyType ): SftpSession.
	 * @param string                $where    sftp://host:port for messages.
	 */
	public function __construct( private array $settings, #[\SensitiveParameter] private array $secrets, private \Closure $open, private string $where ) {
	}

	/**
	 * @throws PermanentFailure When the address, the host key, the key, the login or the folder is refused.
	 * @throws \Throwable A TransientFailure when the server cannot be reached now; anything thrown after opening, once the session is closed.
	 */
	public function connect(): SftpSession {
		$ip       = ServerAddress::connectTo( (string) ( $this->settings['host'] ?? '' ), $this->where, null );
		$session  = ( $this->open )( $ip, (int) ( $this->settings['port'] ?? 22 ), CloudTimeout::seconds(), $this->where, (string) ( $this->settings['hostKeyType'] ?? '' ) );
		$expected = (string) ( $this->settings['hostKey'] ?? '' );
		try {
			HostKey::check( $expected, $session->hostKey(), $this->where );
			if ( ! $session->login( (string) ( $this->settings['user'] ?? '' ), $this->credential() ) ) {
				/* translators: %s: server address */
				throw new PermanentFailure( sprintf( __( '%s refused the user name, the password or the key.', 'lw-migration' ), $this->where ), PermanentFailure::AUTH );
			}
			HostKey::check( $expected, $session->hostKey(), $this->where ); // Still the same connection (review C1).
			if ( ! $session->ensureFolder( (string) ( $this->settings['folder'] ?? '' ) ) ) {
				/* translators: %s: server address */
				throw new PermanentFailure( sprintf( __( 'The folder cannot be opened or created at %s.', 'lw-migration' ), $this->where ), PermanentFailure::MISSING );
			}
		} catch ( \Throwable $e ) {
			$session->close();
			throw $e;
		}

		return $session;
	}

	/**
	 * @throws PermanentFailure AUTH or INVALID for a key that does not open.
	 */
	private function credential(): string|PrivateKey {
		if ( 'key' === ( $this->settings['auth'] ?? 'password' ) ) {
			return SftpKey::load( (string) ( $this->secrets['privateKey'] ?? '' ), (string) ( $this->secrets['passphrase'] ?? '' ) );
		}

		return (string) ( $this->secrets['password'] ?? '' );
	}
}

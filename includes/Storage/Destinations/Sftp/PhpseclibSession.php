<?php
/**
 * An SFTP session through the bundled phpseclib.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common\PrivateKey;
use LightweightPlugins\Migration\Vendor\phpseclib3\Exception\NoSupportedAlgorithmsException;
use LightweightPlugins\Migration\Vendor\phpseclib3\Exception\UnsupportedAlgorithmException;
use LightweightPlugins\Migration\Vendor\phpseclib3\Net\SFTP;
use LightweightPlugins\Migration\Vendor\phpseclib3\Net\SSH2;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D30, D34 (verified against OpenSSH 10.3): writes use explicit offsets
 * (SFTP::put( …, SOURCE_STRING, $offset ) opens without truncating); reads
 * use get( …, false, $offset, $length ). The TCP connection is opened here,
 * to the address the connector checked (P-C2), and handed to phpseclib as a
 * stream, never a host name. Review C1: phpseclib reconnects by itself in
 * one case (an unreadable SERVICE_ACCEPT under arcfour256, aes192-ctr or
 * aes256-ctr) and then replays the stored login without checking the new
 * host key; built on a stream it has no address to reconnect to, so that
 * reconnect fails and nothing is replayed. The stat cache is off, so a size
 * is always the server's answer now. phpseclib throws on a lost connection
 * or a timeout and may raise notices; both become a failure in our words,
 * and nothing it says reaches a message.
 */
final class PhpseclibSession implements SftpSession {

	/** The value of phpseclib's NET_SFTP_TYPE_REGULAR. */
	private const REGULAR = 1;

	/** The RSA key's signature formats: getServerPublicHostKey() names the one negotiated. */
	private const RSA = [ 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa' ];

	/**
	 * The connection, opened by the first call.
	 *
	 * @var SFTP|null
	 */
	private ?SFTP $sftp = null;

	/**
	 * @param string $ip          Checked address (ServerAddress::connectTo()).
	 * @param int    $port        Port.
	 * @param int    $timeout     Seconds.
	 * @param string $where       sftp://host:port for messages.
	 * @param string $hostKeyType Type of the confirmed host key ('' = none), asked for first (P-M8).
	 */
	public function __construct( private string $ip, private int $port, private int $timeout, private string $where, private string $hostKeyType = '' ) {
	}

	/**
	 * Host key algorithms in the order to offer them: the confirmed key's
	 * kind first (all RSA signature formats for an RSA key), then every
	 * other one phpseclib supports — a preference, not a filter, because
	 * phpseclib also limits a user's RSA key signature to this list.
	 *
	 * @param string $type The confirmed key's type ('' = none).
	 * @return array<int, string> Empty: phpseclib's own order.
	 */
	public static function preferredHostKeys( string $type ): array {
		$supported = SSH2::getSupportedHostKeyAlgorithms();
		$first     = in_array( $type, self::RSA, true ) ? self::RSA : [ $type ];
		$first     = array_values( array_intersect( $first, $supported ) );
		if ( [] === $first ) {
			return [];
		}

		return array_values( array_unique( array_merge( $first, $supported ) ) );
	}

	public function hostKey(): string {
		$key = $this->guard( fn() => $this->sftp()->getServerPublicHostKey() );
		if ( ! is_string( $key ) || '' === $key ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s could not prove its host key (the key exchange signature did not match).', 'lw-migration' ), $this->where ), PermanentFailure::INVALID );
		}

		return $key;
	}

	public function login( string $user, #[\SensitiveParameter] string|PrivateKey $credential ): bool {
		return true === $this->guard( fn() => $this->sftp()->login( $user, $credential ) );
	}

	public function ensureFolder( string $folder ): bool {
		if ( '' === $folder ) {
			return true;
		}

		return true === $this->guard( fn(): bool => $this->sftp()->is_dir( $folder ) || $this->sftp()->mkdir( $folder, -1, true ) );
	}

	public function files( string $folder ): ?array {
		$list = $this->guard( fn() => $this->sftp()->rawlist( '' === $folder ? '.' : $folder ) );
		if ( ! is_array( $list ) ) {
			return null;
		}
		$files = [];
		foreach ( $list as $name => $attributes ) {
			if ( is_array( $attributes ) && self::REGULAR === ( $attributes['type'] ?? 0 ) ) {
				$files[ (string) $name ] = [
					'size'  => (int) ( $attributes['size'] ?? 0 ),
					'mtime' => (int) ( $attributes['mtime'] ?? 0 ),
				];
			}
		}

		return $files;
	}

	public function size( string $path ): int {
		$stat = $this->guard( fn() => $this->sftp()->stat( $path ) );
		if ( ! is_array( $stat ) || ! isset( $stat['size'] ) || self::REGULAR !== ( $stat['type'] ?? self::REGULAR ) ) {
			return -1; // No file (a folder of that name is not one).
		}

		return (int) $stat['size'];
	}

	public function write( string $path, string $bytes, int $offset ): bool {
		return true === $this->guard( fn() => $this->sftp()->put( $path, $bytes, SFTP::SOURCE_STRING, $offset ) );
	}

	public function read( string $path, int $offset, int $length ): ?string {
		$bytes = $this->guard( fn() => $this->sftp()->get( $path, false, $offset, $length ) );

		return is_string( $bytes ) ? $bytes : null;
	}

	public function rename( string $from, string $to ): bool {
		return true === $this->guard( fn() => $this->sftp()->rename( $from, $to ) );
	}

	public function delete( string $path ): bool {
		return true === $this->guard( fn() => $this->sftp()->delete( $path, false ) );
	}

	public function close(): void {
		if ( null === $this->sftp ) {
			return;
		}
		try {
			$this->sftp->disconnect();
		} catch ( \Throwable $e ) {
			unset( $e ); // Closing a connection the server already dropped.
		}
		$this->sftp = null;
	}

	private function sftp(): SFTP {
		if ( null === $this->sftp ) {
			$host   = str_contains( $this->ip, ':' ) ? '[' . $this->ip . ']' : $this->ip;
			$socket = stream_socket_client( 'tcp://' . $host . ':' . $this->port, $errno, $errstr, (float) $this->timeout );
			if ( ! is_resource( $socket ) ) {
				/* translators: %s: server address */
				throw new TransientFailure( sprintf( __( '%s cannot be reached, or closed the connection.', 'lw-migration' ), $this->where ), TransientFailure::NETWORK );
			}
			$this->sftp = new SFTP( $socket, $this->port, $this->timeout ); // A stream, never an address: no reconnect (review C1).
			$this->sftp->setTimeout( $this->timeout );
			$this->sftp->disableStatCache();
			$preferred = self::preferredHostKeys( $this->hostKeyType );
			if ( [] !== $preferred ) {
				$this->sftp->setPreferredAlgorithms( [ 'hostkey' => $preferred ] );
			}
		}

		return $this->sftp;
	}

	/**
	 * Runs one phpseclib call: its notices are dropped; a server with nothing
	 * in common (or a key of a kind left out of the bundle, whose class then
	 * does not exist) is a lasting failure, anything else a passing one.
	 * Any other Error is a bug and is thrown on. No algorithm in common is
	 * PermanentFailure INVALID.
	 *
	 * @param \Closure $call fn(): mixed.
	 * @return mixed What phpseclib answered.
	 * @throws TransientFailure NETWORK on a lost connection or a timeout.
	 * @throws \Error Anything else that is not an exception.
	 */
	private function guard( \Closure $call ) {
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- narrow: phpseclib's notices for one call.
		try {
			return $call();
		} catch ( \Error $e ) {
			if ( ! self::isMissingBundledClass( $e ) ) {
				throw $e;
			}
			throw $this->unsupported();
		} catch ( NoSupportedAlgorithmsException | UnsupportedAlgorithmException $e ) {
			throw $this->unsupported();
		} catch ( \Exception $e ) {
			/* translators: %s: server address */
			throw new TransientFailure( sprintf( __( '%s cannot be reached, or closed the connection.', 'lw-migration' ), $this->where ), TransientFailure::NETWORK );
		} finally {
			restore_error_handler();
		}
	}

	/** The server and this site have no algorithm (or key kind) in common. */
	private function unsupported(): PermanentFailure {
		/* translators: %s: server address */
		return new PermanentFailure( sprintf( __( '%s offers no key exchange, encryption or key type this site supports.', 'lw-migration' ), $this->where ), PermanentFailure::INVALID );
	}

	/**
	 * A class of the bundled phpseclib that was trimmed away (plan D30), e.g.
	 * a curve: PHP reports it as 'Class "…\\Vendor\\phpseclib3\\…" not found'.
	 *
	 * @param \Error $e What PHP threw.
	 */
	private static function isMissingBundledClass( \Error $e ): bool {
		return str_starts_with( $e->getMessage(), 'Class "LightweightPlugins\\Migration\\Vendor\\phpseclib3\\' ) && str_ends_with( $e->getMessage(), '" not found' );
	}
}

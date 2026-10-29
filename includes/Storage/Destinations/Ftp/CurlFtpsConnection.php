<?php
/**
 * An FTPS connection through cURL, with the server's certificate checked.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #5 (verify + pin). Explicit FTPS (AUTH TLS, CURLUSESSL_ALL: no plain
 * fallback) to the address ServerAddress::connectTo() checked, pinned with
 * CURLOPT_RESOLVE under the stored name, environment proxies off, and
 * passive data connections to that same address (CURLOPT_FTP_SKIP_PASV_IP,
 * P-C2). Without a confirmed fingerprint the certificate and the host name
 * are checked against WordPress's CA list; when that check fails, a
 * login-free CertificateProbe reads the certificate and the failure is
 * CERT_UNKNOWN with its fingerprint. With one, the probe's certificate must
 * have exactly that fingerprint and its public key is pinned
 * (CURLOPT_PINNEDPUBLICKEY) on the connection that logs in; another key
 * there is CERT_CHANGED. The TLS handshake comes before USER/PASS, so the
 * password never reaches a server that failed it. One cURL handle keeps one
 * control connection between calls; parts go from memory (APPE needs no
 * temp file here); REST + STOR is REST sent right before STOR (PREQUOTE).
 * What a cURL error means is CurlFtpsFailure's.
 */
final class CurlFtpsConnection implements FtpConnection {

	/**
	 * The cURL handle (null once closed): a resource before PHP 8, a \CurlHandle after.
	 *
	 * @var mixed
	 */
	private $curl;

	/**
	 * The server's replies to the last call (cURL hands FTP replies to the header callback).
	 *
	 * @var array<int, string>
	 */
	private array $replies = [];

	/**
	 * @param array<int, mixed> $tls     The certificate options (FtpsTrust), set first on every call.
	 * @param array<int, mixed> $options Options of every call (address, login).
	 * @param string            $dir     URL of the folder, with a trailing slash.
	 * @param string            $pin     The confirmed fingerprint ('' = none).
	 * @param \Closure          $probe   fn(): array{fingerprint: string, publicKey: string}.
	 * @param \Closure          $setopt  fn( $handle, int $option, mixed $value ): bool.
	 * @param string            $where   ftps://host:port for messages.
	 */
	private function __construct( private array $tls, #[\SensitiveParameter] private array $options, private string $dir, private string $pin, private \Closure $probe, private \Closure $setopt, private string $where ) {
		$this->curl = curl_init();
	}

	/**
	 * Whether this PHP can open checked FTPS connections: cURL with FTP and TLS whose backend can pin a key, and OpenSSL for the certificate probe.
	 *
	 * @param \Closure|null $setopt fn( $handle, int $option, mixed $value ): bool (tests); null = curl_setopt.
	 */
	public static function supported( ?\Closure $setopt = null ): bool {
		if ( ! function_exists( 'curl_version' ) || ! function_exists( 'openssl_x509_export' ) || ! function_exists( 'stream_socket_enable_crypto' ) ) {
			return false;
		}
		$version = curl_version();

		return is_array( $version ) && in_array( 'ftp', (array) ( $version['protocols'] ?? [] ), true ) && 0 !== ( (int) ( $version['features'] ?? 0 ) & CURL_VERSION_SSL ) && FtpsTrust::canPin( $setopt );
	}

	public static function unsupported(): string {
		return __( 'This server\'s PHP cannot open FTPS connections that check the server\'s certificate (its cURL or OpenSSL has no FTP or TLS support). Choose SFTP.', 'lw-migration' );
	}

	/**
	 * Connects to the checked address, checks the certificate, logs in and changes into the folder (creating it).
	 *
	 * @param array{host: string, name: string, port: int, passive: bool, user: string, folder: string, pin: string} $at       Where; host is an address from ServerAddress::connectTo(), name the host as stored.
	 * @param string                                                                                                 $password Password.
	 * @param string                                                                                                 $where    ftps://host:port for messages.
	 * @param int                                                                                                    $timeout  Seconds.
	 * @param \Closure|null                                                                                          $setopt   fn( $handle, int $option, mixed $value ): bool (tests); null = curl_setopt.
	 * @throws \LogicException When $at['host'] is a name (a programming error).
	 * @throws PermanentFailure CERT_UNKNOWN or CERT_CHANGED for a certificate to confirm; AUTH, MISSING or INVALID otherwise (and a TransientFailure when the server cannot be reached or the connection breaks).
	 */
	public static function open( array $at, #[\SensitiveParameter] string $password, string $where, int $timeout, ?\Closure $setopt = null ): self {
		if ( ! AddressGuard::isLiteral( $at['host'] ) ) {
			throw new \LogicException( 'An FTPS connection goes to an address ServerAddress::connectTo() checked, never to a name.' );
		}
		$setopt = $setopt ?? FtpsTrust::setopt();
		if ( ! self::supported( $setopt ) ) {
			throw new PermanentFailure( self::unsupported(), PermanentFailure::INVALID );
		}
		$probe = static fn(): array => CertificateProbe::fetch( $at['host'], $at['port'], $at['name'], $timeout, $where );
		$pin   = AddressGuard::isLiteral( $at['name'] ) ? [ CURLOPT_PROXY => '' ] : GuardedHttp::pinOptions( $at['name'], $at['port'], $at['host'] );
		$base  = [
			CURLOPT_USE_SSL              => CURLUSESSL_ALL,
			CURLOPT_USERNAME             => $at['user'],
			CURLOPT_PASSWORD             => $password,
			CURLOPT_FTP_SKIP_PASV_IP     => true, // P-C2: data connections go to the checked control address.
			CURLOPT_CONNECTTIMEOUT       => $timeout,
			CURLOPT_FTP_RESPONSE_TIMEOUT => $timeout,
			CURLOPT_LOW_SPEED_LIMIT      => 1,
			CURLOPT_LOW_SPEED_TIME       => $timeout,
		] + ( $at['passive'] ? [] : [ CURLOPT_FTPPORT => '-' ] ) + $pin;
		$self  = new self( FtpsTrust::options( $at['pin'], $probe, $where ), $base, self::url( $at['name'], $at['port'], $at['folder'] ), $at['pin'], $probe, $setopt, $where );
		$self->enter();

		return $self;
	}

	/**
	 * The URL of a folder (with a trailing slash): relative to the login
	 * folder, or from the server's root (%2F) when it starts with /.
	 *
	 * @param string $host   Host as stored.
	 * @param int    $port   Port.
	 * @param string $folder '' (the login folder), "a/b" or "/a/b".
	 */
	public static function url( string $host, int $port, string $folder ): string {
		$segments = array_values( array_filter( explode( '/', $folder ), static fn( string $s ): bool => '' !== $s ) );
		$path     = implode( '', array_map( static fn( string $s ): string => rawurlencode( $s ) . '/', $segments ) );

		return 'ftp://' . ( str_contains( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . $port . '/' . ( str_starts_with( $folder, '/' ) ? '%2F' : '' ) . $path;
	}

	public function features(): array {
		if ( 0 !== $this->perform(
			[
				CURLOPT_URL    => $this->dir,
				CURLOPT_NOBODY => true,
				CURLOPT_QUOTE  => [ 'FEAT' ],
			]
		)[0] ) {
			return [];
		}
		$start = null;
		foreach ( $this->replies as $i => $line ) {
			if ( null === $start && str_starts_with( $line, '211' ) ) {
				$start = $i;
			}
		}

		return null === $start ? [] : array_slice( $this->replies, $start );
	}

	public function size( string $name ): int {
		if ( ! self::plain( $name ) || 0 !== $this->perform(
			[
				CURLOPT_URL    => $this->dir . rawurlencode( $name ),
				CURLOPT_NOBODY => true,
			]
		)[0] ) {
			return -1;
		}

		return max( -1, (int) curl_getinfo( $this->curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T ) );
	}

	public function mtime( string $name ): int {
		if ( ! self::plain( $name ) || 0 !== $this->perform(
			[
				CURLOPT_URL      => $this->dir . rawurlencode( $name ),
				CURLOPT_NOBODY   => true,
				CURLOPT_FILETIME => true,
			]
		)[0] ) {
			return -1;
		}

		return max( -1, (int) curl_getinfo( $this->curl, CURLINFO_FILETIME ) );
	}

	public function names(): ?array {
		[ $errno, $body ] = $this->perform(
			[
				CURLOPT_URL         => $this->dir,
				CURLOPT_DIRLISTONLY => true,
			]
		);
		if ( 0 !== $errno ) {
			return null;
		}
		$lines = preg_split( '/\r?\n/', $body, -1, PREG_SPLIT_NO_EMPTY );

		return array_map( static fn( string $n ): string => basename( $n ), false === $lines ? [] : $lines );
	}

	public function store( string $name, string $bytes, int $offset ): bool {
		return $this->upload( $name, $bytes, $offset > 0 ? [ CURLOPT_PREQUOTE => [ 'REST ' . $offset ] ] : [] );
	}

	public function append( string $name, string $bytes ): bool {
		return $this->upload( $name, $bytes, [ CURLOPT_APPEND => true ] );
	}

	public function read( string $name, int $offset, int $length ): ?string {
		if ( ! self::plain( $name ) || $length < 1 ) {
			return $length < 1 ? '' : null;
		}
		[ $errno, $body ] = $this->perform(
			[
				CURLOPT_URL   => $this->dir . rawurlencode( $name ),
				CURLOPT_RANGE => $offset . '-' . ( $offset + $length - 1 ),
			]
		);
		if ( CurlFtpsFailure::NO_REST === $errno && ExtFtpConnection::partRefused( array_slice( $this->replies, -1 ), $offset ) && $this->size( $name ) >= 0 ) {
			throw ExtFtpConnection::partsRefused( $this->where );
		}

		return 0 === $errno ? substr( $body, 0, $length ) : null;
	}

	public function rename( string $from, string $to ): bool {
		return self::plain( $from ) && self::plain( $to ) && 0 === $this->perform(
			[
				CURLOPT_URL       => $this->dir,
				CURLOPT_NOBODY    => true,
				CURLOPT_POSTQUOTE => [ 'RNFR ' . $from, 'RNTO ' . $to ],
			]
		)[0];
	}

	public function delete( string $name ): bool {
		return self::plain( $name ) && 0 === $this->perform(
			[
				CURLOPT_URL       => $this->dir,
				CURLOPT_NOBODY    => true,
				CURLOPT_POSTQUOTE => [ 'DELE ' . $name ],
			]
		)[0];
	}

	public function close(): void {
		$this->curl = null; // Ends the control connection (cURL sends QUIT).
	}

	public function __destruct() {
		$this->close();
	}

	/**
	 * Logs in and changes into the folder, creating the missing levels.
	 *
	 * @throws PermanentFailure MISSING when the folder cannot be opened or created, perform()'s failures, and a TransientFailure when the connection breaks.
	 */
	private function enter(): void {
		$errno = $this->perform(
			[
				CURLOPT_URL                     => $this->dir,
				CURLOPT_NOBODY                  => true,
				CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
			]
		)[0];
		if ( 0 === $errno ) {
			return;
		}
		$this->close();
		if ( CurlFtpsFailure::ACCESS_DENIED === $errno ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( 'The folder cannot be opened or created at %s.', 'lw-migration' ), $this->where ), PermanentFailure::MISSING );
		}

		$broken = CurlFtpsFailure::brokenOff( $this->where ); // Any other answer to a CWD/MKD: the connection is not usable.
		throw $broken;
	}

	/**
	 * @param string            $name  The file.
	 * @param string            $bytes What to send.
	 * @param array<int, mixed> $extra STOR at an offset (REST), or APPE.
	 */
	private function upload( string $name, string $bytes, array $extra ): bool {
		if ( ! self::plain( $name ) ) {
			return false;
		}
		$in = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in memory, never spilled to a temp folder (P-M9).
		fwrite( $in, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		rewind( $in );
		try {
			return 0 === $this->perform(
				[
					CURLOPT_URL        => $this->dir . rawurlencode( $name ),
					CURLOPT_UPLOAD     => true,
					CURLOPT_INFILE     => $in,
					CURLOPT_INFILESIZE => strlen( $bytes ),
				] + $extra
			)[0];
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
	}

	/**
	 * Runs one request on the connection. Failures that are not about this
	 * one file throw (the connection is closed); the rest answer the error code.
	 *
	 * @param array<int, mixed> $request This request's options.
	 * @return array{0: int, 1: string} cURL's error code (0 = done), the body.
	 * @throws TransientFailure NETWORK when the connection broke or the server turned the login away for now.
	 * @throws PermanentFailure For a certificate to confirm, a refused login or FTPS, passive mode refused, or a cURL that cannot do it.
	 * @phpstan-impure
	 */
	private function perform( array $request ): array {
		if ( null === $this->curl ) {
			return [ -1, '' ];
		}
		$this->replies = [];
		curl_reset( $this->curl );
		$set = FtpsTrust::apply( $this->curl, $this->tls, $this->setopt ) && FtpsTrust::apply(
			$this->curl,
			$request + $this->options + [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADERFUNCTION => function ( $handle, string $line ): int {
					$this->replies[] = rtrim( $line );
					return strlen( $line );
				},
			],
			$this->setopt
		);
		if ( ! $set ) {
			$this->close(); // Never sent with an option missing (review I1: no fail-open TLS).
			$unsupported = new PermanentFailure( self::unsupported(), PermanentFailure::INVALID );
			throw $unsupported;
		}
		$body  = curl_exec( $this->curl );
		$errno = curl_errno( $this->curl );
		if ( 0 !== $errno ) {
			$this->refuse( $errno );
		}

		return [ $errno, is_string( $body ) ? $body : '' ];
	}

	/**
	 * Throws for a failure of the connection itself (CurlFtpsFailure); returns for one of the file.
	 *
	 * @param int $errno cURL's error code.
	 * @throws TransientFailure|PermanentFailure See perform().
	 */
	private function refuse( int $errno ): void {
		$failure = CurlFtpsFailure::connection( $errno, $this->replies, $this->where );
		if ( CurlFtpsFailure::unverified( $errno ) ) {
			$this->close();
			CertificatePin::check( $this->pin, ( $this->probe )()['fingerprint'], $this->where ); // Throws for another certificate than the confirmed one.
			$failure = CurlFtpsFailure::brokenOff( $this->where );
		}
		if ( null !== $failure ) {
			$this->close();
			throw $failure;
		}
	}

	/**
	 * A plain file name: no folder part, no control characters (they would end an FTP command).
	 *
	 * @param string $name File name.
	 */
	private static function plain( string $name ): bool {
		return '' !== $name && 1 !== preg_match( '#[\x00-\x1f\x7f/]#', $name );
	}
}

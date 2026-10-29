<?php
/**
 * A plain FTP connection through PHP's ext-ftp.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D28 (verified against vsftpd). It connects only to an address
 * ServerAddress::connectTo() checked (preflight P-C2), and turns off
 * FTP_USEPASVADDRESS before passive mode, so a data connection goes to that
 * same address, never to one the server announces. FTPS is never opened
 * here: ftp_ssl_connect() cannot check the server's certificate (issue #5),
 * so it goes through CurlFtpsConnection. FTP_AUTOSEEK is off —
 * with it on, ftp_fput() with an offset seeks our part-only stream past its
 * end and sends nothing. A range read stops the transfer once the bytes are
 * there, which ends this connection (the destination opens one per call).
 * Parts are sent from memory; APPE needs a file, written in this site's
 * storage folder and removed afterwards (P-M9). ext-ftp's warnings are
 * caught: they only classify — a broken transport (a timeout, a reset) is
 * a passing NETWORK failure (P-M12) — and
 * are never shown.
 */
final class ExtFtpConnection implements FtpConnection {

	/** Transport texts: ext-ftp's own (ext/ftp/ftp.c), the system's socket errors, and a server's 4xx data-connection and timeout replies. */
	private const BROKEN = '/timed? ?out|reset by peer|broken pipe|connection refused|unreachable|no route to host|host is down|connection abort|handshake failed|SSL (read|write) failed|failed to create the SSL|php_connect_nonb|socket\(\) failed|getsockname|data_accept|establish connection|open data connection/i';

	/**
	 * The ext-ftp connection: a resource on PHP 8.0, an \FTP\Connection from
	 * 8.1 (a class the 8.0 analysis floor does not know).
	 *
	 * @var mixed
	 */
	private $ftp;

	/**
	 * Whether the connection may still be used.
	 *
	 * @var bool
	 */
	private bool $open = true;

	/**
	 * The warnings of the last ext-ftp call, without the function name.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * @param mixed  $ftp   Connection.
	 * @param string $where ftp://host:port for messages.
	 */
	private function __construct( $ftp, private string $where ) {
		$this->ftp = $ftp;
	}

	/**
	 * Connects to the checked address, logs in and changes into the folder (creating it).
	 *
	 * @param array{host: string, port: int, tls: bool, passive: bool, user: string, folder: string} $at       Where; host is an address from ServerAddress::connectTo(); tls must be false.
	 * @param string                                                                                 $password Password.
	 * @param string                                                                                 $where    ftp://host:port for messages.
	 * @param int                                                                                    $timeout  Seconds.
	 * @throws \LogicException When $at['host'] is a name or FTPS is asked for (programming errors).
	 * @throws TransientFailure When the server cannot be reached or the connection breaks.
	 * @throws PermanentFailure When the login, passive mode or the folder is refused, or FTP is impossible here.
	 */
	public static function open( array $at, #[\SensitiveParameter] string $password, string $where, int $timeout ): self {
		if ( ! AddressGuard::isLiteral( $at['host'] ) || $at['tls'] ) {
			throw new \LogicException( 'A plain FTP connection goes to an address ServerAddress::connectTo() checked, never to a name; FTPS goes through CurlFtpsConnection.' );
		}
		if ( ! function_exists( 'ftp_connect' ) ) {
			throw new PermanentFailure( __( 'This server\'s PHP has no FTP extension (ext-ftp), so FTP destinations cannot be used here. Ask your host to enable it, or choose SFTP.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$ftp = @ftp_connect( $at['host'], $at['port'], $timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below in our words.
		if ( false === $ftp ) {
			/* translators: %s: server address */
			throw new TransientFailure( sprintf( __( '%s cannot be reached.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
		}
		ftp_set_option( $ftp, FTP_AUTOSEEK, false );
		ftp_set_option( $ftp, FTP_USEPASVADDRESS, false ); // P-C2: data connections go to the checked control address.
		$self = new self( $ftp, $where );
		$self->login( $at['user'], $password );
		if ( $at['passive'] && true !== $self->quiet( static fn( $f ) => ftp_pasv( $f, true ) ) ) {
			$self->close();
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s did not accept passive mode. Turn passive mode off, or ask the host.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
		}
		if ( ! $self->into( $at['folder'] ) ) {
			$self->close();
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( 'The folder cannot be opened or created at %s.', 'lw-migration' ), $where ), PermanentFailure::MISSING );
		}

		return $self;
	}

	/**
	 * What a refused login means (P-M12): a broken transport passes, and so
	 * does a server turning logins away for now ("421 too many
	 * connections", "try again later"); a refusal is AUTH, worded for a
	 * server that wants encryption, or for the user name and password.
	 *
	 * @param array<int, string> $warnings ext-ftp's warnings of the login.
	 * @param string             $where    ftp://host:port for messages.
	 */
	public static function loginFailure( array $warnings, string $where ): CloudFailure {
		if ( self::broken( $warnings ) ) {
			return self::brokenOff( $where );
		}
		if ( 1 === preg_match( '/too many|maximum number|try again|temporar/i', implode( "\n", $warnings ) ) ) {
			/* translators: %s: server address */
			return new TransientFailure( sprintf( __( '%s turned the login away for now (too many connections). Trying again later.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
		}
		if ( 1 === preg_match( '/encrypt|tls|ssl/i', implode( "\n", $warnings ) ) ) {
			/* translators: %s: server address */
			return new PermanentFailure( sprintf( __( '%s accepts logins only over an encrypted connection: choose FTPS.', 'lw-migration' ), $where ), PermanentFailure::AUTH );
		}

		/* translators: %s: server address */
		return new PermanentFailure( sprintf( __( '%s refused the user name or the password.', 'lw-migration' ), $where ), PermanentFailure::AUTH );
	}

	/**
	 * Whether ext-ftp's warnings tell of a broken transport rather than a server's refusal.
	 *
	 * @param array<int, string> $warnings Warnings of one call.
	 */
	public static function broken( array $warnings ): bool {
		return 1 === preg_match( self::BROKEN, implode( "\n", $warnings ) );
	}

	/**
	 * Whether the server refuses REST for good: its own answer to REST, with
	 * the code (ftp_raw()), is a permanent negative (5xx). ext-ftp's warnings
	 * carry no code and may be RETR's (php-src ext/ftp: ftp_getresp() strips
	 * the code; ftp_nb_get() sends REST, then RETR), so they are not used;
	 * a passing answer (4xx) or 350 is never a refusal.
	 *
	 * @param array<int, string> $restReply ftp_raw()'s lines for REST <offset>.
	 * @param int                $offset    Where the download was to start.
	 */
	public static function partRefused( array $restReply, int $offset ): bool {
		return $offset > 0 && 1 === preg_match( '/^5\d\d(\s|-|$)/', (string) ( $restReply[0] ?? '' ) );
	}

	/**
	 * @param string $where ftp(s)://host:port for messages.
	 */
	public static function partsRefused( string $where ): PermanentFailure {
		/* translators: %s: server address */
		return new PermanentFailure( sprintf( __( '%s cannot send part of a file (it refuses REST), so backups there cannot be restored. Choose SFTP, or ask the host to allow REST.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
	}

	public function features(): array {
		$lines = $this->quiet( static fn( $f ) => ftp_raw( $f, 'FEAT' ) );

		return is_array( $lines ) ? array_map( 'strval', $lines ) : [];
	}

	public function size( string $name ): int {
		$size = $this->quiet( static fn( $f ) => ftp_size( $f, $name ) );

		return is_int( $size ) ? $size : -1;
	}

	public function mtime( string $name ): int {
		$time = $this->quiet( static fn( $f ) => ftp_mdtm( $f, $name ) );

		return is_int( $time ) ? $time : -1;
	}

	public function names(): ?array {
		$names = $this->quiet( static fn( $f ) => ftp_nlist( $f, '.' ) );

		return is_array( $names ) ? array_map( static fn( $n ): string => basename( (string) $n ), $names ) : null;
	}

	public function store( string $name, string $bytes, int $offset ): bool {
		$in = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in memory, never spilled to a temp folder (P-M9).
		fwrite( $in, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		rewind( $in );
		try {
			return true === $this->quiet( static fn( $f ) => ftp_fput( $f, $name, $in, FTP_BINARY, $offset ) );
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
	}

	public function append( string $name, string $bytes ): bool {
		$storage = StorageDir::stored();
		$temp    = null === $storage ? '' : TempName::generate( $storage->jobsDir() );
		if ( '' === $temp || false === @file_put_contents( $temp, $bytes ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- ftp_append() reads a file; never a warning with our path.
			/* translators: %s: server address */
			throw new TransientFailure( sprintf( __( 'This site could not prepare the part for %s in its storage folder.', 'lw-migration' ), $this->where ), TransientFailure::SERVER );
		}
		try {
			return true === $this->quiet( static fn( $f ) => ftp_append( $f, $name, $temp, FTP_BINARY ) );
		} finally {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- our temp file; never a warning with its path.
		}
	}

	public function read( string $name, int $offset, int $length ): ?string {
		$out = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in memory, never spilled to a temp folder (P-M9).
		try {
			$state = $this->quiet( static fn( $f ) => ftp_nb_fget( $f, $out, $name, FTP_BINARY, $offset ) );
			while ( FTP_MOREDATA === $state && (int) fstat( $out )['size'] < $length ) {
				$state = $this->quiet( static fn( $f ) => ftp_nb_continue( $f ) );
			}
			if ( FTP_MOREDATA === $state ) {
				$this->abandon(); // The transfer is cut short: this connection is done.
			}
			if ( ( FTP_FAILED === $state || false === $state ) && $offset > 0 ) {
				$this->restRefusal( $name, $offset );
			}
			rewind( $out );

			return FTP_FAILED === $state || false === $state ? null : (string) stream_get_contents( $out, $length );
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
	}

	/**
	 * After a failed download from inside a file: asks REST again and, when
	 * the server refuses it for good and the file is there, stops for good.
	 * An accepted REST is undone (REST 0), so the caller's next command on
	 * this connection is not taken for a resumed transfer.
	 *
	 * @param string $name   The file.
	 * @param int    $offset Where the download was to start.
	 * @throws PermanentFailure INVALID when the server refuses REST.
	 */
	private function restRefusal( string $name, int $offset ): void {
		$reply = $this->quiet( static fn( $f ) => ftp_raw( $f, 'REST ' . $offset ) );
		$lines = is_array( $reply ) ? array_map( 'strval', $reply ) : [];
		if ( self::partRefused( $lines, $offset ) && $this->size( $name ) >= 0 ) {
			throw self::partsRefused( $this->where );
		}
		if ( str_starts_with( (string) ( $lines[0] ?? '' ), '350' ) ) {
			$this->quiet( static fn( $f ) => ftp_raw( $f, 'REST 0' ) );
		}
	}

	public function rename( string $from, string $to ): bool {
		return true === $this->quiet( static fn( $f ) => ftp_rename( $f, $from, $to ) );
	}

	public function delete( string $name ): bool {
		return true === $this->quiet( static fn( $f ) => ftp_delete( $f, $name ) );
	}

	/** A connection nobody closed is closed quietly too (ext-ftp warns when a connection ends abruptly). */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Ends a connection whose download was cut short. The server answers
	 * QUIT only once it sent the rest of the file, which nobody reads any
	 * more: wait a second for that answer, not the connection's timeout
	 * (ext-ftp has no ABOR).
	 */
	private function abandon(): void {
		if ( $this->open ) {
			ftp_set_option( $this->ftp, FTP_TIMEOUT_SEC, 1 );
		}
		$this->close();
	}

	public function close(): void {
		if ( $this->open ) {
			$this->open = false;
			@ftp_close( $this->ftp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- closing a connection the server may have dropped.
		}
	}

	/**
	 * @param string $user     User name.
	 * @param string $password Password.
	 * @throws CloudFailure AUTH, or NETWORK when the connection broke.
	 */
	private function login( string $user, #[\SensitiveParameter] string $password ): void {
		if ( true === $this->quiet( static fn( $f ) => ftp_login( $f, $user, $password ), false ) ) {
			return;
		}
		$this->close();

		throw self::loginFailure( $this->warnings, $this->where );
	}

	/**
	 * Changes into the folder, creating each missing level.
	 *
	 * @param string $folder '' (the login folder), "a/b" or "/a/b".
	 */
	private function into( string $folder ): bool {
		if ( '' === $folder ) {
			return true;
		}
		if ( str_starts_with( $folder, '/' ) && true !== $this->quiet( static fn( $f ) => ftp_chdir( $f, '/' ) ) ) {
			return false;
		}
		foreach ( array_filter( explode( '/', trim( $folder, '/' ) ), static fn( string $segment ): bool => '' !== $segment ) as $segment ) {
			if ( true === $this->quiet( static fn( $f ) => ftp_chdir( $f, $segment ) ) ) {
				continue;
			}
			if ( false === $this->quiet( static fn( $f ) => ftp_mkdir( $f, $segment ) ) || true !== $this->quiet( static fn( $f ) => ftp_chdir( $f, $segment ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Runs one ext-ftp call with its warnings caught (kept for classification).
	 *
	 * @param \Closure $call  fn( mixed $ftp ): mixed.
	 * @param bool     $raise Whether a broken transport throws (the login classifies it itself).
	 * @return mixed What ext-ftp answered; false on a closed connection.
	 * @throws TransientFailure NETWORK when the transport broke (the connection is closed).
	 * @phpstan-impure
	 */
	private function quiet( \Closure $call, bool $raise = true ) {
		if ( ! $this->open ) {
			return false;
		}
		$warnings = [];
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- narrow: ext-ftp's warnings for one call.
			static function ( int $severity, string $message ) use ( &$warnings ): bool {
				$warnings[] = (string) preg_replace( '/^ftp_\w+\(\): /', '', $message );
				return true;
			}
		);
		try {
			$answer = $call( $this->ftp );
		} finally {
			restore_error_handler();
		}
		$this->warnings = $warnings;
		if ( $raise && self::broken( $warnings ) ) {
			$this->close();
			throw self::brokenOff( $this->where );
		}

		return $answer;
	}

	private static function brokenOff( string $where ): TransientFailure {
		/* translators: %s: server address */
		return new TransientFailure( sprintf( __( 'The connection to %s broke off.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
	}
}

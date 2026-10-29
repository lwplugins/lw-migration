<?php
/**
 * Where an archive password given to a command comes from.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * The safe forms first: --password-file=<path>, and --password without a
 * value (a hidden prompt on a terminal, otherwise one line of STDIN).
 * --password=<value> still works, but stays in the process list and the shell
 * history. One trailing line ending is cut, nothing else; the path and the
 * value never reach a message. An empty file or line is no password (the
 * caller refuses it).
 */
final class PasswordSource {

	public const FILE_FLAG = 'password-file';

	/**
	 * @param array<string, mixed> $assoc       Command flags (the caller made sure one of the two flags is there).
	 * @param bool                 $interactive STDIN is a terminal.
	 * @param \Closure|null        $line        fn(): ?string, one STDIN line (default lineOf( STDIN )).
	 * @return string|null The password ('' = none given); null = ask on the terminal.
	 * @throws \RuntimeException Both flags, a file that cannot be read, or no input (translated).
	 */
	public static function read( array $assoc, bool $interactive, ?\Closure $line = null ): ?string {
		$hasFile = array_key_exists( self::FILE_FLAG, $assoc );
		if ( $hasFile && array_key_exists( 'password', $assoc ) ) {
			throw new \RuntimeException( __( 'Give the password with --password-file=<path> or with --password, not both.', 'lw-migration' ) );
		}
		if ( $hasFile ) {
			return self::file( $assoc[ self::FILE_FLAG ] );
		}
		if ( true !== $assoc['password'] ) {
			return (string) $assoc['password'];
		}
		if ( $interactive ) {
			return null;
		}

		return self::stdin( $line ?? ( defined( 'STDIN' ) ? self::lineOf( STDIN ) : static fn(): ?string => null ) );
	}

	/**
	 * @param mixed $path The flag's value (true when it had none).
	 * @throws \RuntimeException When the file cannot be read or is larger than the cap (translated, without the path).
	 */
	private static function file( $path ): string {
		if ( ! is_string( $path ) || '' === $path ) {
			throw new \RuntimeException( __( 'Give the path of the password file: --password-file=<path>.', 'lw-migration' ) );
		}
		$size = is_file( $path ) && is_readable( $path ) ? filesize( $path ) : false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would print the path.
		$bytes = false === $size || $size > SecretFiles::MAX_BYTES ? false : @file_get_contents( $path, false, null, 0, SecretFiles::MAX_BYTES + 1 );
		if ( false === $bytes || strlen( $bytes ) > SecretFiles::MAX_BYTES ) {
			/* translators: %s: option name */
			throw new \RuntimeException( sprintf( __( 'The file given with --%s cannot be read, or is larger than 16 KB.', 'lw-migration' ), self::FILE_FLAG ) );
		}
		if ( '' === self::cut( $bytes ) ) {
			throw new \RuntimeException( __( 'The file given with --password-file holds no password.', 'lw-migration' ) );
		}

		return self::cut( $bytes );
	}

	/**
	 * @param \Closure $line fn(): ?string.
	 * @throws \RuntimeException At end of input, or for a line longer than the cap (translated).
	 */
	private static function stdin( \Closure $line ): string {
		$read = $line();
		if ( null === $read ) {
			throw new \RuntimeException( __( 'No password entered. Give it with --password-file=<path>, or as one line on standard input.', 'lw-migration' ) );
		}
		if ( strlen( $read ) > SecretFiles::MAX_BYTES + 2 || strlen( self::cut( $read ) ) > SecretFiles::MAX_BYTES ) {
			throw new \RuntimeException( __( 'The password on standard input is longer than 16 KB.', 'lw-migration' ) );
		}

		return self::cut( $read );
	}

	/**
	 * @param string $text Text read.
	 * @return string Without one trailing line ending.
	 */
	private static function cut( string $text ): string {
		foreach ( [ "\r\n", "\n" ] as $ending ) {
			if ( str_ends_with( $text, $ending ) ) {
				return substr( $text, 0, -strlen( $ending ) );
			}
		}

		return $text;
	}

	/**
	 * One line of a stream. fgets() reads at most length - 1 bytes, so this
	 * reads one byte past a 16 KB password with a \r\n ending: a longer line
	 * is seen and refused, never cut short.
	 *
	 * @param resource $stream An open stream (STDIN).
	 * @return \Closure fn(): ?string, null at end of input.
	 */
	public static function lineOf( $stream ): \Closure {
		return static function () use ( $stream ): ?string {
			$read = fgets( $stream, SecretFiles::MAX_BYTES + 4 );

			return false === $read ? null : $read;
		};
	}
}

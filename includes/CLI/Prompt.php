<?php
/**
 * Password input for commands.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * The archive password of a command: --password-file=<path>, --password
 * alone (a hidden prompt on a terminal, otherwise one line of STDIN) or
 * --password=<value>. A value on the command line is visible to other users
 * of the server in the process list (and may land in the shell history); the
 * other forms keep it out of both (PasswordSource).
 */
final class Prompt {

	/**
	 * @param array<string, mixed> $assoc    Command flags.
	 * @param bool                 $repeat   Ask twice (new password).
	 * @param bool                 $required Ask even when no flag was given (an encrypted archive).
	 * @return string|null Null when no password flag was given.
	 */
	public static function password( array $assoc, bool $repeat, bool $required = false ): ?string {
		if ( ! array_key_exists( 'password', $assoc ) && ! array_key_exists( PasswordSource::FILE_FLAG, $assoc ) ) {
			if ( ! $required ) {
				return null;
			}
			$assoc['password'] = true;
		}
		try {
			$value = PasswordSource::read( $assoc, Confirmation::stdinIsInteractive() );
		} catch ( \RuntimeException $e ) {
			Console::error( $e->getMessage() );
			return null;
		}
		if ( null === $value ) {
			$value = self::ask( __( 'Password', 'lw-migration' ) );
			if ( $repeat && self::ask( __( 'Repeat the password', 'lw-migration' ) ) !== $value ) {
				Console::error( __( 'The passwords do not match.', 'lw-migration' ) );
			}
		}
		if ( '' === $value ) {
			Console::error( __( 'The password cannot be empty.', 'lw-migration' ) );
		}

		return $value;
	}

	/**
	 * A hidden prompt that never exits: usable while a job holds the site lock.
	 * It asks again on an empty answer, unless the answer is optional (an
	 * SSH key without passphrase): then Enter answers ''.
	 *
	 * @param string $question Prompt text.
	 * @param bool   $optional An empty answer is accepted.
	 * @return string|null Null at end of input.
	 */
	public static function hidden( string $question, bool $optional = false ): ?string {
		return self::read( $question, ': ', true, $optional );
	}

	/**
	 * A visible prompt (e.g. "[y/n]") that never exits.
	 *
	 * @param string $question Prompt text.
	 * @return string|null Null at end of input.
	 */
	public static function line( string $question ): ?string {
		return self::read( $question, ' ', false );
	}

	/**
	 * End of input (Ctrl-D) makes WP-CLI's prompt throw a bare \Exception; the
	 * line is ended so the next message starts on its own line.
	 *
	 * @param string $question Prompt text.
	 * @param string $marker   Text after the question.
	 * @param bool   $hide     Hide the input.
	 * @param bool   $optional An empty answer is accepted ('' as the default; php-cli-tools asks again only while the default is false).
	 * @return string|null Null at end of input.
	 */
	private static function read( string $question, string $marker, bool $hide, bool $optional = false ): ?string {
		$shown = Console::promptText( $question, class_exists( \cli\Colors::class ) && \cli\Colors::shouldColorize() );
		try {
			return (string) \cli\prompt( $shown, $optional ? '' : false, $marker, $hide );
		} catch ( \Exception $e ) {
			\cli\line();
			return null;
		}
	}

	/**
	 * @param string $question Prompt text.
	 * @return string
	 */
	private static function ask( string $question ): string {
		$value = self::hidden( $question );
		if ( null === $value ) {
			Console::error( __( 'No password entered.', 'lw-migration' ) );
			return '';
		}

		return $value;
	}
}

<?php
/**
 * Yes/no confirmation that never passes silently.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * --yes confirms; without a terminal --yes is required (a script must not
 * hang on, or be fed, a prompt); otherwise only "y" confirms.
 */
final class Confirmation {

	public const CONFIRMED = 'confirmed';

	public const NEEDS_YES = 'needs-yes';

	public const DECLINED = 'declined';

	/**
	 * @param array<string, mixed> $assoc       Command flags.
	 * @param bool                 $interactive Whether STDIN is a terminal.
	 * @param callable             $ask         fn(): ?string, the answer or null at end of input.
	 * @return string One of the constants.
	 */
	public static function decide( array $assoc, bool $interactive, callable $ask ): string {
		if ( ! empty( $assoc['yes'] ) ) {
			return self::CONFIRMED;
		}
		if ( self::requiresYes( $assoc, $interactive ) ) {
			return self::NEEDS_YES;
		}
		$answer = $ask();

		return null !== $answer && 'y' === strtolower( trim( $answer ) ) ? self::CONFIRMED : self::DECLINED;
	}

	/**
	 * Whether the command must stop for --yes (no --yes and no terminal to ask on).
	 * Lets a command refuse before it starts work that would ask later.
	 *
	 * @param array<string, mixed> $assoc       Command flags.
	 * @param bool                 $interactive Whether STDIN is a terminal.
	 */
	public static function requiresYes( array $assoc, bool $interactive ): bool {
		return empty( $assoc['yes'] ) && ! $interactive;
	}

	public static function stdinIsInteractive(): bool {
		if ( ! defined( 'STDIN' ) ) {
			return false;
		}
		if ( function_exists( 'stream_isatty' ) ) {
			return stream_isatty( STDIN );
		}

		return function_exists( 'posix_isatty' ) && posix_isatty( STDIN );
	}
}

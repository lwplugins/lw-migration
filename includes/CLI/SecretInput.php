<?php
/**
 * Secrets typed at a prompt or piped in.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Storage\Destinations\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.7 ("Titok CLI-ben csak --prompt / stdin útján"): on a terminal a
 * hidden prompt per secret field; otherwise one line of STDIN per field, in
 * the schema's order (the ResetCommand pattern). The line ending is cut; an
 * empty required secret stops the command, an empty optional one is left
 * out (on a terminal, Enter skips it). Values never reach output. Only the
 * secret fields shown with the given settings reach it; multi-line ones come
 * from files (SecretFiles).
 */
final class SecretInput {

	/**
	 * @param array<int, Field> $fields      One-line secret fields shown with the settings.
	 * @param bool              $interactive STDIN is a terminal.
	 * @param \Closure|null     $line        fn(): ?string — one STDIN line (default fgets).
	 * @param \Closure|null     $hidden      fn( string $question, bool $optional ): ?string — a hidden prompt (default Prompt::hidden()).
	 * @return array<string, string> Non-empty values.
	 * @throws \RuntimeException When a required secret is empty (translated).
	 */
	public static function read( array $fields, bool $interactive, ?\Closure $line = null, ?\Closure $hidden = null ): array {
		$line   = $line ?? static function (): ?string {
			$read = defined( 'STDIN' ) ? fgets( STDIN ) : false;
			return false === $read ? null : $read;
		};
		$hidden = $hidden ?? static fn( string $question, bool $optional ): ?string => Prompt::hidden( $question, $optional );
		$values = [];
		foreach ( $fields as $field ) {
			/* translators: %s: name of the secret, e.g. Passphrase */
			$question = $field->required ? $field->label : sprintf( __( '%s (Enter if none)', 'lw-migration' ), $field->label );
			$value    = rtrim( (string) ( $interactive ? $hidden( $question, ! $field->required ) : $line() ), "\r\n" );
			if ( '' === $value && $field->required ) {
				/* translators: %s: name of the secret, e.g. Secret access key */
				throw new \RuntimeException( sprintf( __( 'Enter the %s (hidden prompt on a terminal, or one line on standard input).', 'lw-migration' ), $field->label ) );
			}
			if ( '' !== $value ) {
				$values[ $field->key ] = $value;
			}
		}

		return $values;
	}
}

<?php
/**
 * Multi-line secrets read from files given on the command line.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Storage\Destinations\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D33: a SECRET_TEXT field (an SSH private key) comes from
 * --<field>-file=<path>. The path is not secret; the key never enters the
 * shell history and never a STDIN line (which holds one-line secrets). The
 * path is never repeated in a message. Line ends are unified and the ends
 * trimmed, as the REST input does.
 */
final class SecretFiles {

	public const MAX_BYTES = 16384;

	/**
	 * @param array<int, Field>    $fields Multi-line secret fields shown with the given settings.
	 * @param array<string, mixed> $assoc  Flags.
	 * @return array<string, string> Field => value.
	 * @throws \RuntimeException When a required one is missing or empty, or a file cannot be read or is too large (translated).
	 */
	public static function read( array $fields, array $assoc ): array {
		$values = [];
		foreach ( $fields as $field ) {
			$flag = DestinationFlags::flag( $field );
			$path = is_string( $assoc[ $flag ] ?? null ) ? $assoc[ $flag ] : '';
			if ( '' === $path && ! array_key_exists( $flag, $assoc ) ) {
				if ( $field->required ) {
					/* translators: 1: name of the secret, e.g. Private key, 2: option name */
					throw new \RuntimeException( sprintf( __( 'Give the %1$s as a file: --%2$s=<path>.', 'lw-migration' ), $field->label, $flag ) );
				}
				continue;
			}
			$value = self::file( $path, $flag );
			if ( '' === $value && $field->required ) {
				/* translators: 1: name of the secret, e.g. Private key, 2: option name */
				throw new \RuntimeException( sprintf( __( 'The file given with --%2$s holds no %1$s.', 'lw-migration' ), $field->label, $flag ) );
			}
			if ( '' !== $value ) {
				$values[ $field->key ] = $value;
			}
		}

		return $values;
	}

	/**
	 * @param string $path File path ('' when the flag had no value).
	 * @param string $flag Option name, for the message.
	 * @throws \RuntimeException When the file cannot be read or is larger than MAX_BYTES (translated, without the path).
	 */
	private static function file( string $path, string $flag ): string {
		$size = '' !== $path && is_file( $path ) && is_readable( $path ) ? filesize( $path ) : false;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would print the path.
		$bytes = false === $size || $size > self::MAX_BYTES ? false : @file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );
		if ( false === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			/* translators: %s: option name */
			throw new \RuntimeException( sprintf( __( 'The file given with --%s cannot be read, or is larger than 16 KB.', 'lw-migration' ), $flag ) );
		}

		return trim( str_replace( "\r\n", "\n", $bytes ) );
	}
}

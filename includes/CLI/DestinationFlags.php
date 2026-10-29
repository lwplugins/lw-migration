<?php
/**
 * Command-line flags of a destination type's fields.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Rest\DestinationInput;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a type's schema to `destination add` flags: --<field> (kebab-case) for
 * a setting, --<field>-file for a multi-line secret; a secret is never a
 * flag value. Splits the flags into settings and the secret fields those
 * settings show, and names the flag a refusal is about.
 */
final class DestinationFlags {

	/**
	 * Schema keys that WP-CLI would take as its own global parameters (the
	 * user to run as, the site's address) and never pass to the command.
	 */
	private const GLOBAL_NAMES = [
		'user' => 'username',
		'url'  => 'address',
	];

	/**
	 * @param string $key Schema key (camelCase).
	 */
	public static function kebab( string $key ): string {
		return self::GLOBAL_NAMES[ $key ] ?? strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $key ) );
	}

	/**
	 * @param DestinationType      $type  Type.
	 * @param array<string, mixed> $assoc Flags.
	 * @return array{0: array<string, mixed>, 1: array<int, Field>, 2: array<int, Field>} Settings, one-line secrets to ask, multi-line secrets to read from files — only the shown ones.
	 */
	public static function split( DestinationType $type, array $assoc ): array {
		$known    = [ 'name' => true ];
		$settings = [];
		foreach ( $type->fields() as $field ) {
			if ( Field::OAUTH === $field->kind ) {
				continue; // The connect panel has no value: `connect` does it (M7c).
			}
			$flag           = self::kebab( $field->key );
			$known[ $flag ] = true;
			if ( $field->isSecret() ) {
				$known[ self::flag( $field ) ] = true;
				if ( array_key_exists( $flag, $assoc ) ) {
					self::refuseSecretFlag( $field );
				}
				continue;
			}
			if ( array_key_exists( $flag, $assoc ) ) {
				if ( Field::TOGGLE !== $field->kind && true === $assoc[ $flag ] ) { // A text flag given without a value.
					/* translators: %s: option name */
					Console::error( sprintf( __( 'The option %s needs a value.', 'lw-migration' ), '--' . $flag ) );
				}
				// A toggle passes through unchanged: the type refuses a value that is not a boolean (--path-style=false), naming the field.
				$settings[ $field->key ] = Field::TOGGLE === $field->kind ? $assoc[ $flag ] : (string) $assoc[ $flag ];
			}
		}
		if ( $type instanceof OAuthDestinationType && ! array_key_exists( OAuthMode::OWN_APP, $settings ) && self::ownAppOnly( $type, $settings ) ) {
			$settings[ OAuthMode::OWN_APP ] = true; // --client-id (or --tenant, --access) alone means the own app, never a silently dropped value.
		}
		foreach ( array_keys( $assoc ) as $flag ) {
			if ( ! isset( $known[ $flag ] ) ) {
				/* translators: %s: option name */
				Console::error( sprintf( __( 'Unknown option %s for this destination type.', 'lw-migration' ), '--' . $flag ) );
			}
		}

		return array_merge( [ $settings ], self::shownSecrets( $type, $settings, $assoc ) );
	}

	/**
	 * Whether the flags set a field shown only for the own app (its `when`
	 * is the switch on: the Client ID, OneDrive's tenant and permission).
	 *
	 * @param DestinationType      $type     Type.
	 * @param array<string, mixed> $settings Settings from the flags.
	 */
	private static function ownAppOnly( DestinationType $type, array $settings ): bool {
		foreach ( $type->fields() as $field ) {
			if ( array_key_exists( $field->key, $settings ) && [ OAuthMode::OWN_APP => [ true ] ] === $field->when ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The secret fields these settings show (with the type's defaults filled
	 * in, as the REST input checks them), split by how they are read. These
	 * are the raw flag values, while REST decides on the settings the type
	 * normalised: the same for the select/text values a `when` names; should
	 * a type ever normalise such a value, the REST check is the one that
	 * refuses a missing secret.
	 *
	 * @param DestinationType      $type     Type.
	 * @param array<string, mixed> $settings Settings from the flags.
	 * @param array<string, mixed> $assoc    Flags (a file given for a hidden multi-line secret is refused).
	 * @return array{0: array<int, Field>, 1: array<int, Field>} One-line secrets, multi-line secrets.
	 */
	private static function shownSecrets( DestinationType $type, array $settings, array $assoc ): array {
		$shown = DestinationInput::withDefaults( $type, $settings );
		$split = [ [], [] ];
		foreach ( $type->fields() as $field ) {
			if ( $field->isSecret() && $field->visible( $shown ) ) {
				$split[ Field::SECRET_TEXT === $field->kind ? 1 : 0 ][] = $field;
			} elseif ( Field::SECRET_TEXT === $field->kind && array_key_exists( self::flag( $field ), $assoc ) ) {
				self::refuseHiddenFile( $field );
			}
		}

		return $split;
	}

	/**
	 * A key file for a key the settings do not use would be dropped while
	 * the other secrets are read (a piped line would become the password):
	 * refused, naming the flag and the setting that uses it, never the path.
	 *
	 * @param Field $field A multi-line secret field the settings hide.
	 */
	private static function refuseHiddenFile( Field $field ): void {
		$needs = [];
		foreach ( $field->when as $key => $values ) {
			foreach ( $values as $value ) {
				$needs[] = '--' . self::kebab( (string) $key ) . '=' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
			}
		}
		/* translators: 1: option name, 2: the option(s) it needs, e.g. --auth=key */
		Console::error( sprintf( __( 'The option %1$s is used only with %2$s.', 'lw-migration' ), '--' . self::flag( $field ), implode( ' / ', $needs ) ) );
	}

	/**
	 * @param Field $field A secret field given as a flag.
	 */
	private static function refuseSecretFlag( Field $field ): void {
		$flag = '--' . self::kebab( $field->key );
		if ( Field::SECRET_TEXT === $field->kind ) {
			/* translators: 1: option name, 2: option name with -file */
			Console::error( sprintf( __( 'Secrets are never options (%1$s would stay in the shell history). Give it as a file instead: %2$s=<path>.', 'lw-migration' ), $flag, '--' . self::flag( $field ) ) );
		}
		/* translators: %s: option name */
		Console::error( sprintf( __( 'Secrets are never options (%s would stay in the shell history). Leave it out: it is asked for, or read from standard input.', 'lw-migration' ), $flag ) );
	}

	/**
	 * The flag a field's value is given with: --<field>, a multi-line secret --<field>-file.
	 *
	 * @param Field $field Field.
	 */
	public static function flag( Field $field ): string {
		return self::kebab( $field->key ) . ( Field::SECRET_TEXT === $field->kind ? '-file' : '' );
	}

	/**
	 * @param \Throwable      $e    A refusal.
	 * @param DestinationType $type The type (its fields name the flags, P-M5).
	 * @param string          $id   The destination's ID, when it exists: an OAuth destination to connect again names the connect command (its text says "Choose Connect", the admin page's word).
	 */
	public static function text( \Throwable $e, DestinationType $type, string $id = '' ): string {
		$key = $e instanceof RestException ? (string) ( $e->data['field'] ?? '' ) : '';
		if ( '' === $key ) {
			if ( '' !== $id && $type instanceof OAuthDestinationType && $e instanceof RestException && PermanentFailure::REAUTH === ( $e->data['reason'] ?? null ) ) {
				/* translators: 1: why the destination needs a connection, 2: destination ID */
				return sprintf( __( '%1$s On the command line: wp lw-migration destination connect %2$s --user=<an administrator\'s login>', 'lw-migration' ), $e->getMessage(), $id );
			}
			return $e->getMessage();
		}
		$flag = self::kebab( $key );
		foreach ( $type->fields() as $field ) {
			if ( $field->key === $key ) {
				$flag = self::flag( $field );
			}
		}

		return sprintf( '%s (--%s)', $e->getMessage(), $flag );
	}
}

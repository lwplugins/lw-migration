<?php
/**
 * A destination request turned into a profile and its secrets.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Destinations\AuthorityBound;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Keys: type (a new profile only; it never changes), name, settings (the
 * type's non-secret fields, checked by the type), secrets (only the type's
 * secret fields; '' or missing keeps the stored value; a required one that
 * the settings show must be sent unless the vault already holds it, plan
 * D33), retention. A refusal names its field (data.field); its text never
 * repeats a submitted value. An OAuth profile whose Client ID changes
 * loses its connection and its tokens (`forget`): they belong to the app
 * that issued them (M7c plan E16), and it needs that app's client secret
 * too; a new client secret alone keeps both. A change the type says moves
 * the tokens to another authority or consent (OneDrive's tenant or
 * permission, OAuthDestinationType::sameGrant()) forgets them the same way
 * but keeps the client secret. The password types do the same by server
 * (AuthorityBound): another host, port, security (FTP, SFTP), origin
 * (WebDAV) or endpoint, provider, account (S3) forgets the stored secrets
 * and the type's other authority-bound fields, and the secrets must be
 * entered again; an unchanged server keeps them.
 */
final class DestinationInput {

	private const KEYS = [ 'type', 'name', 'settings', 'secrets', 'retention' ];

	/**
	 * An 8 192-bit RSA key in OpenSSH format is ~6.5 KB; plan D33.
	 */
	private const SECRET_BYTES = 16384;

	/**
	 * @param Profile|null         $current  Stored profile (null: a new one).
	 * @param DestinationRegistry  $registry Types.
	 * @param array<string, mixed> $body     Request.
	 * @param array<int, string>   $stored   Secret fields already stored (the vault's fields(); none for a new profile).
	 * @return array{profile: Profile, secrets: array<string, string>, forget: array<int, string>} forget: vault fields to remove (the tokens, when an OAuth profile's Client ID changed).
	 * @throws RestException On an unknown key or a refused value.
	 */
	public static function apply( ?Profile $current, DestinationRegistry $registry, #[\SensitiveParameter] array $body, array $stored = [] ): array {
		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				throw RestException::invalidField( (string) $key );
			}
		}
		$type    = self::type( $current, $registry, $body['type'] ?? null );
		$profile = null === $current ? new Profile( Profile::newId(), $type->key(), '' ) : clone $current;
		if ( null === $current || array_key_exists( 'name', $body ) ) {
			$profile->name = Profile::name( $body['name'] ?? '' );
			if ( '' === $profile->name ) {
				throw RestException::invalid( __( 'Give the destination a name.', 'lw-migration' ), 'name' );
			}
		}
		if ( null === $current || array_key_exists( 'settings', $body ) ) {
			$profile->settings = self::settings( $type, $body['settings'] ?? [] );
		}
		if ( array_key_exists( 'retention', $body ) ) {
			$profile->retention = Profile::retention( ScheduleInput::retention( $body['retention'], $profile->retention ) );
		}

		$forget = [];
		if ( null !== $current && $type instanceof OAuthDestinationType && ( $current->settings[ OAuthDestinationType::CLIENT_ID ] ?? '' ) !== ( $profile->settings[ OAuthDestinationType::CLIENT_ID ] ?? '' ) ) {
			$profile->connection = [];
			$forget              = TokenKeeper::FIELDS;
			$stored              = array_diff( $stored, [ OAuthDestinationType::CLIENT_SECRET ] ); // The old secret belongs to the old app.
		} elseif ( null !== $current && $type instanceof OAuthDestinationType && ! $type->sameGrant( $current->settings, $profile->settings ) ) {
			$profile->connection = []; // Tokens of another authority or consent never refresh against the new one; the secret stays.
			$forget              = TokenKeeper::FIELDS;
		}

		$moved   = null !== $current && $type instanceof AuthorityBound && $type->authority( $current->settings ) !== $type->authority( $profile->settings ) ? $type->authorityFields() : [];
		$held    = array_values( array_intersect( $stored, $moved ) );
		$stored  = array_values( array_diff( $stored, $moved ) ); // The stored secret belonged to the old server: it is asked for again.
		$secrets = self::secrets( $type, $body['secrets'] ?? [], $profile->settings, $stored );
		foreach ( $moved as $key ) {
			if ( ( $profile->settings[ $key ] ?? '' ) === ( $current->settings[ $key ] ?? '' ) ) {
				unset( $profile->settings[ $key ] ); // A non-secret field tied to the old server (a pinned certificate); a new one sent with the move stays.
			}
		}

		return [
			'profile' => $profile,
			'secrets' => $secrets,
			'forget'  => array_merge( $forget, array_values( array_diff( $held, array_keys( $secrets ) ) ) ),
		];
	}

	/**
	 * Takes only the sent type value, never the body (it carries the secrets).
	 *
	 * @param Profile|null        $current  Stored profile.
	 * @param DestinationRegistry $registry Types.
	 * @param mixed               $sent     The request's type (null: not sent).
	 * @throws RestException On an unknown, unavailable or changed type.
	 */
	private static function type( ?Profile $current, DestinationRegistry $registry, $sent ): DestinationType {
		$key = $sent ?? ( null === $current ? '' : $current->type );
		if ( null !== $current && $key !== $current->type ) {
			throw RestException::invalid( __( 'The type of a destination cannot change. Add a new destination instead.', 'lw-migration' ), 'type' );
		}
		$type = is_string( $key ) ? $registry->get( $key ) : null;
		if ( null === $type ) {
			throw RestException::invalid( __( 'Choose a destination type.', 'lw-migration' ), 'type' );
		}
		$reason = $type->unavailable();
		if ( null !== $reason && null === $current ) {
			throw RestException::invalid( $reason, 'type' );
		}

		return $type;
	}

	/**
	 * @param DestinationType $type Type.
	 * @param mixed           $raw  Submitted settings.
	 * @return array<string, mixed>
	 * @throws RestException On a refused value.
	 */
	private static function settings( DestinationType $type, $raw ): array {
		if ( ! is_array( $raw ) ) {
			throw RestException::invalidField( 'settings' );
		}
		$secret = array_map( static fn( Field $f ): string => $f->key, array_filter( $type->fields(), static fn( Field $f ): bool => $f->isSecret() ) );
		try {
			return $type->settings( array_diff_key( $raw, array_flip( $secret ) ) );
		} catch ( FieldException $e ) {
			throw RestException::invalid( $e->getMessage(), $e->field );
		}
	}

	/**
	 * @param DestinationType      $type     Type.
	 * @param mixed                $raw      Submitted secrets.
	 * @param array<string, mixed> $settings The profile's settings after this request (they decide which fields are shown).
	 * @param array<int, string>   $stored   Secret fields already stored.
	 * @return array<string, string> Non-empty values to store.
	 * @throws RestException On a field that is not a secret of the type, a malformed value, or a shown required one that is neither sent nor stored.
	 */
	private static function secrets( DestinationType $type, #[\SensitiveParameter] $raw, array $settings, array $stored ): array {
		if ( ! is_array( $raw ) ) {
			throw RestException::invalidField( 'secrets' );
		}
		$fields = [];
		foreach ( $type->fields() as $field ) {
			if ( $field->isSecret() ) {
				$fields[ $field->key ] = $field;
			}
		}
		$values = [];
		foreach ( $raw as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) || ! is_string( $value ) || strlen( $value ) > self::SECRET_BYTES || str_contains( $value, "\0" ) ) {
				throw RestException::invalidField( (string) $key );
			}
			if ( Field::SECRET_TEXT === $fields[ $key ]->kind ) {
				$value = trim( str_replace( "\r\n", "\n", $value ) );
			}
			if ( '' !== $value ) {
				$values[ (string) $key ] = $value;
			}
		}
		$shown = self::withDefaults( $type, $settings );
		foreach ( $fields as $key => $field ) {
			if ( $field->required && $field->visible( $shown ) && ! isset( $values[ $key ] ) && ! in_array( $key, $stored, true ) ) {
				/* translators: %s: name of the field, e.g. Secret access key */
				throw RestException::invalid( sprintf( __( 'Enter the %s.', 'lw-migration' ), $field->label ), $key );
			}
		}

		return $values;
	}

	/**
	 * Which fields are shown depends on settings a request may leave out (a
	 * CLI select left at its default); the browser's form starts from the
	 * same defaults (emptyForm() in src/destinations/destinations.js).
	 *
	 * @param DestinationType      $type     Type.
	 * @param array<string, mixed> $settings Stored or submitted settings.
	 * @return array<string, mixed> The settings with each field's default where none is given.
	 */
	public static function withDefaults( DestinationType $type, array $settings ): array {
		foreach ( $type->fields() as $field ) {
			if ( ! $field->isSecret() && ! array_key_exists( $field->key, $settings ) && null !== $field->default ) {
				$settings[ $field->key ] = $field->default;
			}
		}

		return $settings;
	}
}

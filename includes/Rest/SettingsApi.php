<?php
/**
 * Settings over REST.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Recipients;

defined( 'ABSPATH' ) || exit;

/**
 * Retention (count, total bytes, days; 0 = no limit) and the default
 * notification address. A save changes only the keys it names and refuses an
 * unknown key or anything that is not valid before anything is stored.
 */
final class SettingsApi {

	/** The objects a save may name. */
	private const KEYS = [
		'retention'     => true,
		'notifications' => true,
	];

	/**
	 * @return array{retention: array{count: int, bytes: int, days: int}, notifications: array{email: string}}
	 */
	public static function get(): array {
		return [
			'retention'     => Retention::fromOption( get_option( Options::RETENTION, [] ) )->toArray(),
			'notifications' => [ 'email' => Recipients::defaultAddress() ],
		];
	}

	/**
	 * @param array<string, mixed> $body {retention?: {count?, bytes?, days?}, notifications?: {email}}.
	 * @return array{retention: array{count: int, bytes: int, days: int}, notifications: array{email: string}}
	 * @throws RestException On a missing object, an unknown key or an invalid value.
	 */
	public static function save( array $body ): array {
		if ( [] === $body || [] !== array_diff_key( $body, self::KEYS ) ) {
			throw RestException::invalidField( 'retention' );
		}
		$retention = array_key_exists( 'retention', $body ) ? self::retention( $body['retention'] ) : null;
		$email     = array_key_exists( 'notifications', $body ) ? self::email( $body['notifications'] ) : null;
		if ( null !== $retention ) {
			update_option( Options::RETENTION, $retention, false );
		}
		if ( null !== $email ) {
			update_option( Options::NOTIFICATIONS, [ 'email' => $email ], false );
		}

		return self::get();
	}

	/**
	 * @param mixed $raw {count?, bytes?, days?}.
	 * @return array{count: int, bytes: int, days: int}
	 * @throws RestException On anything else.
	 */
	private static function retention( $raw ): array {
		if ( ! is_array( $raw ) || [] !== array_diff_key( $raw, Retention::LIMITS ) ) {
			throw RestException::invalidField( 'retention' );
		}
		$retention = self::get()['retention'];
		foreach ( $raw as $key => $value ) {
			if ( ! is_int( $value ) || $value < 0 || $value > Retention::LIMITS[ $key ] ) {
				throw RestException::invalidField( (string) $key );
			}
			$retention[ $key ] = $value;
		}

		return $retention;
	}

	/**
	 * @param mixed $raw {email}.
	 * @return string Normalised list ('' = the site admin).
	 * @throws RestException On anything else.
	 */
	private static function email( $raw ): string {
		if ( ! is_array( $raw ) || [ 'email' ] !== array_keys( $raw ) || ! is_string( $raw['email'] ) || strlen( $raw['email'] ) > 1024 ) {
			throw RestException::invalidField( 'email' );
		}
		try {
			return Recipients::normalize( $raw['email'] );
		} catch ( \InvalidArgumentException $e ) {
			throw RestException::invalid( $e->getMessage(), 'email' );
		}
	}
}

<?php
/**
 * Who gets the mails of a schedule.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A list of at most MAX addresses separated by commas, semicolons or white
 * space, each accepted by is_email() (which also refuses anything that could
 * smuggle a header in). Order kept, duplicates dropped without regard to case.
 * The schedule's own list wins, then the default of Settings, then the site
 * admin's address; a stored list that no longer parses falls back the same way.
 */
final class Recipients {

	public const MAX = 5;

	/**
	 * @param string $list Addresses.
	 * @return array<int, string>
	 * @throws \InvalidArgumentException When an address is invalid or there are too many.
	 */
	public static function parse( string $list ): array {
		$found = [];
		foreach ( (array) preg_split( '/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $address ) {
			$address = (string) $address;
			if ( false === is_email( $address ) ) {
				throw new \InvalidArgumentException( self::refusal() );
			}
			$found[ strtolower( $address ) ] ??= $address;
		}
		if ( count( $found ) > self::MAX ) {
			throw new \InvalidArgumentException( self::refusal() );
		}

		return array_values( $found );
	}

	/**
	 * @param string $list Addresses.
	 * @throws \InvalidArgumentException Like parse().
	 */
	public static function normalize( string $list ): string {
		return implode( ', ', self::parse( $list ) );
	}

	public static function defaultAddress(): string {
		$stored = get_option( Options::NOTIFICATIONS, [] );

		return is_array( $stored ) && is_string( $stored['email'] ?? null ) ? $stored['email'] : '';
	}

	/**
	 * @param string $scheduleEmail The schedule's list ('' = none).
	 * @return array<int, string> Empty only when even the admin address is unusable.
	 */
	public static function resolve( string $scheduleEmail ): array {
		foreach ( [ $scheduleEmail, self::defaultAddress(), (string) get_option( 'admin_email', '' ) ] as $candidate ) {
			try {
				$list = self::parse( $candidate );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
			if ( [] !== $list ) {
				return $list;
			}
		}

		return [];
	}

	private static function refusal(): string {
		/* translators: %d: largest number of addresses */
		return sprintf( __( 'Enter up to %d valid e-mail addresses, separated by commas.', 'lw-migration' ), self::MAX );
	}
}

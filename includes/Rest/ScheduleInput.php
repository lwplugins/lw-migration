<?php
/**
 * Checks a schedule sent by the browser.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Schedules\Recipients;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Schedules\ScheduleException;

defined( 'ABSPATH' ) || exit;

/**
 * Allowlists of exact JSON types, like JobInput: only the keys sent change
 * (an update of `enabled` alone keeps everything else); the export options
 * pass JobInput::exportOptions() (the Backup section's rules; `kind` and
 * `noFiles` are never accepted); `password` absent = keep the sealed one,
 * '' or null = remove it, a string = seal it. Switching the protection off
 * forgets the password; protection without a password is refused.
 * `destinations` and `keepLocal` (spec 21.1) go through
 * ScheduleDestinations::apply(). Every refusal is a 400 with data.field.
 */
final class ScheduleInput {

	private const KEYS = [ 'title', 'enabled', 'frequency', 'every', 'minute', 'hour', 'weekday', 'monthday', 'export', 'password', 'retention', 'notify', 'destinations', 'keepLocal' ];

	private const RANGES = [
		'minute'   => [ 0, 59 ],
		'hour'     => [ 0, 23 ],
		'weekday'  => [ 1, 7 ],
		'monthday' => [ 1, 31 ],
	];

	private const MAX_TEXT = 1024;

	/**
	 * @param Schedule             $current Stored schedule (or a new one with its defaults).
	 * @param array<string, mixed> $body    Request.
	 * @param \Closure             $seal    fn( string $plain ): string.
	 * @throws RestException On an unknown key or an invalid value.
	 */
	public static function apply( Schedule $current, array $body, \Closure $seal ): Schedule {
		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				throw RestException::invalidField( (string) $key );
			}
		}
		$data = $current->toArray();
		if ( array_key_exists( 'title', $body ) ) {
			$data['title'] = self::title( $body['title'] );
		}
		if ( array_key_exists( 'enabled', $body ) ) {
			$data['enabled'] = self::flag( $body['enabled'], 'enabled' );
		}
		if ( array_key_exists( 'frequency', $body ) ) {
			$data['frequency'] = self::choice( $body['frequency'], Schedule::FREQUENCIES, 'frequency' );
		}
		foreach ( self::RANGES as $key => [ $min, $max ] ) {
			if ( array_key_exists( $key, $body ) ) {
				$data[ $key ] = self::number( $body[ $key ], $key, $min, $max );
			}
		}
		if ( array_key_exists( 'every', $body ) ) {
			// Only "every N hours/days" uses it; for the others any small number is accepted and stored as 1.
			[ $min, $max ] = Schedule::EVERY[ $data['frequency'] ] ?? [ 1, 90 ];
			$data['every'] = self::number( $body['every'], 'every', $min, $max );
		}
		if ( array_key_exists( 'export', $body ) ) {
			$data['export'] = array_diff_key(
				JobInput::exportOptions( $body['export'] )->toArray(),
				[
					'kind'    => true,
					'noFiles' => true,
				]
			);
		}
		if ( array_key_exists( 'retention', $body ) ) {
			$data['retention'] = self::retention( $body['retention'], (array) $data['retention'] );
		}
		if ( array_key_exists( 'notify', $body ) ) {
			$data['notify'] = self::notify( $body['notify'], (array) $data['notify'] );
		}
		$data = ScheduleDestinations::apply( $body, $data );
		if ( array_key_exists( 'password', $body ) ) {
			$data['password'] = self::password( $body['password'], $seal );
		}

		return self::checked( $data );
	}

	/**
	 * @param array<string, mixed> $data Merged row.
	 * @throws RestException On a missing title or a protection without password.
	 */
	private static function checked( array $data ): Schedule {
		if ( empty( $data['export']['encrypt'] ) ) {
			$data['password'] = '';
		} elseif ( '' === $data['password'] ) {
			throw RestException::invalid( __( 'An encrypted backup needs a password.', 'lw-migration' ), 'password' );
		}
		if ( '' === $data['title'] ) {
			throw RestException::invalid( __( 'Give the schedule a name.', 'lw-migration' ), 'title' );
		}

		return Schedule::fromArray( $data );
	}

	/**
	 * @param mixed $value Title.
	 * @throws RestException Unless a plain text of 1-100 characters.
	 */
	private static function title( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_TEXT || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw RestException::invalidField( 'title' );
		}
		$title = trim( sanitize_text_field( $value ) );
		if ( mb_strlen( $title ) > Schedule::TITLE_LENGTH ) {
			/* translators: %d: largest number of characters */
			throw RestException::invalid( sprintf( __( 'Use at most %d characters for the name.', 'lw-migration' ), Schedule::TITLE_LENGTH ), 'title' );
		}

		return $title;
	}

	/**
	 * @param mixed  $value Value.
	 * @param string $field Field name.
	 * @throws RestException Unless a boolean.
	 */
	private static function flag( $value, string $field ): bool {
		if ( ! is_bool( $value ) ) {
			throw RestException::invalidField( $field );
		}

		return $value;
	}

	/**
	 * @param mixed              $value   Value.
	 * @param array<int, string> $allowed Choices.
	 * @param string             $field   Field name.
	 * @throws RestException Unless one of the choices.
	 */
	private static function choice( $value, array $allowed, string $field ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw RestException::invalidField( $field );
		}

		return $value;
	}

	/**
	 * @param mixed  $value Value.
	 * @param string $field Field name.
	 * @param int    $min   Smallest.
	 * @param int    $max   Largest.
	 * @throws RestException Unless a whole number (JSON integer) in range.
	 */
	private static function number( $value, string $field, int $min, int $max ): int {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw RestException::invalidField( $field );
		}

		return $value;
	}

	/**
	 * Also checks a destination's retention (DestinationInput).
	 *
	 * @param mixed                $raw     {count?, bytes?, days?}.
	 * @param array<string, mixed> $current Stored retention.
	 * @return array<string, int>
	 * @throws RestException On anything else.
	 */
	public static function retention( $raw, array $current ): array {
		if ( ! is_array( $raw ) || [] !== array_diff_key( $raw, Retention::LIMITS ) ) {
			throw RestException::invalidField( 'retention' );
		}
		foreach ( $raw as $key => $value ) {
			$current[ $key ] = self::number( $value, (string) $key, 0, Retention::LIMITS[ $key ] );
		}

		return $current;
	}

	/**
	 * @param mixed                $raw     {when?, email?}.
	 * @param array<string, mixed> $current Stored setting.
	 * @return array<string, string>
	 * @throws RestException On anything else.
	 */
	private static function notify( $raw, array $current ): array {
		if ( ! is_array( $raw ) || [] !== array_diff_key(
			$raw,
			[
				'when'  => true,
				'email' => true,
			]
		) ) {
			throw RestException::invalidField( 'notify' );
		}
		if ( array_key_exists( 'when', $raw ) ) {
			$current['when'] = self::choice( $raw['when'], Schedule::NOTIFY, 'when' );
		}
		if ( array_key_exists( 'email', $raw ) ) {
			if ( ! is_string( $raw['email'] ) || strlen( $raw['email'] ) > self::MAX_TEXT ) {
				throw RestException::invalidField( 'email' );
			}
			try {
				$current['email'] = Recipients::normalize( $raw['email'] );
			} catch ( \InvalidArgumentException $e ) {
				throw RestException::invalid( $e->getMessage(), 'email' );
			}
		}

		return [
			'when'  => (string) ( $current['when'] ?? Schedule::NOTIFY_FAILURE ),
			'email' => (string) ( $current['email'] ?? '' ),
		];
	}

	/**
	 * @param mixed    $raw  Password.
	 * @param \Closure $seal Sealer.
	 * @throws RestException On a malformed value, or when the server cannot seal it.
	 */
	private static function password( $raw, \Closure $seal ): string {
		$plain = JobInput::password( $raw );
		if ( null === $plain ) {
			return '';
		}
		try {
			return (string) $seal( $plain );
		} catch ( ScheduleException $e ) {
			throw RestException::invalid( $e->getMessage(), 'password' );
		}
	}
}

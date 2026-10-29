<?php
/**
 * The cloud part of a schedule request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;

defined( 'ABSPATH' ) || exit;

/**
 * `destinations`: a list of {id, retention?} naming stored profiles, each at
 * most once, at most Schedule::MAX_DESTINATIONS; retention as the schedule's
 * own (ScheduleInput::retention(), missing = 7 / 0 / 0). A profile deleted
 * later is skipped at run time (spec 21.3), but one that does not exist now
 * is refused. Every refusal names the field `destinations`.
 */
final class ScheduleDestinations {

	private const ROW_KEYS = [
		'id'        => true,
		'retention' => true,
	];

	private const RETENTION = [
		'count' => 7,
		'bytes' => 0,
		'days'  => 0,
	];

	/**
	 * Applies the cloud keys of a schedule request: `destinations` (parse())
	 * and `keepLocal` (a boolean); keys not sent keep the stored value.
	 *
	 * @param array<string, mixed> $body Request.
	 * @param array<string, mixed> $data Merged schedule row.
	 * @return array<string, mixed>
	 * @throws RestException With the field of the refused key.
	 */
	public static function apply( array $body, array $data ): array {
		if ( array_key_exists( 'destinations', $body ) ) {
			$data['destinations'] = self::parse( $body['destinations'] );
		}
		if ( array_key_exists( 'keepLocal', $body ) ) {
			if ( ! is_bool( $body['keepLocal'] ) ) {
				throw RestException::invalidField( 'keepLocal' );
			}
			$data['keepLocal'] = $body['keepLocal'];
		}

		return $data;
	}

	/**
	 * @param mixed         $raw    Submitted list.
	 * @param \Closure|null $exists fn( string $id ): bool (default: the stored profiles).
	 * @return array<int, array{id: string, retention: array<string, int>}>
	 * @throws RestException With field `destinations`.
	 */
	public static function parse( $raw, ?\Closure $exists = null ): array {
		$exists = $exists ?? static fn( string $id ): bool => null !== ( new ProfileRepository() )->find( $id );
		if ( ! is_array( $raw ) || count( $raw ) > Schedule::MAX_DESTINATIONS ) {
			throw RestException::invalidField( 'destinations' );
		}
		$clean = [];
		foreach ( $raw as $row ) {
			$id = is_array( $row ) && [] === array_diff_key( $row, self::ROW_KEYS ) ? ( $row['id'] ?? null ) : null;
			if ( ! is_string( $id ) || 1 !== preg_match( Profile::ID_PATTERN, $id ) || isset( $clean[ $id ] ) || ! $exists( $id ) ) {
				throw RestException::invalid( __( 'Choose each destination once, from the stored destinations.', 'lw-migration' ), 'destinations' );
			}
			try {
				$retention = ScheduleInput::retention( $row['retention'] ?? [], self::RETENTION );
			} catch ( RestException $e ) {
				throw RestException::invalidField( 'destinations' );
			}
			$clean[ $id ] = [
				'id'        => $id,
				'retention' => $retention,
			];
		}

		return array_values( $clean );
	}
}

<?php
/**
 * The run log of a schedule.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The last MAX runs (spec 17), newest first, in one option per schedule
 * (lw_migration_schedule_log_<id>, autoload off: {entries, failureMailAt}),
 * written only when a run ends (RunRecorder; one job per site). Beside the
 * entries it keeps when the last failure mail was handed to the mailer, so
 * the throttle does not depend on how many entries came since. Entries carry
 * no path, password or server error text: the recorder writes them redacted.
 * Texts are clipped to TEXT_LENGTH characters and numbers are never negative,
 * so one entry stays small. An entry of a run with cloud destinations also has
 * `cloud`: at most CLOUD_ROWS rows of {name, status, error}, clipped like the texts.
 */
final class Log {

	public const MAX = 30;

	/** Longest text of a field, in characters (a longer one is clipped). */
	public const TEXT_LENGTH = 500;

	public const CLOUD_ROWS = 20;

	private const CLOUD_NAME_LENGTH = 100;

	/** Every field of an entry with its empty value (the type is the value's type). */
	private const FIELDS = [
		'job'       => '',
		'trigger'   => '',
		'status'    => '',
		'startedAt' => 0,
		'endedAt'   => 0,
		'backup'    => '',
		'size'      => 0,
		'error'     => '',
		'warnings'  => 0,
		'mail'      => '',
	];

	/**
	 * @param string $id Schedule id.
	 * @return array<int, array<string, mixed>> Newest first.
	 */
	public static function all( string $id ): array {
		return self::read( $id )['entries'];
	}

	/**
	 * @param string $id Schedule id.
	 * @return array<string, mixed>|null The newest entry.
	 */
	public static function last( string $id ): ?array {
		return self::all( $id )[0] ?? null;
	}

	/**
	 * @param string $id Schedule id.
	 * @return int Unix time the last failure mail was handed over (0 = none since the last success).
	 */
	public static function failureMailAt( string $id ): int {
		return self::read( $id )['failureMailAt'];
	}

	/**
	 * @param string               $id            Schedule id.
	 * @param array<string, mixed> $entry         New entry.
	 * @param int|null             $failureMailAt New failure mail time (null = keep).
	 */
	public static function add( string $id, array $entry, ?int $failureMailAt = null ): void {
		$log            = self::read( $id );
		$log['entries'] = array_slice( array_merge( [ self::entry( $entry ) ], $log['entries'] ), 0, self::MAX );
		self::write( $id, $log, $failureMailAt );
	}

	/**
	 * Sets the mail result of the newest entry (nothing to set on an empty log).
	 *
	 * @param string   $id            Schedule id.
	 * @param string   $mail          Mail result.
	 * @param int|null $failureMailAt New failure mail time (null = keep).
	 */
	public static function setMail( string $id, string $mail, ?int $failureMailAt = null ): void {
		$log = self::read( $id );
		if ( [] === $log['entries'] ) {
			return;
		}
		$log['entries'][0] = self::entry( array_merge( $log['entries'][0], [ 'mail' => $mail ] ) );
		self::write( $id, $log, $failureMailAt );
	}

	/**
	 * @param string $id Schedule id.
	 */
	public static function clear( string $id ): void {
		delete_option( self::option( $id ) );
	}

	/**
	 * @param string $id Schedule id.
	 * @throws \InvalidArgumentException Unless a schedule id.
	 */
	private static function option( string $id ): string {
		if ( 1 !== preg_match( Schedule::ID_PATTERN, $id ) ) {
			throw new \InvalidArgumentException( 'Invalid schedule id.' );
		}

		return Options::SCHEDULE_LOG_PREFIX . $id;
	}

	/**
	 * @param string $id Schedule id.
	 * @return array{entries: array<int, array<string, mixed>>, failureMailAt: int}
	 */
	private static function read( string $id ): array {
		$stored  = get_option( self::option( $id ), [] );
		$stored  = is_array( $stored ) ? $stored : [];
		$entries = [];
		foreach ( is_array( $stored['entries'] ?? null ) ? $stored['entries'] : [] as $entry ) {
			if ( is_array( $entry ) ) {
				$entries[] = self::entry( $entry );
			}
		}
		$at = $stored['failureMailAt'] ?? 0;

		return [
			'entries'       => array_slice( $entries, 0, self::MAX ),
			'failureMailAt' => is_int( $at ) ? max( 0, $at ) : 0,
		];
	}

	/**
	 * @param string                                                               $id            Schedule id.
	 * @param array{entries: array<int, array<string, mixed>>, failureMailAt: int} $log           Log.
	 * @param int|null                                                             $failureMailAt New failure mail time (null = keep).
	 */
	private static function write( string $id, array $log, ?int $failureMailAt ): void {
		if ( null !== $failureMailAt ) {
			$log['failureMailAt'] = max( 0, $failureMailAt );
		}
		update_option( self::option( $id ), $log, false );
	}

	/**
	 * @param array<string, mixed> $entry Raw entry.
	 * @return array<string, mixed>
	 */
	private static function entry( array $entry ): array {
		$clean = [];
		foreach ( self::FIELDS as $key => $empty ) {
			$value         = $entry[ $key ] ?? $empty;
			$clean[ $key ] = is_int( $empty )
				? ( is_numeric( $value ) ? max( 0, (int) $value ) : 0 )
				: ( is_string( $value ) ? mb_substr( $value, 0, self::TEXT_LENGTH ) : '' );
		}
		$cloud = self::cloud( $entry['cloud'] ?? null );
		if ( [] !== $cloud ) {
			$clean['cloud'] = $cloud;
		}

		return $clean;
	}

	/**
	 * @param mixed $rows Raw cloud lines.
	 * @return array<int, array{name: string, status: string, error: string}>
	 */
	private static function cloud( $rows ): array {
		$clean = [];
		foreach ( is_array( $rows ) ? array_slice( array_values( $rows ), 0, self::CLOUD_ROWS ) : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = [
				'name'   => mb_substr( is_string( $row['name'] ?? null ) ? $row['name'] : '', 0, self::CLOUD_NAME_LENGTH ),
				'status' => in_array( $row['status'] ?? null, [ 'ok', 'failed', 'skipped', 'pending' ], true ) ? (string) $row['status'] : 'failed',
				'error'  => mb_substr( is_string( $row['error'] ?? null ) ? $row['error'] : '', 0, self::TEXT_LENGTH ),
			];
		}

		return $clean;
	}
}

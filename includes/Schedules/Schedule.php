<?php
/**
 * One backup schedule.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data, stored as one item of the option `lw_migration_schedules` (an
 * array, never a serialized object). fromArray() is lenient for stored rows:
 * unknown keys are dropped, numbers are clamped into range and a missing
 * value gets its default, so an older or hand-edited row still loads; the
 * strict gate for what a user sends is Rest\ScheduleInput. The password is
 * kept sealed (SealedPassword); this class never sees it in plain text. The
 * export options are kept as an array and checked when a run starts
 * (ScheduleJobs); `kind` and `noFiles` are never stored: a schedule always
 * makes a full backup. `destinations` lists profile ids with this schedule's
 * retention there (spec 21.1); `keepLocal` false deletes the local copy once
 * every upload succeeded.
 */
final class Schedule {

	public const ID_PATTERN = '/^[a-f0-9]{8}\z/';

	public const HOURLY  = 'hourly';
	public const HOURS   = 'hours';
	public const DAILY   = 'daily';
	public const DAYS    = 'days';
	public const WEEKLY  = 'weekly';
	public const MONTHLY = 'monthly';

	public const FREQUENCIES = [ self::HOURLY, self::HOURS, self::DAILY, self::DAYS, self::WEEKLY, self::MONTHLY ];

	/** Allowed "every N" per frequency; the others always have 1. */
	public const EVERY = [
		self::HOURS => [ 2, 48 ],
		self::DAYS  => [ 2, 90 ],
	];

	public const NOTIFY_OFF     = 'off';
	public const NOTIFY_FAILURE = 'failure';
	public const NOTIFY_SUCCESS = 'success';
	public const NOTIFY_ALWAYS  = 'always';

	public const NOTIFY = [ self::NOTIFY_OFF, self::NOTIFY_FAILURE, self::NOTIFY_SUCCESS, self::NOTIFY_ALWAYS ];

	public const TITLE_LENGTH = 100;

	public const MAX_DESTINATIONS = 20;

	/** Export option keys the server decides. */
	private const FIXED_EXPORT_KEYS = [
		'kind'    => true,
		'noFiles' => true,
	];

	private const DEFAULT_RETENTION = [
		'count' => 7,
		'bytes' => 0,
		'days'  => 0,
	];

	/**
	 * @param string                                                                             $id          8 hex digits.
	 * @param string                                                                             $title       Name shown to people.
	 * @param bool                                                                               $enabled     Runs by itself.
	 * @param string                                                                             $frequency   One of FREQUENCIES.
	 * @param int                                                                                $every       N of "every N hours/days", else 1.
	 * @param int                                                                                $minute      0-59.
	 * @param int                                                                                $hour        0-23 (not used by hourly / every N hours).
	 * @param int                                                                                $weekday     1-7, ISO (1 = Monday), weekly only.
	 * @param int                                                                                $monthday    1-31, monthly only (clamped to the month).
	 * @param int                                                                                $anchor      Unix time "every N" counts from.
	 * @param array<string, mixed>                                                               $export      Export options (ExportOptions::toArray() without kind/noFiles).
	 * @param string                                                                             $password    Sealed password, or ''.
	 * @param array{count: int, bytes: int, days: int}                                           $retention   Retention of this schedule's backups.
	 * @param string                                                                             $notifyWhen  One of NOTIFY.
	 * @param string                                                                             $notifyEmail Address list, '' = the default address.
	 * @param int                                                                                $createdAt   Unix time.
	 * @param int                                                                                $updatedAt   Unix time.
	 * @param array<int, array{id: string, retention: array{count: int, bytes: int, days: int}}> $destinations Cloud destinations of the backups.
	 * @param bool                                                                               $keepLocal   Keep the local copy after the uploads.
	 */
	public function __construct(
		public string $id,
		public string $title = '',
		public bool $enabled = false,
		public string $frequency = self::DAILY,
		public int $every = 1,
		public int $minute = 0,
		public int $hour = 3,
		public int $weekday = 1,
		public int $monthday = 1,
		public int $anchor = 0,
		public array $export = [],
		public string $password = '',
		public array $retention = self::DEFAULT_RETENTION,
		public string $notifyWhen = self::NOTIFY_FAILURE,
		public string $notifyEmail = '',
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public array $destinations = [],
		public bool $keepLocal = true
	) {
	}

	/**
	 * @param array<string, mixed> $a Stored row.
	 * @return self
	 * @throws \InvalidArgumentException On a missing or malformed id.
	 */
	public static function fromArray( array $a ): self {
		$id = $a['id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new \InvalidArgumentException( 'Invalid schedule id.' );
		}
		$frequency = in_array( $a['frequency'] ?? null, self::FREQUENCIES, true ) ? (string) $a['frequency'] : self::DAILY;
		$range     = self::EVERY[ $frequency ] ?? [ 1, 1 ];
		$notify    = is_array( $a['notify'] ?? null ) ? $a['notify'] : [];

		return new self(
			$id,
			self::title( $a['title'] ?? '' ),
			true === ( $a['enabled'] ?? false ),
			$frequency,
			self::clamp( $a['every'] ?? $range[0], $range[0], $range[1] ),
			self::clamp( $a['minute'] ?? 0, 0, 59 ),
			self::clamp( $a['hour'] ?? 3, 0, 23 ),
			self::clamp( $a['weekday'] ?? 1, 1, 7 ),
			self::clamp( $a['monthday'] ?? 1, 1, 31 ),
			self::clamp( $a['anchor'] ?? 0, 0, PHP_INT_MAX ),
			array_diff_key( is_array( $a['export'] ?? null ) ? $a['export'] : [], self::FIXED_EXPORT_KEYS ),
			is_string( $a['password'] ?? null ) ? $a['password'] : '',
			self::retention( $a['retention'] ?? null ),
			in_array( $notify['when'] ?? null, self::NOTIFY, true ) ? (string) $notify['when'] : self::NOTIFY_FAILURE,
			is_string( $notify['email'] ?? null ) ? trim( $notify['email'] ) : '',
			self::clamp( $a['createdAt'] ?? 0, 0, PHP_INT_MAX ),
			self::clamp( $a['updatedAt'] ?? 0, 0, PHP_INT_MAX ),
			self::destinations( $a['destinations'] ?? [] ),
			false !== ( $a['keepLocal'] ?? true )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id'           => $this->id,
			'title'        => $this->title,
			'enabled'      => $this->enabled,
			'frequency'    => $this->frequency,
			'every'        => $this->every,
			'minute'       => $this->minute,
			'hour'         => $this->hour,
			'weekday'      => $this->weekday,
			'monthday'     => $this->monthday,
			'anchor'       => $this->anchor,
			'export'       => $this->export,
			'password'     => $this->password,
			'retention'    => $this->retention,
			'notify'       => [
				'when'  => $this->notifyWhen,
				'email' => $this->notifyEmail,
			],
			'createdAt'    => $this->createdAt,
			'updatedAt'    => $this->updatedAt,
			'destinations' => $this->destinations,
			'keepLocal'    => $this->keepLocal,
		];
	}

	public static function newId(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	/**
	 * What NextRun reads (and whether it runs at all).
	 *
	 * @return array<string, int|string|bool>
	 */
	public function timing(): array {
		return [
			'enabled'   => $this->enabled,
			'frequency' => $this->frequency,
			'every'     => $this->every,
			'minute'    => $this->minute,
			'hour'      => $this->hour,
			'weekday'   => $this->weekday,
			'monthday'  => $this->monthday,
			'anchor'    => $this->anchor,
		];
	}

	public function sameTiming( self $other ): bool {
		return $this->timing() === $other->timing();
	}

	/**
	 * @param mixed $value Stored destinations.
	 * @return array<int, array{id: string, retention: array{count: int, bytes: int, days: int}}>
	 */
	private static function destinations( $value ): array {
		$clean = [];
		foreach ( is_array( $value ) ? $value : [] as $row ) {
			$id = is_array( $row ) ? ( $row['id'] ?? null ) : null;
			if ( ! is_string( $id ) || 1 !== preg_match( Profile::ID_PATTERN, $id ) || isset( $clean[ $id ] ) ) {
				continue;
			}
			$clean[ $id ] = [
				'id'        => $id,
				'retention' => self::retention( $row['retention'] ?? null ),
			];
		}

		return array_slice( array_values( $clean ), 0, self::MAX_DESTINATIONS );
	}

	/**
	 * @param mixed $value Stored title.
	 */
	private static function title( $value ): string {
		$title = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', is_string( $value ) ? $value : '' ) );

		return mb_substr( $title, 0, self::TITLE_LENGTH );
	}

	/**
	 * A whole number (an int or a string of digits) clamped into range; anything else is $min.
	 *
	 * @param mixed $value Stored value.
	 * @param int   $min   Smallest value.
	 * @param int   $max   Largest value.
	 */
	private static function clamp( $value, int $min, int $max ): int {
		$number = is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,18}\z/', $value ) ) ? (int) $value : $min;

		return max( $min, min( $max, $number ) );
	}

	/**
	 * @param mixed $value Stored retention.
	 * @return array{count: int, bytes: int, days: int}
	 */
	private static function retention( $value ): array {
		$value = is_array( $value ) ? $value : self::DEFAULT_RETENTION;
		$clean = [];
		foreach ( Retention::LIMITS as $key => $max ) {
			$clean[ $key ] = self::clamp( $value[ $key ] ?? 0, 0, $max );
		}

		return [
			'count' => $clean['count'],
			'bytes' => $clean['bytes'],
			'days'  => $clean['days'],
		];
	}
}

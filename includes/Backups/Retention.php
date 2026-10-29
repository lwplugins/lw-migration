<?php
/**
 * Which old backups to delete.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Count, total size and age limits (0 = no limit). Only backups we generated
 * in our own folder are counted or pruned: never the external folder, never
 * the backups taken before an import or a reset, never an archive copied in by
 * hand (a name without our time stamp). The newest of ours always stays.
 * Scheduled backups (name suffix) are pruned only by their own schedule's rules.
 */
final class Retention {

	private const DAY = 86400;

	/** Largest accepted value per rule (Settings, schedules); 0 = no limit. */
	public const LIMITS = [
		'count' => 10000,
		'bytes' => 1125899906842624,
		'days'  => 36500,
	];

	/**
	 * Most backups to keep, 0 = no limit.
	 *
	 * @var int
	 */
	public int $maxCount;

	/**
	 * Most bytes to keep in total, 0 = no limit.
	 *
	 * @var int
	 */
	public int $maxBytes;

	/**
	 * Oldest age in days to keep, 0 = no limit.
	 *
	 * @var int
	 */
	public int $maxDays;

	public function __construct( int $maxCount = 0, int $maxBytes = 0, int $maxDays = 0 ) {
		$this->maxCount = max( 0, $maxCount );
		$this->maxBytes = max( 0, $maxBytes );
		$this->maxDays  = max( 0, $maxDays );
	}

	/**
	 * @param mixed $value Option value {count, bytes, days}.
	 * @return self
	 */
	public static function fromOption( $value ): self {
		$value = is_array( $value ) ? $value : [];

		return new self( (int) ( $value['count'] ?? 0 ), (int) ( $value['bytes'] ?? 0 ), (int) ( $value['days'] ?? 0 ) );
	}

	/**
	 * @return array{count: int, bytes: int, days: int}
	 */
	public function toArray(): array {
		return [
			'count' => $this->maxCount,
			'bytes' => $this->maxBytes,
			'days'  => $this->maxDays,
		];
	}

	/**
	 * Walks our backups newest first; once a limit is reached, that backup and
	 * every older one are pruned (a smaller older backup does not slip back in
	 * under the size limit). The first one walked always stays: $keep when given
	 * and present, otherwise the newest. Age comes from the time in the generated
	 * name (one later than $now counts as $now: the clock went back, it stays
	 * newest); the file's mtime is never trusted.
	 *
	 * @param array<int, Backup> $backups  Any backups; only our own are considered.
	 * @param int                $now      Unix time.
	 * @param string|null        $keep     Name to keep regardless (the one just written).
	 * @param string|null        $schedule null = manual backups only, an id = that schedule's backups only;
	 *                                     the global rules never prune a scheduled backup, a schedule never another's.
	 * @return array<int, string> Names to delete, newest first.
	 */
	public function prune( array $backups, int $now, ?string $keep = null, ?string $schedule = null ): array {
		$own  = array_values( array_filter( $backups, static fn( Backup $backup ): bool => self::prunable( $backup, $schedule ) ) );
		$time = [];
		foreach ( $own as $backup ) {
			$time[ $backup->name ] = min( (int) BackupName::timeOf( $backup->name ), $now );
		}
		usort(
			$own,
			static fn( Backup $a, Backup $b ): int => [ $b->name === $keep, $time[ $b->name ], $b->name ] <=> [ $a->name === $keep, $time[ $a->name ], $a->name ]
		);
		$kept   = 0;
		$bytes  = 0;
		$cut    = false;
		$delete = [];
		foreach ( $own as $backup ) {
			$cut = $cut || ( 0 < $kept && $this->exceeds( $kept, $bytes + $backup->size, $time[ $backup->name ], $now ) );
			if ( $cut && $backup->name !== $keep ) {
				$delete[] = $backup->name;
				continue;
			}
			++$kept;
			$bytes += $backup->size;
		}

		return $delete;
	}

	/**
	 * Only non-empty backups in our own folder with a name we generated, never a backup
	 * taken before an import or a reset, and only those of the scope: null = manual backups only, an id =
	 * that schedule's backups only.
	 *
	 * @param Backup      $backup   Candidate.
	 * @param string|null $schedule Scope.
	 */
	private static function prunable( Backup $backup, ?string $schedule ): bool {
		return Backup::OWN === $backup->source
			&& 0 < $backup->size
			&& BackupName::isValid( $backup->name )
			&& null !== BackupName::timeOf( $backup->name )
			&& ! BackupName::isSafety( $backup->name )
			&& BackupName::scheduleOf( $backup->name ) === $schedule;
	}

	private function exceeds( int $kept, int $bytes, int $mtime, int $now ): bool {
		return ( $this->maxCount > 0 && $kept >= $this->maxCount )
			|| ( $this->maxBytes > 0 && $bytes > $this->maxBytes )
			|| ( $this->maxDays > 0 && $mtime < $now - $this->maxDays * self::DAY );
	}
}

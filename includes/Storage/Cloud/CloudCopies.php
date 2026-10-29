<?php
/**
 * Which backup is on which destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\JsonOptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * One option (plan D12, autoload off): backup name => profile id =>
 * {status: pending|up|failed, at}. Written by the job steps and by an admin
 * deleting a remote file or a profile, so every read goes to the row and
 * every write is a compare-and-swap (JsonOptionRow): a concurrent edit is
 * kept, not rolled back. At most MAX names; the oldest (first written) go
 * first. Shown in the backups list (spec 21.6 "fent / folyamatban / hiba").
 */
final class CloudCopies {

	public const PENDING = 'pending';

	public const UP = 'up';

	public const FAILED = 'failed';

	public const MAX = 500;

	/**
	 * @return array<string, array<string, array{status: string, at: int}>>
	 */
	public static function all(): array {
		return self::clean( self::row()->get() );
	}

	/**
	 * @param string $name Backup name.
	 * @return array<string, array{status: string, at: int}>
	 */
	public static function of( string $name ): array {
		return self::all()[ $name ] ?? [];
	}

	/**
	 * @param string $name   Backup name.
	 * @param string $id     Profile id.
	 * @param string $status PENDING, UP or FAILED.
	 * @param int    $at     Unix time.
	 */
	public static function mark( string $name, string $id, string $status, int $at ): void {
		self::row()->change(
			static function ( $stored ) use ( $name, $id, $status, $at ): array {
				$all = self::clean( $stored );
				$row = $all[ $name ] ?? [];
				unset( $all[ $name ] );
				$row[ $id ]   = [
					'status' => $status,
					'at'     => $at,
				];
				$all[ $name ] = $row; // Moved to the end: the newest name is dropped last.

				return self::capped( $all );
			}
		);
	}

	/**
	 * Records an UP copy at one destination for each name that has no record
	 * there yet: the backups its listing holds byte for byte (a destination
	 * added again over a folder it held before, for example). A pending, up
	 * or failed record stays as it is.
	 *
	 * @param string             $id    Profile id.
	 * @param array<int, string> $names Backup names the destination holds.
	 * @param int                $at    Unix time.
	 * @return bool Whether a record was added.
	 */
	public static function adopt( string $id, array $names, int $at ): bool {
		$added   = false;
		$written = self::row()->change(
			static function ( $stored ) use ( $id, $names, $at, &$added ): ?array {
				$all   = self::clean( $stored );
				$added = false;
				foreach ( $names as $name ) {
					if ( ! isset( $all[ $name ][ $id ] ) ) {
						$all[ $name ][ $id ] = [
							'status' => self::UP,
							'at'     => $at,
						];
						$added               = true;
					}
				}

				return $added ? self::capped( $all ) : null;
			}
		);

		return $added && $written;
	}

	public static function forget( string $name, string $id ): void {
		self::row()->change(
			static function ( $stored ) use ( $name, $id ): ?array {
				$all = self::clean( $stored );
				if ( ! isset( $all[ $name ][ $id ] ) ) {
					return null;
				}
				unset( $all[ $name ][ $id ] );
				if ( [] === $all[ $name ] ) {
					unset( $all[ $name ] );
				}

				return $all;
			}
		);
	}

	/**
	 * Corrects the records of one destination from its whole listing: an UP
	 * copy recorded before the listing was taken and not in it is gone there
	 * (removed by hand, for example) and is forgotten. A copy recorded after
	 * the listing (an upload that finished meanwhile), a pending and a failed
	 * one stay; nothing is added (adopt() does that). Only for a listing known
	 * to be whole.
	 *
	 * @param string             $id       Profile id.
	 * @param array<int, string> $names    Every file name the destination listed.
	 * @param int                $listedAt Unix time just before the listing was asked for.
	 * @return bool Whether a copy was forgotten.
	 */
	public static function reconcile( string $id, array $names, int $listedAt ): bool {
		$there   = array_fill_keys( $names, true );
		$changed = false;
		$written = self::row()->change(
			static function ( $stored ) use ( $id, $there, $listedAt, &$changed ): ?array {
				$all     = self::clean( $stored );
				$changed = false;
				foreach ( $all as $name => $where ) {
					$copy = $where[ $id ] ?? null;
					if ( null === $copy || self::UP !== $copy['status'] || $copy['at'] >= $listedAt || isset( $there[ $name ] ) ) {
						continue;
					}
					unset( $all[ $name ][ $id ] );
					if ( [] === $all[ $name ] ) {
						unset( $all[ $name ] );
					}
					$changed = true;
				}

				return $changed ? $all : null;
			}
		);

		return $changed && $written;
	}

	/**
	 * @param string $id Profile id.
	 * @return bool Whether the row was written (false: the caller warns).
	 */
	public static function forgetDestination( string $id ): bool {
		return self::row()->change(
			static function ( $stored ) use ( $id ): array {
				$all = self::clean( $stored );
				foreach ( array_keys( $all ) as $name ) {
					unset( $all[ $name ][ $id ] );
					if ( [] === $all[ $name ] ) {
						unset( $all[ $name ] );
					}
				}

				return $all;
			}
		);
	}

	/**
	 * @param array<string, array<string, array{status: string, at: int}>> $all Records, oldest name first.
	 * @return array<string, array<string, array{status: string, at: int}>> At most MAX names, the oldest dropped.
	 */
	private static function capped( array $all ): array {
		$surplus = count( $all ) - self::MAX;

		return $surplus > 0 ? array_slice( $all, $surplus, null, true ) : $all;
	}

	private static function row(): JsonOptionRow {
		return new JsonOptionRow( Options::CLOUD_COPIES );
	}

	/**
	 * @param mixed $stored A stored value.
	 * @return array<string, array<string, array{status: string, at: int}>>
	 */
	private static function clean( $stored ): array {
		$clean = [];
		foreach ( is_array( $stored ) ? $stored : [] as $name => $where ) {
			if ( ! is_array( $where ) ) {
				continue;
			}
			foreach ( $where as $id => $row ) {
				if ( is_array( $row ) && in_array( $row['status'] ?? null, [ self::PENDING, self::UP, self::FAILED ], true ) ) {
					$clean[ (string) $name ][ (string) $id ] = [
						'status' => (string) $row['status'],
						'at'     => max( 0, (int) ( $row['at'] ?? 0 ) ),
					];
				}
			}
		}

		return $clean;
	}
}

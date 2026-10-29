<?php
/**
 * Wrong reset passwords per user.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * One non-autoloaded row holding a JSON map user id => {n, first, until}:
 * the failures counted since `first`, and the end of a lock. MAX_FAILURES
 * wrong passwords within WINDOW seconds lock the user for LOCK seconds; the
 * counter then starts over. Changed by compare-and-swap (OptionRow), so two
 * requests at once both count; at most MAX_USERS entries (the oldest go).
 * No password or hash is ever stored here.
 */
final class Tries {

	public const MAX_FAILURES = 5;

	public const WINDOW = 900;

	public const LOCK = 900;

	public const MAX_USERS = 100;

	public const ATTEMPTS = 5;

	/**
	 * The option row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null $db Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?object $db = null ) {
		$this->row = new OptionRow( Options::RESET_TRIES, $db );
	}

	/**
	 * @param int $userId User id.
	 * @param int $now    Unix time.
	 * @return int Seconds until the user may try again; 0 = free.
	 */
	public function lockedFor( int $userId, int $now ): int {
		$entry = self::decode( $this->row->read() )[ $userId ] ?? null;

		return null === $entry ? 0 : min( self::LOCK, max( 0, $entry['until'] - $now ) ); // A skewed clock never locks longer.
	}

	/**
	 * Counts one attempt before the password is checked, so requests in
	 * parallel cannot all pass a read of the count: the compare-and-swap
	 * serialises them, and the attempt that reaches MAX_FAILURES sets the
	 * lock. A failed check leaves its attempt counted; the right password
	 * clears the user (clear()).
	 *
	 * @param int $userId User id (never the login: one counter per account).
	 * @param int $now    Unix time.
	 * @return bool False when the user is locked or the attempt could not be counted (the caller refuses).
	 */
	public function reserve( int $userId, int $now ): bool {
		$locked  = false;
		$kept    = false;
		$written = $this->change(
			static function ( array $all ) use ( $userId, $now, &$locked, &$kept ): array {
				$entry  = $all[ $userId ] ?? null;
				$locked = null !== $entry && $entry['until'] > $now;
				if ( $locked ) {
					return $all;
				}
				if ( null === $entry || $entry['first'] < $now - self::WINDOW ) {
					$entry = [
						'n'     => 0,
						'first' => $now,
						'until' => 0,
					];
				}
				++$entry['n'];
				if ( $entry['n'] >= self::MAX_FAILURES ) {
					$entry = [
						'n'     => 0,
						'first' => $now,
						'until' => $now + self::LOCK,
					];
				}
				unset( $all[ $userId ] );
				$all[ $userId ] = $entry; // Most recent last.
				$capped         = self::capped( self::live( $all, $now ), $now );
				$kept           = isset( $capped[ $userId ] ); // A row full of locks has no room to count this attempt.

				return $capped;
			}
		);

		return $written && ! $locked && $kept;
	}

	/**
	 * Forgets the user's failures (after the right password).
	 *
	 * @param int $userId User id.
	 */
	public function clear( int $userId ): void {
		$this->change(
			static function ( array $all ) use ( $userId ): array {
				unset( $all[ $userId ] );

				return $all;
			}
		);
	}

	/**
	 * Read, edit, write; a lost race reads again, up to ATTEMPTS times.
	 *
	 * @param \Closure $edit fn( array<int, array{n: int, first: int, until: int}> ): array<int, array{n: int, first: int, until: int}>.
	 */
	private function change( \Closure $edit ): bool {
		for ( $i = 0; $i < self::ATTEMPTS; $i++ ) {
			$raw = $this->row->read();
			$new = $edit( self::decode( $raw ) );
			if ( null === $raw ) {
				$done = [] === $new || $this->row->insert( self::encode( $new ) );
			} elseif ( [] === $new ) {
				$done = $this->row->deleteIf( $raw );
			} else {
				$done = self::encode( $new ) === $raw || $this->row->swap( $raw, self::encode( $new ) );
			}
			if ( $done ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * At most MAX_USERS entries: the oldest unlocked ones go first, so failures
	 * of other accounts never push a locked user out of the row.
	 *
	 * @param array<int, array{n: int, first: int, until: int}> $all Entries, oldest first.
	 * @param int                                               $now Unix time.
	 * @return array<int, array{n: int, first: int, until: int}>
	 */
	private static function capped( array $all, int $now ): array {
		$locked = array_filter( $all, static fn( array $entry ): bool => $entry['until'] > $now );

		return array_slice( array_diff_key( $all, $locked ) + $locked, -self::MAX_USERS, null, true );
	}

	/**
	 * Drops the entries whose window and lock both ended. A time in the
	 * future (a clock that was ahead) is brought back to now, and a lock never
	 * ends later than LOCK from now.
	 *
	 * @param array<int, array{n: int, first: int, until: int}> $all Entries.
	 * @param int                                               $now Unix time.
	 * @return array<int, array{n: int, first: int, until: int}>
	 */
	private static function live( array $all, int $now ): array {
		$live = [];
		foreach ( $all as $id => $entry ) {
			$entry['first'] = min( $entry['first'], $now );
			$entry['until'] = min( $entry['until'], $now + self::LOCK );
			if ( $entry['first'] >= $now - self::WINDOW || $entry['until'] > $now ) {
				$live[ $id ] = $entry;
			}
		}

		return $live;
	}

	/**
	 * @param string|null $raw Stored value.
	 * @return array<int, array{n: int, first: int, until: int}> Anything malformed is dropped.
	 */
	private static function decode( ?string $raw ): array {
		$value = null === $raw ? null : json_decode( $raw, true );
		$all   = [];
		foreach ( is_array( $value ) ? $value : [] as $id => $entry ) {
			if ( 1 === preg_match( '/^[1-9][0-9]{0,18}\z/', (string) $id ) && is_array( $entry ) && is_int( $entry['n'] ?? null ) && is_int( $entry['first'] ?? null ) && is_int( $entry['until'] ?? null ) ) {
				$all[ (int) $id ] = [
					'n'     => $entry['n'],
					'first' => $entry['first'],
					'until' => $entry['until'],
				];
			}
		}

		return $all;
	}

	/**
	 * @param array<int, array{n: int, first: int, until: int}> $all Entries.
	 */
	private static function encode( array $all ): string {
		return (string) json_encode( (object) $all );
	}
}

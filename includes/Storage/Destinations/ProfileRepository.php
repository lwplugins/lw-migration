<?php
/**
 * The stored cloud destination profiles.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\JsonOptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * One option row, `lw_migration_destinations` (autoload off): a JSON list of
 * plain rows, never objects. Written by an admin (REST, CLI) and by a job at
 * the end of its slices (mark()), so every read goes to the row itself and
 * every write is a compare-and-swap (JsonOptionRow): a job whose options
 * cache is older than an admin's edit never writes that edit back, never
 * brings back a deleted profile. mark() touches only the state fields of a
 * profile that still exists; connect() and remember() only the OAuth
 * connection of a profile that still exists for the same app (M7c preflight
 * P-C1), and an edit never writes its copy of the connection back. Never a
 * secret (the vault is a separate row).
 */
final class ProfileRepository {

	public const MAX = 20;

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * The option row.
	 *
	 * @var JsonOptionRow
	 */
	private JsonOptionRow $row;

	/**
	 * @param \Closure|null $now fn(): int (default time()).
	 * @param \wpdb|null    $db  Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?\Closure $now = null, ?object $db = null ) {
		$this->now = $now ?? static fn(): int => time();
		$this->row = new JsonOptionRow( Options::DESTINATIONS, $db );
	}

	/**
	 * @return array<int, Profile>
	 */
	public function all(): array {
		return self::profiles( $this->row->get() );
	}

	public function find( string $id ): ?Profile {
		foreach ( $this->all() as $profile ) {
			if ( $profile->id === $id ) {
				return $profile;
			}
		}

		return null;
	}

	/**
	 * Adds or replaces by id; createdAt is set once, updatedAt every time.
	 * When MAX profiles exist and this one is new, the change callback throws
	 * \LengthException (nothing is written).
	 *
	 * @param Profile $profile Profile.
	 * @throws \RuntimeException When the row cannot be written.
	 */
	public function save( Profile $profile ): void {
		$now    = (int) ( $this->now )();
		$stored = $this->row->change(
			static function ( $current ) use ( $profile, $now ): array {
				$all   = self::profiles( $current );
				$found = false;
				foreach ( $all as $i => $old ) {
					if ( $old->id === $profile->id ) {
						$profile->createdAt = $old->createdAt > 0 ? $old->createdAt : $now;
						$all[ $i ]          = $profile;
						$found              = true;
					}
				}
				if ( ! $found ) {
					if ( count( $all ) >= self::MAX ) {
						throw new \LengthException( 'Too many destinations.' );
					}
					$profile->createdAt = $now;
					$all[]              = $profile;
				}
				$profile->updatedAt = $now;

				return self::rows( $all );
			}
		);
		if ( ! $stored ) {
			throw new \RuntimeException( 'The destination could not be saved.' );
		}
	}

	/**
	 * An edit of an existing profile: a profile that is gone stays gone
	 * (false, nothing written). Its state (status, message, last upload) and
	 * its connection are taken from the row as it is now, so a job's mark()
	 * or a connect made meanwhile is kept; with $reset the state starts over
	 * (new settings or secrets) and only the last upload is kept; with
	 * $resetConnection (the Client ID changed) the connection is cleared. The
	 * given object gets the stored state.
	 *
	 * @param Profile $profile         Edited profile.
	 * @param bool    $reset           Its settings or secrets changed.
	 * @param bool    $resetConnection Its OAuth connection belongs to another app now.
	 * @return bool Whether the profile exists (and was written).
	 * @throws \RuntimeException When the row cannot be written.
	 */
	public function replace( Profile $profile, bool $reset, bool $resetConnection = false ): bool {
		$now    = (int) ( $this->now )();
		$found  = false;
		$stored = $this->row->change(
			static function ( $current ) use ( $profile, $reset, $resetConnection, $now, &$found ): ?array {
				$all   = self::profiles( $current );
				$found = false;
				foreach ( $all as $i => $old ) {
					if ( $old->id === $profile->id ) {
						$profile->status       = $reset ? Profile::NEW : $old->status;
						$profile->message      = $reset ? '' : $old->message;
						$profile->lastUploadAt = $old->lastUploadAt;
						$profile->connection   = $resetConnection ? [] : $old->connection;
						$profile->createdAt    = $old->createdAt > 0 ? $old->createdAt : $now;
						$profile->updatedAt    = $now;
						$all[ $i ]             = $profile;
						$found                 = true;
					}
				}

				return $found ? self::rows( $all ) : null;
			}
		);
		if ( ! $stored ) {
			throw new \RuntimeException( 'The destination could not be saved.' );
		}

		return $found;
	}

	/**
	 * Commits an OAuth connection (the callback, after the exchange and the
	 * account check): only a profile that still exists and whose settings,
	 * read inside the write, still bind the connection gets it; its state
	 * starts over (NEW, no message), the last upload stays.
	 *
	 * @param string                    $id         Profile id.
	 * @param array<string, int|string> $connection New connection (cleaned by Profile::connection()); its `client` mark and its `id` (32 hex digits) are required.
	 * @param \Closure                  $bound      fn( array $settings ): bool — whether the profile's settings as they are now still bind the connection (Client ID and grant settings, Task 10 review I1).
	 * @return bool Whether it was written (false: gone, or the Client ID or grant settings changed meanwhile).
	 * @throws \LogicException When the connection has no client mark or no well-formed id (it could never be used).
	 * @throws \RuntimeException When the row cannot be written.
	 */
	public function connect( string $id, array $connection, \Closure $bound ): bool {
		$connection = Profile::connection( $connection );
		if ( ! is_string( $connection['client'] ?? null ) || '' === $connection['client'] ) {
			throw new \LogicException( 'An OAuth connection carries the mark of its Client ID.' );
		}
		if ( ! is_string( $connection['id'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{32}\z/', $connection['id'] ) ) {
			throw new \LogicException( 'An OAuth connection carries its id (TokenKeeper::connectionId()).' );
		}
		$found  = false;
		$stored = $this->row->change(
			static function ( $current ) use ( $id, $connection, $bound, &$found ): ?array {
				$all   = self::profiles( $current );
				$found = false;
				foreach ( $all as $profile ) {
					if ( $profile->id === $id && true === $bound( $profile->settings ) ) {
						$profile->connection = $connection;
						$profile->status     = Profile::NEW;
						$profile->message    = '';
						$found               = true;
					}
				}

				return $found ? self::rows( $all ) : null;
			}
		);
		if ( ! $stored ) {
			throw new \RuntimeException( 'The destination could not be saved.' );
		}

		return $found;
	}

	/**
	 * Records one connection entry (e.g. the Drive folder id found again) of
	 * a profile that still exists and still holds the connection with the
	 * given id (Task 7 review m3: one connect, not just the same app). Best
	 * effort like mark().
	 *
	 * @param string     $id           Profile id.
	 * @param string     $key          Connection key (Profile::isConnectionKey(); never `client` or `id`).
	 * @param int|string $value        Value (a text over the connection limit is not written).
	 * @param string     $connectionId connection.id the profile must still hold.
	 * @return bool Whether it was written.
	 * @throws \LogicException On a malformed or reserved key (a programming error).
	 */
	public function remember( string $id, string $key, int|string $value, string $connectionId ): bool {
		if ( ! Profile::isConnectionKey( $key ) || in_array( $key, [ 'client', 'id' ], true ) ) {
			throw new \LogicException( 'A connection key has 1 to 20 letters and is neither the client mark nor the connection id.' );
		}
		$found  = false;
		$stored = $this->row->change(
			static function ( $current ) use ( $id, $key, $value, $connectionId, &$found ): ?array {
				$all   = self::profiles( $current );
				$found = false;
				foreach ( $all as $profile ) {
					$next = Profile::connection( array_merge( $profile->connection, [ $key => $value ] ) );
					$held = $profile->connection['id'] ?? null;
					$kept = $next[ $key ] ?? null;
					if ( $profile->id === $id && '' !== $connectionId && $held === $connectionId && $kept === $value ) {
						$profile->connection = $next;
						$found               = true;
					}
				}

				return $found ? self::rows( $all ) : null;
			}
		);

		return $found && $stored;
	}

	/**
	 * @param string $id Profile id.
	 * @return bool Whether a profile was deleted.
	 */
	public function delete( string $id ): bool {
		$found  = false;
		$stored = $this->row->change(
			static function ( $current ) use ( $id, &$found ): ?array {
				$all   = self::profiles( $current );
				$kept  = array_filter( $all, static fn( Profile $p ): bool => $p->id !== $id );
				$found = count( $kept ) !== count( $all );

				return $found ? self::rows( $kept ) : null;
			}
		);

		return $found && $stored;
	}

	/**
	 * Records the state of an existing profile: only status, message and the
	 * last upload change; a profile that is gone stays gone. Best effort: a
	 * row that cannot be written keeps the previous state line.
	 *
	 * @param string        $id         Profile id (a gone profile is ignored).
	 * @param string        $status     Profile::OK, ERROR or REAUTH.
	 * @param string        $message    Last failure ('' = none).
	 * @param int|null      $uploadedAt Time of a successful upload (null = keep).
	 * @param \Closure|null $while      fn( Profile $stored ): bool — decided on the row as it is when written; false leaves the profile alone.
	 */
	public function mark( string $id, string $status, string $message = '', ?int $uploadedAt = null, ?\Closure $while = null ): void {
		$status = in_array( $status, Profile::STATES, true ) ? $status : Profile::ERROR;
		$this->row->change(
			static function ( $current ) use ( $id, $status, $message, $uploadedAt, $while ): ?array {
				$all = self::profiles( $current );
				foreach ( $all as $profile ) {
					if ( $profile->id === $id && ( null === $while || $while( $profile ) ) ) {
						$profile->status       = $status;
						$profile->message      = $message;
						$profile->lastUploadAt = $uploadedAt ?? $profile->lastUploadAt;

						return self::rows( $all );
					}
				}

				return null;
			}
		);
	}

	/**
	 * Marks a profile `reauth` after a run was refused its credentials,
	 * decided on the row as it is now (preflight P-M6): a profile that no
	 * longer holds the connection the run used (reconnected meanwhile, M7c
	 * Task 4 m4) or is gone is left alone. With $notice it also answers
	 * whether the reconnect mail is due: once per OAuth connection — the
	 * first notice is recorded in it (`noticed`); only a new connection, that
	 * is a new grant, is noticed again, never an edit — or, for a profile
	 * without one, when this mark turned it `reauth`.
	 *
	 * @param string                    $id      Profile id.
	 * @param string                    $message Why (shown on the profile).
	 * @param array<string, int|string> $used    The connection the run used.
	 * @param bool                      $notice  Whether this run would mail (ReauthNotice::due()).
	 * @return bool Whether the mail is due now (never without $notice).
	 */
	public function revoked( string $id, string $message, array $used, bool $notice ): bool {
		$due     = false;
		$written = $this->row->change(
			static function ( $current ) use ( $id, $message, $used, $notice, &$due ): ?array {
				$all = self::profiles( $current );
				$due = false;
				foreach ( $all as $profile ) {
					if ( $profile->id !== $id || ! self::sameConnection( $profile->connection, $used ) ) {
						continue;
					}
					$keyed = isset( $profile->connection['client'] );
					$due   = $notice && ( $keyed ? empty( $profile->connection['noticed'] ) : Profile::REAUTH !== $profile->status );
					if ( $due && $keyed ) {
						$profile->connection['noticed'] = 1;
					}
					$profile->status  = Profile::REAUTH;
					$profile->message = $message;

					return self::rows( $all );
				}

				return null;
			}
		);

		return $written && $due;
	}

	/**
	 * Whether a stored connection is the one a run used: by its id when
	 * either has one (an entry remembered meanwhile, like a folder id, does
	 * not make it another connection; Task 7 review I1), else entry by entry
	 * (`noticed` aside).
	 *
	 * @param array<string, int|string> $stored The profile's connection now.
	 * @param array<string, int|string> $used   The one the run used.
	 */
	private static function sameConnection( array $stored, array $used ): bool {
		if ( isset( $stored['id'] ) || isset( $used['id'] ) ) {
			return ( $stored['id'] ?? null ) === ( $used['id'] ?? null );
		}

		return array_diff_key( $stored, [ 'noticed' => 0 ] ) === array_diff_key( $used, [ 'noticed' => 0 ] );
	}

	/**
	 * @param mixed $stored Decoded row.
	 * @return array<int, Profile>
	 */
	private static function profiles( $stored ): array {
		$all = [];
		foreach ( is_array( $stored ) ? $stored : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$all[] = Profile::fromArray( $row );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e ); // A damaged row is skipped, never shown.
			}
		}

		return array_slice( $all, 0, self::MAX );
	}

	/**
	 * @param array<int, Profile> $all Profiles.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( array $all ): array {
		return array_map( static fn( Profile $p ): array => $p->toArray(), array_values( $all ) );
	}
}

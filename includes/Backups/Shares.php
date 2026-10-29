<?php
/**
 * Transfer links of backups.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * Transfer links: rows of the option row lw_migration_shares (JSON list,
 * compare-and-swap, never autoloaded). A row names one backup and holds only
 * the SHA-256 of its 128-bit token; the token itself is returned once by
 * create() and never stored, logged or listed. open() compares the digest of
 * the presented token with every row through hash_equals (no early exit) and
 * never writes. At most MAX_LIVE links live at once; expired rows are dropped
 * by the next write (create) and by the daily cleanup (prune).
 */
final class Shares {

	/** Code of the exception create() throws when MAX_LIVE links live. */
	public const FULL = 1;

	public const ID_PATTERN = '/^[a-f0-9]{8}\z/';

	public const TOKEN_PATTERN = '/^[a-f0-9]{32}\z/';

	public const MIN_TTL = 300;

	public const MAX_TTL = 604800;

	public const DEFAULT_TTL = 86400;

	public const MAX_LIVE = 50;

	/** A download that does not start at byte 0 updates the last use at most this often (seconds). */
	public const TOUCH_EVERY = 60;

	private const HASH_PATTERN = '/^[a-f0-9]{64}\z/';

	private const TRIES = 5;

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
		$this->row = new OptionRow( Options::SHARES, $db );
	}

	public static function clamp( int $seconds ): int {
		return max( self::MIN_TTL, min( self::MAX_TTL, $seconds ) );
	}

	/**
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 * @param string $name   Backup file name.
	 * @param int    $userId Who created it.
	 * @param int    $now    Unix time.
	 * @param int    $ttl    Seconds it lives (clamped).
	 * @param string $identity Fingerprint of the file now ('' = a link that never opens).
	 * @return array{id: string, token: string, expiresAt: int}
	 * @throws \RuntimeException FULL when MAX_LIVE links are live; when the row cannot be written.
	 */
	public function create( string $folder, string $name, int $userId, int $now, int $ttl, string $identity = '' ): array {
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = $now + self::clamp( $ttl );
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$rows           = array_values( array_filter( $rows, static fn( array $row ): bool => $row['expiresAt'] > $now ) );
			if ( count( $rows ) >= self::MAX_LIVE ) {
				throw new \RuntimeException( 'Too many live transfer links.', self::FULL );
			}
			$id     = self::freeId( $rows );
			$rows[] = [
				'id'         => $id,
				'hash'       => hash( 'sha256', $token ),
				'folder'     => $folder,
				'name'       => $name,
				'identity'   => $identity,
				'createdAt'  => $now,
				'createdBy'  => $userId,
				'expiresAt'  => $expires,
				'uses'       => 0,
				'lastUsedAt' => 0,
			];
			if ( $this->write( $raw, $rows ) ) {
				return [
					'id'        => $id,
					'token'     => $token,
					'expiresAt' => $expires,
				];
			}
		}
		throw new \RuntimeException( 'The transfer link could not be saved.' );
	}

	/**
	 * Every stored link, expired ones included, without its digest.
	 *
	 * @return array<int, array{id: string, folder: string, name: string, identity: string, createdAt: int, createdBy: int, expiresAt: int, uses: int, lastUsedAt: int}>
	 */
	public function all(): array {
		return array_map(
			static function ( array $row ): array {
				unset( $row['hash'] );

				return $row;
			},
			$this->read()[1]
		);
	}

	/**
	 * @param string $id Link id.
	 * @return bool False when there is no such link.
	 */
	public function revoke( string $id ): bool {
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$kept           = array_values( array_filter( $rows, static fn( array $row ): bool => $row['id'] !== $id ) );
			if ( count( $kept ) === count( $rows ) ) {
				return false;
			}
			if ( $this->write( $raw, $kept ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a link's stored fingerprint is the one of the file now. An
	 * empty value on either side never matches (a legacy link, an unreadable file).
	 *
	 * @param string $stored  Fingerprint stored with the link.
	 * @param string $current Fingerprint of the file now.
	 */
	public static function matches( string $stored, string $current ): bool {
		return '' !== $stored && '' !== $current && hash_equals( $stored, $current );
	}

	/**
	 * Read only: the backup a live link names, and the file identity it was
	 * made for ('' for a link created before identities were stored: it
	 * never matches a file).
	 *
	 * @param string $token Token from the link.
	 * @param int    $now   Unix time.
	 * @return array{id: string, folder: string, name: string, identity: string}|null
	 */
	public function open( string $token, int $now ): ?array {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		$digest = hash( 'sha256', $token );
		$found  = null;
		foreach ( $this->read()[1] as $row ) {
			if ( hash_equals( $row['hash'], $digest ) && null === $found ) {
				$found = $row; // No break: every row is compared.
			}
		}
		if ( null === $found || $found['expiresAt'] <= $now ) {
			return null;
		}

		return [
			'id'       => $found['id'],
			'folder'   => $found['folder'],
			'name'     => $found['name'],
			'identity' => $found['identity'],
		];
	}

	/**
	 * Records a use, best effort (one try; a conflict or a failed write is
	 * ignored: the download must not wait for a counter).
	 *
	 * @param string $id        Link id.
	 * @param bool   $fromStart A download from byte 0 (counted as a use).
	 * @param int    $now       Unix time.
	 */
	public function touch( string $id, bool $fromStart, int $now ): void {
		[ $raw, $rows ] = $this->read();
		$changed        = false;
		foreach ( $rows as $i => $row ) {
			if ( $row['id'] !== $id || ( ! $fromStart && $now - $row['lastUsedAt'] < self::TOUCH_EVERY ) ) {
				continue;
			}
			$rows[ $i ]['uses']      += $fromStart ? 1 : 0;
			$rows[ $i ]['lastUsedAt'] = $now;
			$changed                  = true;
		}
		if ( $changed ) {
			$this->write( $raw, $rows );
		}
	}

	/**
	 * Drops every link of one backup (it was deleted).
	 *
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 * @param string $name   Backup file name.
	 * @return int How many were dropped.
	 */
	public function revokeFor( string $folder, string $name ): int {
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$kept           = array_values( array_filter( $rows, static fn( array $row ): bool => $row['folder'] !== $folder || $row['name'] !== $name ) );
			$dropped        = count( $rows ) - count( $kept );
			if ( 0 === $dropped || $this->write( $raw, $kept ) ) {
				return $dropped;
			}
		}

		return 0;
	}

	/**
	 * Binds the links of one backup to its verified copy (the backups folder
	 * moved to another disk: the bytes are proven the same, the inode is
	 * new). Only links bound to the moved file follow; a link of a file that
	 * was replaced before stays dead.
	 *
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 * @param string $name   Backup file name.
	 * @param string $from   Fingerprint of the moved file.
	 * @param string $to     Fingerprint of its verified copy.
	 * @return int How many links follow the copy.
	 */
	public function rebind( string $folder, string $name, string $from, string $to ): int {
		if ( '' === $from || '' === $to || hash_equals( $from, $to ) ) {
			return 0;
		}
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$bound          = 0;
			foreach ( $rows as $i => $row ) {
				if ( $row['folder'] === $folder && $row['name'] === $name && self::matches( $row['identity'], $from ) ) {
					$rows[ $i ]['identity'] = $to;
					++$bound;
				}
			}
			if ( 0 === $bound || $this->write( $raw, $rows ) ) {
				return $bound;
			}
		}

		return 0;
	}

	/**
	 * Drops the expired links.
	 *
	 * @param int $now Unix time.
	 * @return int How many were dropped.
	 */
	public function prune( int $now ): int {
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$kept           = array_values( array_filter( $rows, static fn( array $row ): bool => $row['expiresAt'] > $now ) );
			$dropped        = count( $rows ) - count( $kept );
			if ( 0 === $dropped || $this->write( $raw, $kept ) ) {
				return $dropped;
			}
		}

		return 0;
	}

	/**
	 * The raw value and its well-formed rows; a broken value reads as no rows
	 * (and is replaced by the next write).
	 *
	 * @return array{0: string|null, 1: array<int, array{id: string, hash: string, folder: string, name: string, identity: string, createdAt: int, createdBy: int, expiresAt: int, uses: int, lastUsedAt: int}>}
	 */
	private function read(): array {
		$raw  = $this->row->read();
		$data = null === $raw ? null : json_decode( $raw, true );
		$rows = [];
		foreach ( is_array( $data ) ? $data : [] as $row ) {
			$identity = is_array( $row ) ? ( $row['identity'] ?? null ) : null; // A legacy row has none: dead, never a wildcard.
			if ( self::isRow( $row ) ) {
				$rows[] = array_merge( $row, [ 'identity' => is_string( $identity ) ? $identity : '' ] );
			}
		}

		return [ $raw, $rows ];
	}

	/**
	 * @param mixed $row Decoded row.
	 * @phpstan-assert-if-true array{id: string, hash: string, folder: string, name: string, createdAt: int, createdBy: int, expiresAt: int, uses: int, lastUsedAt: int} $row
	 */
	private static function isRow( $row ): bool {
		if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || ! is_string( $row['hash'] ?? null ) || ! is_string( $row['folder'] ?? null ) || ! is_string( $row['name'] ?? null ) ) {
			return false;
		}
		foreach ( [ 'createdAt', 'createdBy', 'expiresAt', 'uses', 'lastUsedAt' ] as $key ) {
			if ( ! is_int( $row[ $key ] ?? null ) ) {
				return false;
			}
		}

		return 1 === preg_match( self::ID_PATTERN, $row['id'] )
			&& 1 === preg_match( self::HASH_PATTERN, $row['hash'] )
			&& in_array( $row['folder'], [ Backup::OWN, Backup::EXTERNAL ], true )
			&& BackupName::isValid( $row['name'] );
	}

	/**
	 * @param string|null                      $raw  Value read before (null = no row).
	 * @param array<int, array<string, mixed>> $rows Rows to store.
	 */
	private function write( ?string $raw, array $rows ): bool {
		$value = json_encode( array_values( $rows ), JSON_UNESCAPED_SLASHES );
		if ( false === $value ) {
			return false; // Never store an empty value over the links.
		}
		if ( null === $raw ) {
			return $this->row->insert( $value );
		}

		return $raw === $value || $this->row->swap( $raw, $value );
	}

	/**
	 * @param array<int, array{id: string}> $rows Rows.
	 */
	private static function freeId( array $rows ): string {
		$taken = array_column( $rows, 'id' );
		do {
			$id = bin2hex( random_bytes( 4 ) );
		} while ( in_array( $id, $taken, true ) );

		return $id;
	}
}

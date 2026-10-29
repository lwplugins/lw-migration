<?php
/**
 * The connections of this site to other sites.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of the option row lw_migration_links (JSON list, compare-and-swap,
 * never autoloaded, never in an archive: Options::DUMP_EXCLUDED_PREFIXES).
 * A row is one direction of one connection: `in` (this site accepts the
 * other site's signed requests) or `out` (this site sends them). Its secret
 * is sealed with a key from the auth salt (SecretBox); a secret that no
 * longer opens (the salt changed) makes the link `broken`. A link stored by
 * the site that made the code stays unconfirmed until the first signed hello
 * and is dropped after PENDING_TTL. find() and all() only read.
 */
final class Links {

	public const IN = 'in';

	public const OUT = 'out';

	public const PUSH = 'push';

	public const PULL = 'pull';

	public const MAX = 20;

	public const PENDING_TTL = 900;

	public const TOUCH_EVERY = 60;

	public const MAX_TEXT = 200;

	public const MAX_ADDRESS = 2048;

	/** Code of the exception add() throws when MAX links live. */
	public const FULL = 1;

	/** Code of the exception add() throws for an id already stored. */
	public const TAKEN = 2;

	/** Code of Pairing::accept()'s exception when a used code left no link (review m12). */
	public const CODE_USED = 3;

	private const TRIES = 5;

	/** Binds the sealing key to this one use (schedule passwords and job secrets use other contexts). */
	private const KEY_CONTEXT = 'lw-migration/link-secret/v1';

	/**
	 * The option row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null     $db  Database object; defaults to the global $wpdb.
	 * @param SecretBox|null $box Sealing key (default: derived from wp_salt( 'auth' ), built on first use).
	 */
	public function __construct( ?object $db = null, private ?SecretBox $box = null ) {
		$this->row = new OptionRow( Options::LINKS, $db );
	}

	public static function newId(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * @param array<mixed> $allow Requested rights.
	 * @return array<int, string> The valid ones, once each, sorted.
	 */
	public static function allow( array $allow ): array {
		$valid = array_values( array_unique( array_intersect( [ self::PULL, self::PUSH ], array_filter( $allow, 'is_string' ) ) ) );
		sort( $valid );

		return $valid;
	}

	/**
	 * @param array{id: string, direction: string, address: string, name: string, allow: array<int, string>, createdBy: array{id: int, login: string}, pairedFrom: string} $link Link.
	 * @param string                                                                                                                                                       $secret 32 raw bytes.
	 * @param int                                                                                                                                                          $now Unix time.
	 * @param bool                                                                                                                                                         $confirmed Whether the link is usable at once.
	 * @throws \InvalidArgumentException For a malformed link.
	 * @throws \RuntimeException FULL, TAKEN, or when the row cannot be written.
	 */
	public function add( array $link, string $secret, int $now, bool $confirmed ): void {
		if ( Signer::SECRET_BYTES !== strlen( $secret ) ) {
			throw new \InvalidArgumentException( 'A link secret has 32 bytes.' );
		}
		$row = [
			'id'         => $link['id'],
			'direction'  => $link['direction'],
			'address'    => $link['address'],
			'name'       => $link['name'],
			'allow'      => self::allow( $link['allow'] ),
			'secret'     => '',
			'createdAt'  => $now,
			'createdBy'  => LinkRow::owner( $link['createdBy'] ),
			'confirmed'  => $confirmed,
			'lastUsedAt' => 0,
			'pairedFrom' => $link['pairedFrom'],
		];
		if ( ! LinkRow::isRow( $row ) ) {
			throw new \InvalidArgumentException( 'Invalid link.' );
		}
		$row['secret'] = $this->box()->seal( $secret );
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$rows           = LinkRow::live( $rows, $now );
			if ( in_array( $row['id'], array_column( $rows, 'id' ), true ) ) {
				throw new \RuntimeException( 'This link id is taken.', self::TAKEN );
			}
			if ( count( $rows ) >= self::MAX ) {
				throw new \RuntimeException( 'Too many connections.', self::FULL );
			}
			$rows[] = $row;
			if ( $this->write( $raw, $rows ) ) {
				return;
			}
		}
		throw new \RuntimeException( 'The connection could not be saved.' );
	}

	/**
	 * @param int $now Unix time.
	 */
	public function room( int $now ): bool {
		return count( LinkRow::live( $this->read()[1], $now ) ) < self::MAX;
	}

	/**
	 * @param int $now Unix time.
	 * @return array<int, array{id: string, direction: string, address: string, name: string, allow: array<int, string>, createdAt: int, createdBy: array{id: int, login: string}, lastUsedAt: int, pairedFrom: string, state: string, insecure: bool}>
	 */
	public function all( int $now ): array {
		$views = [];
		foreach ( LinkRow::live( $this->read()[1], $now ) as $row ) {
			$state = $row['confirmed'] ? ( null === $this->open( $row['secret'] ) ? 'broken' : 'ready' ) : 'pending';
			unset( $row['secret'], $row['confirmed'] );
			$views[] = $row + [
				'state'    => $state,
				'insecure' => str_starts_with( strtolower( $row['address'] ), 'http:' ),
			];
		}

		return $views;
	}

	/**
	 * Read only.
	 *
	 * @param string $id  Link id.
	 * @param int    $now Unix time.
	 * @return array{id: string, direction: string, address: string, name: string, allow: array<int, string>, createdAt: int, createdBy: array{id: int, login: string}, confirmed: bool, lastUsedAt: int, pairedFrom: string, key: string|null}|null
	 */
	public function find( string $id, int $now ): ?array {
		foreach ( LinkRow::live( $this->read()[1], $now ) as $row ) {
			if ( $row['id'] === $id ) {
				$key = $this->open( $row['secret'] );
				unset( $row['secret'] );

				return $row + [ 'key' => $key ];
			}
		}

		return null;
	}

	/**
	 * Makes a pending link usable. An unknown or expired one is refused (m7); a miss also
	 * drops the pending links that expired, so they do not wait for the daily sweep.
	 *
	 * @param string $id  Link id.
	 * @param int    $now Unix time.
	 */
	public function confirm( string $id, int $now ): bool {
		return $this->change(
			$now,
			static function ( array $rows ) use ( $id ): ?array {
				foreach ( $rows as $i => $row ) {
					if ( $row['id'] === $id ) {
						$rows[ $i ]['confirmed'] = true;

						return $rows;
					}
				}

				return null;
			}
		);
	}

	/**
	 * @param string $id Link id.
	 */
	public function remove( string $id ): bool {
		return $this->change(
			time(),
			static function ( array $rows ) use ( $id ): ?array {
				$kept = array_values( array_filter( $rows, static fn( array $row ): bool => $row['id'] !== $id ) );

				return count( $kept ) === count( $rows ) ? null : $kept;
			}
		);
	}

	/**
	 * Best effort: one try, at most once per TOUCH_EVERY seconds, a failed write is ignored.
	 *
	 * @param string $id  Link id.
	 * @param int    $now Unix time.
	 */
	public function touch( string $id, int $now ): void {
		[ $raw, $rows ] = $this->read();
		$rows           = LinkRow::live( $rows, $now );
		foreach ( $rows as $i => $row ) {
			if ( $row['id'] === $id && $now - $row['lastUsedAt'] >= self::TOUCH_EVERY ) {
				$rows[ $i ]['lastUsedAt'] = $now;
				$this->write( $raw, $rows );
				return;
			}
		}
	}

	/**
	 * Whether the other site never used an incoming link: the pairing's own
	 * hello marks it moments after it was made, and marks are written at most
	 * once per TOUCH_EVERY seconds. (An outgoing link records no use here.)
	 *
	 * @param array{createdAt: int, lastUsedAt: int} $link Link view or row.
	 */
	public static function neverUsed( array $link ): bool {
		return $link['lastUsedAt'] <= $link['createdAt'] + self::TOUCH_EVERY;
	}

	/**
	 * Drops the pending links that expired.
	 *
	 * @param int $now Unix time.
	 * @return int How many.
	 */
	public function prune( int $now ): int {
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$live           = LinkRow::live( $rows, $now );
			if ( count( $live ) === count( $rows ) ) {
				return 0;
			}
			if ( $this->write( $raw, $live ) ) {
				return count( $rows ) - count( $live );
			}
		}

		return 0;
	}

	/**
	 * The read, change, compare-and-swap loop: $change gets the live rows and returns the new list, or null for no change possible.
	 *
	 * @param int      $now    Unix time.
	 * @param \Closure $change fn( array $rows ): ?array.
	 */
	private function change( int $now, \Closure $change ): bool {
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $rows ] = $this->read();
			$live           = LinkRow::live( $rows, $now );
			$new            = $change( $live );
			if ( null === $new ) {
				if ( count( $live ) !== count( $rows ) ) {
					$this->write( $raw, $live ); // Expired pending links go meanwhile; the answer stays "no".
				}

				return false;
			}
			if ( $this->write( $raw, $new ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $sealed Stored secret.
	 */
	private function open( string $sealed ): ?string {
		$key = $this->box()->open( $sealed );

		return null !== $key && Signer::SECRET_BYTES === strlen( $key ) ? $key : null;
	}

	/**
	 * @throws \RuntimeException When the server has no sodium functions (WordPress bundles them; only a broken install lacks them).
	 */
	private function box(): SecretBox {
		if ( null === $this->box ) {
			if ( ! SecretBox::available() ) {
				throw new \RuntimeException( 'The sodium functions are missing.' );
			}
			$this->box = new SecretBox( hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true ) );
		}

		return $this->box;
	}

	/**
	 * @return array{0: string|null, 1: array<int, array{id: string, direction: string, address: string, name: string, allow: array<int, string>, secret: string, createdAt: int, createdBy: array{id: int, login: string}, confirmed: bool, lastUsedAt: int, pairedFrom: string}>}
	 */
	private function read(): array {
		$raw  = $this->row->read();
		$data = null === $raw ? null : json_decode( $raw, true );
		$rows = [];
		foreach ( is_array( $data ) ? $data : [] as $row ) {
			if ( LinkRow::isRow( $row ) ) {
				$rows[] = $row;
			}
		}

		return [ $raw, $rows ];
	}

	/**
	 * @param string|null                      $raw  Value read before (null: no row yet).
	 * @param array<int, array<string, mixed>> $rows Rows to store.
	 */
	private function write( ?string $raw, array $rows ): bool {
		$value = json_encode( array_values( $rows ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $value ) {
			return false; // Never store an empty value over the connections.
		}
		if ( null === $raw ) {
			return $this->row->insert( $value );
		}

		return $raw === $value || $this->row->swap( $raw, $value );
	}
}

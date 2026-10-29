<?php
/**
 * One cloud destination profile.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use LightweightPlugins\Migration\Backups\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data, one item of `lw_migration_destinations` (spec 21.3): id, type,
 * name, the non-secret settings, the retention a manual backup uses there,
 * the state (new / ok / error / reauth) with the last message and the
 * last successful upload, and the OAuth connection state (M7c plan E16:
 * account label, connect time, redirect address, Client-ID mark, folder
 * id — never a token). Never a secret (SecretVault). fromArray() is
 * lenient for stored rows like Schedules\Schedule: clamped numbers, unknown
 * states read as NEW; only a malformed id throws.
 */
final class Profile {

	public const NEW = 'new';

	public const OK = 'ok';

	public const ERROR = 'error';

	public const REAUTH = 'reauth';

	public const STATES = [ self::NEW, self::OK, self::ERROR, self::REAUTH ];

	public const ID_PATTERN = '/^[a-f0-9]{8}\z/';

	public const NAME_LENGTH = 100;

	private const MESSAGE_LENGTH = 500;

	/**
	 * Most entries of a connection. Entries past it are dropped, so every key
	 * the plugin writes must fit: client, id, connectedAt, redirectUri (the
	 * connect callback writes these first), the type's entries (account,
	 * folderId; Rest\OAuthApi refuses more than fit) and
	 * ProfileRepository::revoked()'s `noticed` (7 today; a dropped `noticed`
	 * would mail a revoked connection on every run).
	 */
	public const CONNECTION_ENTRIES = 10;

	/** Longest text value of a connection entry, in bytes. */
	public const CONNECTION_TEXT = 2048;

	/**
	 * @param string                                   $id           8 hex digits.
	 * @param string                                   $type         Type key.
	 * @param string                                   $name         Name shown to people.
	 * @param array<string, mixed>                     $settings     Non-secret settings (the type's settings()).
	 * @param array{count: int, bytes: int, days: int} $retention    Retention of manual backups there.
	 * @param string                                   $status       One of STATES.
	 * @param string                                   $message      Last failure message ('' = none).
	 * @param int                                      $lastUploadAt Unix time of the last successful upload (0 = never).
	 * @param int                                      $createdAt    Unix time.
	 * @param int                                      $updatedAt    Unix time.
	 * @param array<string, int|string>                $connection   OAuth connection state (M7c plan E16): account, connectedAt, redirectUri, client, folderId.
	 */
	public function __construct(
		public string $id,
		public string $type,
		public string $name,
		public array $settings = [],
		public array $retention = [
			'count' => 0,
			'bytes' => 0,
			'days'  => 0,
		],
		public string $status = self::NEW,
		public string $message = '',
		public int $lastUploadAt = 0,
		public int $createdAt = 0,
		public int $updatedAt = 0,
		public array $connection = []
	) {
	}

	/**
	 * Never all digits: such an id would turn into an integer key once a JSON
	 * row keyed by it is decoded. The caller redraws while the id is taken.
	 *
	 * @param \Closure|null $draw fn(): string — 4 random bytes (default random_bytes()).
	 */
	public static function newId( ?\Closure $draw = null ): string {
		$draw ??= static fn(): string => random_bytes( 4 );
		do {
			$id = bin2hex( (string) $draw() );
		} while ( ctype_digit( $id ) );

		return $id;
	}

	/**
	 * @param array<string, mixed> $a Stored row.
	 * @throws \InvalidArgumentException On a missing or malformed id.
	 */
	public static function fromArray( array $a ): self {
		$id = $a['id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new \InvalidArgumentException( 'Invalid destination id.' );
		}

		return new self(
			$id,
			is_string( $a['type'] ?? null ) ? strtolower( $a['type'] ) : '',
			self::name( $a['name'] ?? '' ),
			is_array( $a['settings'] ?? null ) ? $a['settings'] : [],
			self::retention( $a['retention'] ?? null ),
			in_array( $a['status'] ?? null, self::STATES, true ) ? (string) $a['status'] : self::NEW,
			is_string( $a['message'] ?? null ) ? mb_substr( $a['message'], 0, self::MESSAGE_LENGTH ) : '',
			self::time( $a['lastUploadAt'] ?? 0 ),
			self::time( $a['createdAt'] ?? 0 ),
			self::time( $a['updatedAt'] ?? 0 ),
			self::connection( $a['connection'] ?? null )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id'           => $this->id,
			'type'         => $this->type,
			'name'         => $this->name,
			'settings'     => $this->settings,
			'retention'    => $this->retention,
			'status'       => $this->status,
			'message'      => $this->message,
			'lastUploadAt' => $this->lastUploadAt,
			'createdAt'    => $this->createdAt,
			'updatedAt'    => $this->updatedAt,
			'connection'   => $this->connection,
		];
	}

	/**
	 * @param mixed $value Stored name.
	 */
	public static function name( $value ): string {
		$name = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', is_string( $value ) ? $value : '' ) );

		return mb_substr( $name, 0, self::NAME_LENGTH );
	}

	/**
	 * @param mixed $value Stored retention.
	 * @return array{count: int, bytes: int, days: int}
	 */
	public static function retention( $value ): array {
		$value = is_array( $value ) ? $value : [];
		$clean = [];
		foreach ( Retention::LIMITS as $key => $max ) {
			$raw           = $value[ $key ] ?? 0;
			$clean[ $key ] = is_int( $raw ) ? max( 0, min( $max, $raw ) ) : 0;
		}

		return [
			'count' => $clean['count'],
			'bytes' => $clean['bytes'],
			'days'  => $clean['days'],
		];
	}

	/**
	 * At most CONNECTION_ENTRIES entries with a plain key (1–20 letters) and an
	 * integer or a text of at most CONNECTION_TEXT bytes; anything else is
	 * dropped.
	 *
	 * @param mixed $value Stored connection.
	 * @return array<string, int|string>
	 */
	public static function connection( $value ): array {
		$clean = [];
		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			if ( self::isConnectionKey( $key ) && ( is_int( $item ) || ( is_string( $item ) && strlen( $item ) <= self::CONNECTION_TEXT ) ) ) {
				$clean[ $key ] = $item;
			}
		}

		return array_slice( $clean, 0, self::CONNECTION_ENTRIES, true );
	}

	/**
	 * @param mixed $key Connection key.
	 */
	public static function isConnectionKey( $key ): bool {
		return is_string( $key ) && 1 === preg_match( '/^[a-zA-Z]{1,20}\z/', $key );
	}

	/**
	 * @param mixed $value Stored time.
	 */
	private static function time( $value ): int {
		return is_int( $value ) ? max( 0, $value ) : 0;
	}
}

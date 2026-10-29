<?php
/**
 * Transfer links over REST: list, create, revoke.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The answer to create() is the only one that ever carries a token (inside
 * the link); the list names each link by id, its backup by folder kind and
 * file name, never by path, and never shows a token or its digest.
 */
final class SharesApi {

	private const KEYS = [ 'folder', 'name', 'expires' ];

	/**
	 * Fingerprint of a backup file: fn( string $path ): string ('' = unreadable).
	 *
	 * @var \Closure
	 */
	private \Closure $fingerprint;

	/**
	 * @param Repository    $backups     Backups.
	 * @param Shares        $shares      Transfer links.
	 * @param \Closure|null $fingerprint fn( string $path ): string; default Fingerprint::of() (tests).
	 */
	public function __construct( private Repository $backups, private Shares $shares, ?\Closure $fingerprint = null ) {
		$this->fingerprint = $fingerprint ?? static fn( string $path ): string => Fingerprint::of( $path );
	}

	public static function fromServices( Services $services ): self {
		return new self( $services->backups(), new Shares() );
	}

	/**
	 * @param int $now Unix time.
	 * @return array{items: array<int, array{id: string, folder: string, name: string, createdAt: int, expiresAt: int, expired: bool, available: bool, revoked: bool, uses: int, lastUsedAt: int}>}
	 */
	public function all( int $now ): array {
		$found = [];
		$items = [];
		foreach ( $this->shares->all() as $row ) {
			$key           = $row['folder'] . ':' . $row['name'];
			$found[ $key ] = $found[ $key ] ?? $this->backups->findIn( $row['name'], $row['folder'] );
			$same          = null !== $found[ $key ] && Shares::matches( $row['identity'], Fingerprint::of( $found[ $key ]->path ) );
			$items[]       = [
				'id'         => $row['id'],
				'folder'     => $row['folder'],
				'name'       => $row['name'],
				'createdAt'  => $row['createdAt'],
				'expiresAt'  => $row['expiresAt'],
				'expired'    => $row['expiresAt'] <= $now,
				'available'  => $same,
				'revoked'    => null !== $found[ $key ] && ! $same, // The file is another one than the link was made for: it never opens.
				'uses'       => $row['uses'],
				'lastUsedAt' => $row['lastUsedAt'],
			];
		}

		return [ 'items' => $items ];
	}

	/**
	 * @param array<string, mixed> $body        {folder, name, expires?} (expires in seconds, 300 to 604800).
	 * @param int                  $userId      The logged-in user.
	 * @param int                  $now         Unix time.
	 * @param string               $downloadUrl Address of the transfer-link route (no query needed).
	 * @return array{id: string, url: string, expiresAt: int}
	 * @throws RestException 400 for a refused body, 404 for a missing backup or one whose fingerprint cannot be read, 409 when too many links live.
	 * @throws \RuntimeException When the link cannot be saved.
	 */
	public function create( array $body, int $userId, int $now, string $downloadUrl ): array {
		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				throw RestException::invalidField( 'share' );
			}
		}
		$folder = $body['folder'] ?? null;
		if ( ! in_array( $folder, [ Backup::OWN, Backup::EXTERNAL ], true ) ) {
			throw RestException::invalidField( 'folder' );
		}
		$name = $body['name'] ?? null;
		if ( ! is_string( $name ) || ! BackupName::isValid( $name ) ) {
			throw RestException::invalidField( 'name' );
		}
		$ttl = array_key_exists( 'expires', $body ) ? $body['expires'] : self::defaultTtl();
		if ( ! is_int( $ttl ) || $ttl < Shares::MIN_TTL || $ttl > Shares::MAX_TTL ) {
			throw RestException::invalidField( 'expires' );
		}
		$backup   = $this->backups->findIn( $name, $folder );
		$identity = null === $backup ? '' : (string) ( $this->fingerprint )( $backup->path );
		if ( null === $backup || '' === $identity ) {
			throw RestException::backupMissing(); // A link bound to no fingerprint would never open.
		}
		try {
			$created = $this->shares->create( $backup->source, $backup->name, $userId, $now, $ttl, $identity );
		} catch ( \RuntimeException $e ) {
			if ( Shares::FULL !== $e->getCode() ) {
				throw $e;
			}
			throw new RestException(
				'lw_migration_share_limit',
				/* translators: %d: the most transfer links that can live at once (50). */
				sprintf( __( 'There are already %d live transfer links. Revoke one, or wait until one expires.', 'lw-migration' ), Shares::MAX_LIVE ),
				409
			);
		}

		return [
			'id'        => $created['id'],
			'url'       => add_query_arg( 'share', $created['token'], $downloadUrl ),
			'expiresAt' => $created['expiresAt'],
		];
	}

	/**
	 * @param string $id Link id.
	 * @return array{revoked: true}
	 * @throws RestException 404 when there is no such link.
	 */
	public function revoke( string $id ): array {
		if ( ! $this->shares->revoke( $id ) ) {
			throw new RestException( 'lw_migration_share_missing', __( 'This transfer link does not exist (any more).', 'lw-migration' ), 404 );
		}

		return [ 'revoked' => true ];
	}

	/**
	 * Seconds a new link lives when the request names none.
	 */
	private static function defaultTtl(): int {
		/**
		 * Seconds a new transfer link lives when none is chosen (default one
		 * day); clamped to five minutes to seven days.
		 *
		 * @param mixed $ttl Seconds (an int; anything else means the default).
		 */
		$ttl = apply_filters( 'lw_migration_share_ttl', Shares::DEFAULT_TTL );

		return Shares::clamp( is_int( $ttl ) ? $ttl : Shares::DEFAULT_TTL );
	}
}

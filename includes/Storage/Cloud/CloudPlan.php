<?php
/**
 * Which destinations a job uploads to.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * The state.cloud of an export or cloud job: the destinations in order, each with
 * the retention a scheduled run brings (null = the profile's, read when the
 * retention step runs), whether the local copy stays (spec 21.1 "Helyi
 * példány megtartása", default on) and whether old remote backups are pruned
 * (off for an upload of an existing backup, plan D6). Only profile ids are
 * stored (spec 21.3): a profile deleted meanwhile is skipped with a warning.
 */
final class CloudPlan {

	public const MAX = 20;

	/**
	 * @param array<int, array{id: string, retention: array{count: int, bytes: int, days: int}|null}> $targets   Destinations.
	 * @param bool                                                                                    $keepLocal Keep the local backup.
	 * @param bool                                                                                    $prune     Apply retention after a successful upload.
	 */
	public function __construct( public array $targets, public bool $keepLocal = true, public bool $prune = true ) {
	}

	/**
	 * @param array<int, mixed> $ids       Profile ids.
	 * @param bool              $keepLocal Keep the local backup.
	 * @throws \InvalidArgumentException On no, malformed, repeated or too many ids.
	 */
	public static function manual( array $ids, bool $keepLocal ): self {
		return new self( self::targets( $ids ), $keepLocal, true );
	}

	/**
	 * @param array<int, mixed> $ids Profile ids.
	 * @throws \InvalidArgumentException On no, malformed, repeated or too many ids.
	 */
	public static function upload( array $ids ): self {
		return new self( self::targets( $ids ), true, false );
	}

	/**
	 * @param string $list Comma-separated ids (CLI --to).
	 * @return array<int, string>
	 */
	public static function idsFrom( string $list ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), static fn( string $id ): bool => '' !== $id ) );
	}

	public static function of( Job $job ): ?self {
		$raw = $job->state['cloud'] ?? null;
		if ( ! is_array( $raw ) || ! is_array( $raw['targets'] ?? null ) || [] === $raw['targets'] ) {
			return null;
		}
		$targets = [];
		foreach ( $raw['targets'] as $target ) {
			$id = is_array( $target ) ? ( $target['id'] ?? null ) : null;
			if ( ! is_string( $id ) || 1 !== preg_match( Profile::ID_PATTERN, $id ) ) {
				return null;
			}
			$targets[] = [
				'id'        => $id,
				'retention' => is_array( $target['retention'] ?? null ) ? Profile::retention( $target['retention'] ) : null,
			];
		}

		return new self( array_slice( $targets, 0, self::MAX ), false !== ( $raw['keepLocal'] ?? true ), false !== ( $raw['prune'] ?? true ) );
	}

	/**
	 * @return array{targets: array<int, array{id: string, retention: array{count: int, bytes: int, days: int}|null}>, keepLocal: bool, prune: bool}
	 */
	public function toArray(): array {
		return [
			'targets'   => $this->targets,
			'keepLocal' => $this->keepLocal,
			'prune'     => $this->prune,
		];
	}

	/**
	 * @return array<int, string>
	 */
	public function ids(): array {
		return array_column( $this->targets, 'id' );
	}

	/**
	 * @param array<int, mixed> $ids Profile ids.
	 * @return array<int, array{id: string, retention: null}>
	 * @throws \InvalidArgumentException On no, malformed, repeated or too many ids.
	 */
	private static function targets( array $ids ): array {
		if ( [] === $ids ) {
			throw new \InvalidArgumentException( __( 'Choose at least one destination.', 'lw-migration' ) );
		}
		if ( count( $ids ) > self::MAX ) {
			/* translators: %d: maximum number of destinations */
			throw new \InvalidArgumentException( sprintf( __( 'Choose at most %d destinations.', 'lw-migration' ), self::MAX ) );
		}
		$targets = [];
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( Profile::ID_PATTERN, $id ) || isset( $targets[ $id ] ) ) {
				/* translators: %s: what was given as a destination ID */
				throw new \InvalidArgumentException( sprintf( __( 'Not a destination ID, or named twice: %s', 'lw-migration' ), is_string( $id ) ? $id : '?' ) );
			}
			$targets[ $id ] = [
				'id'        => $id,
				'retention' => null,
			];
		}

		return array_values( $targets );
	}
}

<?php
/**
 * How many transfer links of each backup still open.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * A link counts while it has not expired and is bound to the file as it is
 * now (Shares::matches with the Fingerprint): a link made for a file that
 * was since replaced under the same name never opens, so it is not counted.
 * Only a backup with a live link row is fingerprinted (one stat per file),
 * so a long list costs one read of the links.
 */
final class LiveLinks {

	/**
	 * @param array<int, Backup>                                                                $backups The listed backups.
	 * @param array<int, array{folder: string, name: string, identity: string, expiresAt: int}> $rows    Shares::all().
	 * @param int                                                                               $now     Unix time.
	 * @return array<string, int> "folder:name" => live links; a backup without one is left out.
	 */
	public static function count( array $backups, array $rows, int $now ): array {
		$listed = [];
		foreach ( $backups as $backup ) {
			$listed[ $backup->source . ':' . $backup->name ] = $backup;
		}
		$fingerprints = [];
		$counts       = [];
		foreach ( $rows as $row ) {
			$id = $row['folder'] . ':' . $row['name'];
			if ( $row['expiresAt'] <= $now || ! isset( $listed[ $id ] ) ) {
				continue;
			}
			$fingerprints[ $id ] ??= Fingerprint::of( $listed[ $id ]->path );
			if ( Shares::matches( $row['identity'], $fingerprints[ $id ] ) ) {
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}
}

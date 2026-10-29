<?php
/**
 * What an import will do, for the confirmation.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Multisite\NetworkSummary;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data for any driver (CLI lines, the React check view): the archive
 * next to this site. What is not known is '' in the version pairs (as
 * before) and null in the later fields: an archive's package.json may lack
 * the optional keys, and its contents are known once ConfirmStep walked the
 * entry headers (ArchiveInventory). This plugin's own folder is never
 * written (ProtectedTargets), so keepsThisPlugin is always true.
 */
final class ImportSummary {

	/**
	 * @param Job      $job               Import job after Validate.
	 * @param int|null $freeBytes         Free disk space, null when unknown.
	 * @param int      $liveDatabaseBytes Current database size: the safety backup's estimate, reported as safetyBytes either way.
	 * @return array<string, mixed>
	 */
	public static function build( Job $job, ?int $freeBytes, int $liveDatabaseBytes ): array {
		$package = ImportState::package( $job );
		$site    = ImportState::site( $job );
		$options = ImportOptions::fromArray( $job->options );
		$archive = (int) ( $job->state['archiveSize'] ?? 0 );
		$needed  = 2 * $archive + ( $options->safetyBackup ? $liveDatabaseBytes : 0 );
		$from    = (string) $package->get( 'HomeURL', '' );
		$prefix  = $package->get( 'Database.Prefix' );
		$walked  = ArchiveInventory::fromArray( (array) ( $job->state[ ArchiveInventory::KEY ] ?? [] ) );

		return [
			'from'            => $from,
			'to'              => $site->homeUrl,
			'wordpress'       => [
				'archive' => (string) $package->get( 'WordPress.Version', '' ),
				'site'    => $site->wpVersion,
			],
			'php'             => [
				'archive' => (string) $package->get( 'PHP.Version', '' ),
				'site'    => PHP_VERSION,
			],
			'archiveSize'     => $archive,
			'encrypted'       => $package->isEncrypted(),
			'compression'     => $package->compression(),
			'overwrites'      => [
				'database' => empty( $package->get( 'NoDatabase' ) ),
				'media'    => empty( $package->get( 'NoMedia' ) ),
				'plugins'  => empty( $package->get( 'NoPlugins' ) ),
				'themes'   => empty( $package->get( 'NoThemes' ) ),
			],
			'subsite'         => ImportState::subsiteId( $job ),
			'safetyBackup'    => $options->safetyBackup,
			'safetyBytes'     => $liveDatabaseBytes,
			'diskNeeded'      => $needed,
			'diskFree'        => $freeBytes,
			'lowDiskSpace'    => null !== $freeBytes && $freeBytes < $needed,
			'network'         => is_array( $job->state['networkSummary'] ?? null ) ? $job->state['networkSummary'] : null,
			'prefix'          => [
				'archive' => is_string( $prefix ) && '' !== $prefix ? $prefix : null,
				'site'    => $site->tablePrefix,
			],
			'createdAt'       => $walked->createdAt(),
			'expandedSize'    => $walked->expandedSize( $package ),
			'parts'           => $walked->parts( $package ),
			'replacesAddress' => '' === $from ? null : ! self::sameAddress( $from, $site->homeUrl ),
			'keepsThisPlugin' => true,
		];
	}

	/**
	 * Whether two home addresses are the same (a trailing slash and letter case aside).
	 *
	 * @param string $a Address.
	 * @param string $b Address.
	 */
	private static function sameAddress( string $a, string $b ): bool {
		return strtolower( rtrim( $a, '/' ) ) === strtolower( rtrim( $b, '/' ) );
	}

	/**
	 * @param array<string, mixed> $s Summary from build().
	 * @return array<int, string>
	 */
	public static function lines( array $s ): array {
		$lines = [
			/* translators: 1: old address, 2: new address */
			sprintf( __( 'Address: %1$s -> %2$s', 'lw-migration' ), NetworkSummary::printable( (string) $s['from'] ), NetworkSummary::printable( (string) $s['to'] ) ),
			/* translators: 1: WordPress version in the archive, 2: WordPress version of this site */
			sprintf( __( 'WordPress: %1$s in the archive, %2$s here', 'lw-migration' ), (string) $s['wordpress']['archive'], (string) $s['wordpress']['site'] ),
			/* translators: 1: PHP version in the archive, 2: PHP version of this site */
			sprintf( __( 'PHP: %1$s in the archive, %2$s here', 'lw-migration' ), (string) $s['php']['archive'], (string) $s['php']['site'] ),
		];
		$parts = self::overwritten( (array) $s['overwrites'] );
		if ( [] !== $parts ) {
			/* translators: %s: list of overwritten parts (database, media, plugins, themes) */
			$lines[] = sprintf( __( 'Will be overwritten: %s', 'lw-migration' ), implode( ', ', $parts ) );
		}
		$lines[] = $s['safetyBackup'] ? __( 'A backup of the current database is taken first.', 'lw-migration' ) : __( 'No backup of the current database is taken.', 'lw-migration' );
		if ( is_array( $s['network'] ?? null ) ) {
			$lines = array_merge( $lines, NetworkSummary::lines( $s['network'] ) );
		}
		if ( ! empty( $s['lowDiskSpace'] ) ) {
			/* translators: 1: estimated space needed, 2: free space */
			$lines[] = sprintf( __( 'Warning: the import may need about %1$s of disk space, but only %2$s is free.', 'lw-migration' ), size_format( (int) $s['diskNeeded'] ), size_format( (int) $s['diskFree'] ) );
		}

		return $lines;
	}

	/**
	 * Translated names of the overwritten parts, in summary order.
	 *
	 * @param array<mixed> $overwrites The summary's overwrites map.
	 * @return array<int, string>
	 */
	private static function overwritten( array $overwrites ): array {
		$labels = [
			'database' => __( 'database', 'lw-migration' ),
			'media'    => __( 'media', 'lw-migration' ),
			'plugins'  => __( 'plugins', 'lw-migration' ),
			'themes'   => __( 'themes', 'lw-migration' ),
		];
		$parts  = [];
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $overwrites[ $key ] ) ) {
				$parts[] = $label;
			}
		}

		return $parts;
	}
}

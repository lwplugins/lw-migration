<?php
/**
 * Typed access to an import job's state.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\NetworkPrefixPairs;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Keys: archive, archiveOwned, archiveSize, package, multisite, subsiteId,
 * site (target snapshot), admin, usingPermalinks, databaseEntry, hasDatabase,
 * tmpPrefix, swapped, plugins, safetyBackup, input, passwordTrial (PasswordTrial),
 * extractBudget (ExtractBudget), ownershipWarned, inventory (ArchiveInventory, ConfirmStep's header walk);
 * on a network also target (TargetNetwork), kind (ArchiveKind),
 * blogs (BlogMap), unlisted, networkSummary and swappedTables (the final names
 * the swap brought in, saved before its RENAME).
 */
final class ImportState {

	/**
	 * @param Job $job Import job.
	 * @throws ImportException Before the source step saved the archive path.
	 */
	public static function archive( Job $job ): string {
		$path = (string) ( $job->state['archive'] ?? '' );
		if ( '' === $path ) {
			throw new ImportException( __( 'The archive is not known yet.', 'lw-migration' ) );
		}

		return $path;
	}

	/**
	 * @param Job $job Import job.
	 * @throws ImportException Before the archive's package.json was read.
	 */
	public static function package( Job $job ): PackageConfig {
		if ( ! isset( $job->state['package'] ) || ! is_array( $job->state['package'] ) ) {
			throw new ImportException( __( 'The archive\'s package.json has not been read yet.', 'lw-migration' ) );
		}

		return PackageConfig::fromArray( $job->state['package'] );
	}

	/**
	 * @param Job $job Import job.
	 * @throws ImportException Before the target site snapshot was saved.
	 */
	public static function site( Job $job ): Site {
		$site = $job->state['site'] ?? null;
		if ( ! is_array( $site ) || [] === $site ) {
			throw new ImportException( __( 'The target site has not been read yet.', 'lw-migration' ) );
		}

		return Site::fromArray( $site );
	}

	/**
	 * Null when the archive is encrypted and the password is not (or no longer) available.
	 *
	 * @param Job $job Import job.
	 * @throws ImportException Before the archive's package.json was read.
	 */
	public static function codec( Job $job ): ?ChunkCodec {
		$package  = self::package( $job );
		$password = $job->secrets['password'] ?? null;
		$password = is_string( $password ) && '' !== $password ? $password : null;
		if ( $package->isEncrypted() && null === $password ) {
			return null;
		}

		return PackageCodec::forPackage( $package, $password );
	}

	/**
	 * The imported subsite of a network archive; null for a single site.
	 *
	 * @param Job $job Import job.
	 */
	public static function subsiteId( Job $job ): ?int {
		$id = $job->state['subsiteId'] ?? null;

		return is_int( $id ) && $id > 1 ? $id : null;
	}

	/**
	 * Whether the job restores into a network (ImportJobs saved its target).
	 *
	 * @param Job $job Import job.
	 */
	public static function onNetwork( Job $job ): bool {
		return is_array( $job->state['target'] ?? null );
	}

	/**
	 * @param Job $job Import job.
	 * @throws ImportException When the job does not restore into a network.
	 */
	public static function target( Job $job ): TargetNetwork {
		if ( ! self::onNetwork( $job ) ) {
			throw new ImportException( __( 'The target network has not been read yet.', 'lw-migration' ) );
		}

		return TargetNetwork::fromArray( $job->state['target'] );
	}

	/**
	 * The archive kind of a restore into a network; '' before Validate or on a single site.
	 *
	 * @param Job $job Import job.
	 */
	public static function kind( Job $job ): string {
		return is_string( $job->state['kind'] ?? null ) ? $job->state['kind'] : '';
	}

	/**
	 * @param Job $job Import job.
	 * @throws ImportException Before Validate planned the sites of a network restore.
	 */
	public static function blogMap( Job $job ): BlogMap {
		if ( ! is_array( $job->state['blogs'] ?? null ) ) {
			throw new ImportException( __( 'The sites of the archive have not been planned yet.', 'lw-migration' ) );
		}

		return BlogMap::fromArray( $job->state['blogs'] );
	}

	/**
	 * The restore into a network; null on a single site. The network-wide
	 * plugins and super admins come from state.multisite (ValidateStep).
	 *
	 * @param Job $job Import job.
	 * @throws ImportException On a network before Validate planned the sites.
	 */
	public static function network( Job $job ): ?NetworkImport {
		if ( ! self::onNetwork( $job ) ) {
			return null;
		}

		$multisite = is_array( $job->state['multisite'] ?? null ) ? $job->state['multisite'] : [];
		$names     = static fn( $list ): array => array_values( array_filter( is_array( $list ) ? $list : [], 'is_string' ) );

		return new NetworkImport( self::kind( $job ), self::blogMap( $job ), self::target( $job ), $names( $multisite['plugins'] ?? [] ), $names( $multisite['admins'] ?? [] ) );
	}

	/**
	 * Temporary-table suffixes the swap leaves alone (decision 15): a sites or
	 * single-site archive's network tables wait under lwmsrc_ for the user merge.
	 *
	 * @param Job $job Import job.
	 * @return array<int, string>
	 */
	public static function heldSuffixes( Job $job ): array {
		$staged = in_array( self::kind( $job ), [ ArchiveKind::SITES, ArchiveKind::SINGLE ], true );

		return self::onNetwork( $job ) && $staged ? [ NetworkPrefixPairs::STAGE ] : [];
	}

	/**
	 * Review C1: the only table suffixes ("<newId>_") a sites or single-site
	 * restore into a network swaps in; [] otherwise (no restriction).
	 *
	 * @param Job $job Import job.
	 * @return array<int, string>
	 * @throws ImportException On a network before Validate planned the sites.
	 */
	public static function siteSuffixes( Job $job ): array {
		$network = [] === self::heldSuffixes( $job ) ? null : self::network( $job );

		return null === $network ? [] : NetworkPrefixPairs::siteSuffixes( $network );
	}

	public static function hasDatabase( Job $job ): bool {
		return ! empty( $job->state['hasDatabase'] );
	}

	/**
	 * When the job ends: a download address may carry access tokens, so
	 * job.json keeps only the display form of a URL source and no resolved
	 * download address.
	 *
	 * @param Job $job Import job.
	 * @return bool True when something was removed or shortened.
	 */
	public static function forgetAddress( Job $job ): bool {
		$changed = isset( $job->state['download']['url'] );
		unset( $job->state['download']['url'] );
		$location = $job->options['location'] ?? null;
		if ( ImportOptions::URL === ( $job->options['source'] ?? '' ) && is_string( $location ) ) {
			$shown                    = AddressRedactor::display( $location );
			$changed                  = $changed || $shown !== $location;
			$job->options['location'] = $shown;
		}

		return $changed;
	}
}

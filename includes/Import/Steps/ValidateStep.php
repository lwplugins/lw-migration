<?php
/**
 * Import step: checks the archive and reads its configuration.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetworkReader;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Memory;

defined( 'ABSPATH' ) || exit;

/**
 * End block, first entry, package.json, multisite.json, PHP extensions.
 */
final class ValidateStep implements StepInterface {

	private const CONFIG_MAX_BYTES = 16777216;

	/** Memory one bzip2 block needs to decode, with room to spare. */
	private const BZIP2_MEMORY = 83886080;

	public function run( Job $job, Deadline $deadline ): StepResult {
		$path   = ImportState::archive( $job );
		$reader = new Reader( $path );
		try {
			// validate() guarantees a package.json entry at offset 0.
			$reader->validate();
			$first     = $reader->entryAt( 0 );
			$package   = PackageConfig::fromJson( $reader->readContents( $first, self::CONFIG_MAX_BYTES ) );
			$multisite = null;
			$next      = $first->dataOffset() + $first->size;
			if ( $next < $reader->dataEnd() ) {
				$second = $reader->entryAt( $next );
				if ( Format::MULTISITE === $second->name ) {
					$multisite = MultisiteConfig::fromJson( $reader->readContents( $second, self::CONFIG_MAX_BYTES ) );
				}
			}
		} finally {
			$reader->close();
		}
		$this->checkSupport( $job, $package );
		$job->state['subsiteId']   = null;
		$job->state['package']     = $package->toArray();
		$job->state['archiveSize'] = (int) filesize( $path );
		if ( ImportState::onNetwork( $job ) ) {
			$job->state['multisite'] = $this->network( $job, $package, $multisite );
		} else {
			$job->state['multisite'] = null === $multisite ? null : $this->subsite( $job, $multisite );
		}

		return StepResult::done( __( 'Archive checked.', 'lw-migration' ) );
	}

	/**
	 * @param Job             $job       Import job.
	 * @param MultisiteConfig $multisite The archive's multisite.json.
	 * @return array{network: bool, sites: array<int, array<string, mixed>>}
	 * @throws ImportException For a whole-network archive or one that lists no site.
	 */
	private function subsite( Job $job, MultisiteConfig $multisite ): array {
		$sites = $multisite->sites();
		if ( [] === $sites && ! $multisite->isNetwork() ) {
			throw new ImportException( __( 'The archive\'s multisite.json lists no site, so it cannot be restored. The archive may be damaged.', 'lw-migration' ) );
		}
		if ( $multisite->isNetwork() || 1 !== count( $sites ) ) {
			throw new ImportException( __( 'This archive holds a whole multisite network. It can only be restored into a multisite network, not into a single site.', 'lw-migration' ) );
		}
		$blog                    = (int) ( $sites[0]['BlogID'] ?? 0 );
		$job->state['subsiteId'] = $blog > 1 ? $blog : null;

		return [
			'network' => false,
			'sites'   => $sites,
		];
	}

	/**
	 * A restore into a network: what the archive holds and where each of its sites goes.
	 *
	 * @param Job                  $job       Import job (state.target set by ImportJobs).
	 * @param PackageConfig        $package   The archive's package.json.
	 * @param MultisiteConfig|null $multisite The archive's multisite.json, if any.
	 * @return array{network: bool, sites: array<int, array<string, mixed>>, plugins: array<int, string>, admins: array<int, string>}
	 * @throws ImportException For an archive without sites or of more than one network (decision 17).
	 */
	private function network( Job $job, PackageConfig $package, ?MultisiteConfig $multisite ): array {
		$kind = ArchiveKind::of( $multisite );
		if ( ArchiveKind::NETWORK === $kind && null !== $multisite && count( $multisite->networks() ) > 1 ) {
			throw new ImportException( __( 'This archive holds more than one network. Restoring it is not supported.', 'lw-migration' ) );
		}
		$entries = null === $multisite ? [ SiteEntry::fromPackage( $package ) ] : $multisite->entries();
		$map     = BlogMap::plan( $kind, $entries, (string) $package->get( 'HomeURL', '' ), ImportState::target( $job ) );

		$job->state['kind']     = $kind;
		$job->state['blogs']    = $map->toArray();
		$job->state['unlisted'] = ArchiveKind::NETWORK === $kind ? TargetNetworkReader::unlisted( $map->oldIds() ) : [];

		return [
			'network' => ArchiveKind::NETWORK === $kind,
			'sites'   => array_map( static fn( SiteEntry $e ): array => $e->toArray(), $entries ),
			'plugins' => null === $multisite ? [] : $multisite->plugins(),
			'admins'  => null === $multisite ? [] : $multisite->admins(),
		];
	}

	private function checkSupport( Job $job, PackageConfig $package ): void {
		$compression = $package->compression();
		Codec::assertSupported( $compression );
		if ( $package->isEncrypted() ) {
			Cipher::assertSupported();
		}
		$free = Memory::available();
		if ( Codec::BZIP2 === $compression && null !== $free && $free < self::BZIP2_MEMORY ) {
			$job->addWarning( __( 'This archive is bzip2-compressed, which needs about 60 MB of PHP memory per file block. If the import stops with a memory error, raise the PHP memory limit.', 'lw-migration' ) );
		}
	}
}

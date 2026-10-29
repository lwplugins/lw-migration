<?php
/**
 * Import step: waits for the user's confirmation.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Import\ArchiveInventory;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\ImportSummary;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\NetworkSummary;
use LightweightPlugins\Migration\Multisite\SiteAddress;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The summary travels with the input request, so every driver can show it.
 * The live database size is always measured: the confirmation may switch the
 * safety backup on or off (a boolean `safetyBackup` next to `confirm`), which
 * is written into the job options here, before the safety-backup step reads them.
 * On a network the summary has a network part and the confirmation of a sites
 * or single-site archive may carry addresses (`sites`: index => address).
 * Every current address is checked before each question, so a refused one
 * (also a proposal) carries its reason at its site (sites[i].error); a
 * problem of the answer as a whole goes to network.error. Confirming while
 * any is refused asks again.
 *
 * Before the first question the archive's entry headers are walked once
 * (ArchiveInventory, resumable in the cursor, then kept in the state), so the
 * summary can tell what the archive holds; an archive that cannot be read
 * is asked about with its contents unknown.
 */
final class ConfirmStep implements StepInterface {

	/**
	 * @param \Closure      $freeSpace     fn( string $dir ): ?int.
	 * @param \Closure      $databaseBytes fn(): int.
	 * @param \Closure|null $siteAt        fn( string $domain, string $path, int $networkId ): ?int (network only).
	 */
	public function __construct( private \Closure $freeSpace, private \Closure $databaseBytes, private ?\Closure $siteAt = null ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! isset( $job->state[ ArchiveInventory::KEY ] ) ) {
			$inventory = $this->inventory( $job, $deadline );
			if ( ! $inventory->isFinished() ) {
				$job->cursor = $inventory->toArray();

				return StepResult::more( $inventory->progress(), __( 'Reading the archive\'s contents...', 'lw-migration' ) );
			}
			$job->state[ ArchiveInventory::KEY ] = $inventory->toArray();
			$job->cursor                         = [];
		}
		$given = $job->state['input']['sites'] ?? null;
		unset( $job->state['input']['sites'] );
		if ( true === ( $job->state['input']['confirm'] ?? null ) ) {
			$error = ImportState::onNetwork( $job ) ? $this->applyAddresses( $job, $given ) : null;
			if ( null !== $error || ( ImportState::onNetwork( $job ) && [] !== $this->problems( $job ) ) ) {
				unset( $job->state['input']['confirm'], $job->state['input']['safetyBackup'] );

				return $this->ask( $job, $error );
			}
			$choice = $job->state['input']['safetyBackup'] ?? null;
			if ( is_bool( $choice ) ) {
				$job->options['safetyBackup'] = $choice;
			}
			unset( $job->state['input']['safetyBackup'] );

			return StepResult::done( __( 'Import confirmed.', 'lw-migration' ) );
		}

		return $this->ask( $job, null );
	}

	/**
	 * Walks the entry headers on from the cursor until the deadline.
	 *
	 * @param Job      $job      Import job.
	 * @param Deadline $deadline Time budget.
	 */
	private function inventory( Job $job, Deadline $deadline ): ArchiveInventory {
		$inventory = ArchiveInventory::fromArray( $job->cursor );
		try {
			$reader = new Reader( ImportState::archive( $job ) );
			try {
				$inventory->walk( $reader, $deadline, ImportState::site( $job )->ownPlugin );
			} finally {
				$reader->close();
			}
		} catch ( ArchiveException $e ) {
			return ArchiveInventory::unreadable();
		}

		return $inventory;
	}

	/**
	 * @param Job         $job   Import job.
	 * @param string|null $error Why the last answer was refused.
	 */
	private function ask( Job $job, ?string $error ): StepResult {
		if ( ImportState::onNetwork( $job ) ) {
			$network = NetworkSummary::build( ImportState::kind( $job ), ImportState::blogMap( $job ), (array) ( $job->state['unlisted'] ?? [] ), $this->existing( $job ), $this->problems( $job ) );
			if ( null !== $error ) {
				$network['error'] = $error;
			}
			$job->state['networkSummary'] = $network;
		}

		return StepResult::needsInput(
			'confirm',
			ImportSummary::build( $job, ( $this->freeSpace )( ImportState::site( $job )->contentDir ), (int) ( $this->databaseBytes )() )
		);
	}

	/**
	 * Puts the given addresses of a sites or single-site archive into the map
	 * (refused ones too, so the summary shows them with their reason).
	 *
	 * @param Job   $job   Import job on a network.
	 * @param mixed $given The answer's `sites`, if any.
	 * @return string|null A problem of the answer as a whole (not of one site), or null.
	 */
	private function applyAddresses( Job $job, $given ): ?string {
		if ( null === $given ) {
			return null;
		}
		if ( ArchiveKind::NETWORK === ImportState::kind( $job ) ) {
			return __( 'This archive holds a whole network: its sites keep their addresses.', 'lw-migration' );
		}
		if ( ! is_array( $given ) ) {
			return __( 'Give the addresses by site number.', 'lw-migration' );
		}
		try {
			$job->state['blogs'] = ImportState::blogMap( $job )->withAddresses( $given, ImportState::target( $job ) )->toArray();
		} catch ( \InvalidArgumentException $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Index => reason for every current address of a sites or single-site
	 * archive that cannot be used; a network archive's addresses are fixed
	 * (checked when it was validated).
	 *
	 * @param Job $job Import job on a network.
	 * @return array<int, string>
	 */
	private function problems( Job $job ): array {
		if ( ArchiveKind::NETWORK === ImportState::kind( $job ) ) {
			return [];
		}

		return ImportState::blogMap( $job )->problems( ImportState::target( $job ) );
	}

	/**
	 * Index => id of the network's site at each planned address (sites and single-site archives).
	 *
	 * @param Job $job Import job on a network.
	 * @return array<int, int|null>
	 */
	private function existing( Job $job ): array {
		if ( null === $this->siteAt || ArchiveKind::NETWORK === ImportState::kind( $job ) ) {
			return [];
		}
		$to    = ImportState::target( $job );
		$found = [];
		foreach ( ImportState::blogMap( $job )->all() as $i => $m ) {
			try {
				$address = SiteAddress::check( (string) $m['url'], $to );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
			$found[ $i ] = ( $this->siteAt )( $address['domain'], $address['path'], $to->networkId );
		}

		return $found;
	}
}

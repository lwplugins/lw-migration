<?php
/**
 * The held staging tables of a sites restore into a network that stopped after the swap.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Import\Steps\NetworkUsersStep;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Final review M3: the archive's network tables (<tmp>lwmsrc_*) are held
 * through the swap for the user merge (NetworkUsersStep drops them). When a
 * sites or single-site restore fails after the swap, nothing will merge them
 * any more, so they are dropped here (only they: the swapped-in and replaced
 * tables stay), and the user is told when the merge had not finished.
 */
final class HeldStaging {

	/**
	 * @param Job      $job     Failed or cancelled import that may have swapped.
	 * @param \Closure $connect fn(): Connection.
	 * @return string The sentence for the swapped notice, '' when the users were merged or nothing was held.
	 */
	public static function afterSwappedFailure( Job $job, \Closure $connect ): string {
		$held = ImportState::heldSuffixes( $job );
		$tmp  = (string) ( $job->state['tmpPrefix'] ?? '' );
		if ( [] === $held || '' === $tmp ) {
			return '';
		}
		try {
			$swap = new TableSwap( $connect(), $tmp, ImportState::site( $job )->tablePrefix, ImportPlan::DEFAULT_EXTRA_PREFIXES, $held );
			foreach ( $swap->dropHeld() as $warning ) {
				/* translators: %s: technical message from the database */
				$job->addWarning( sprintf( __( 'Removing the temporary tables of this import: %s', 'lw-migration' ), $warning ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The daily sweep drops them later.
		}

		return self::merged( $job ) ? '' : __( 'The users of the archive were not merged into the network, so the restored sites\' authors may point to other users.', 'lw-migration' );
	}

	/**
	 * Whether the user merge had finished when the import stopped (its drop phase or a later step).
	 *
	 * @param Job $job Import job.
	 */
	private static function merged( Job $job ): bool {
		$step = (string) $job->currentStep();
		if ( SwapStep::KEY === $step ) {
			return false;
		}

		return NetworkUsersStep::KEY !== $step || 'drop' === ( $job->cursor['phase'] ?? '' );
	}
}

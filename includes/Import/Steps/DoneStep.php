<?php
/**
 * Import step: summary.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * What the drivers show at the end; warnings are already in the job. An
 * import without a database has no plugin decisions (state.plugins is absent).
 * A restore into a network also lists its sites (summary.sites: url, created).
 */
final class DoneStep implements StepInterface {

	public function run( Job $job, Deadline $deadline ): StepResult {
		$backup                = $job->state['safetyBackup']['name'] ?? null;
		$backup                = is_string( $backup ) && '' !== $backup ? $backup : null;
		$job->state['summary'] = [
			'safetyBackup' => $backup,
			'deactivated'  => array_map( 'strval', array_keys( (array) ( $job->state['plugins']['removed'] ?? [] ) ) ),
		];
		if ( ImportState::onNetwork( $job ) ) {
			return StepResult::done( self::network( $job, $backup ) );
		}
		if ( null === $backup ) {
			return StepResult::done( __( 'Import complete.', 'lw-migration' ) );
		}

		/* translators: %s: backup file name */
		return StepResult::done( sprintf( __( 'Import complete. The previous database is saved as %s.', 'lw-migration' ), $backup ) );
	}

	/**
	 * @param Job         $job    Import job on a network.
	 * @param string|null $backup Safety backup name.
	 */
	private static function network( Job $job, ?string $backup ): string {
		$sites = [];
		foreach ( ImportState::blogMap( $job )->all() as $m ) {
			$sites[] = [
				'url'     => '' !== $m['newHomeUrl'] ? $m['newHomeUrl'] : $m['url'],
				'created' => $m['created'],
			];
		}
		$job->state['summary']['sites'] = $sites;
		$count                          = count( $sites );
		if ( null === $backup ) {
			/* translators: %d: number of sites */
			return sprintf( _n( 'Import complete: %d site restored.', 'Import complete: %d sites restored.', $count, 'lw-migration' ), $count );
		}

		/* translators: 1: number of sites, 2: backup file name */
		return sprintf( _n( 'Import complete: %1$d site restored. The previous database is saved as %2$s.', 'Import complete: %1$d sites restored. The previous database is saved as %2$s.', $count, 'lw-migration' ), $count, $backup );
	}
}

<?php
/**
 * One measuring of what a backup would contain, in time-bounded slices.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Estimate;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Phases, in this order: on a network the sites' plugins and themes
 * (NetworkSoftware), then the database (DatabaseSizes: quick, so its row
 * gets its size first), then the files (FileSizes, the long one). The state
 * is a plain array the caller stores between requests; it holds the site
 * snapshot taken at the start, so every slice measures the same roots, as an
 * export job does. run() stops at the first unit of work after the deadline,
 * and once the database is measured: the caller saves its figures before the
 * walk begins, so a walk whose slices die (one huge folder, the host's time
 * limit) cannot take them along when the measuring is given up.
 */
final class EstimateScan {

	public const DONE = 'done';

	/**
	 * @param FileSizes            $files    The files phase.
	 * @param \Closure             $database fn(): DatabaseSizes — on a fresh connection every slice.
	 * @param NetworkSoftware|null $network  The sites phase; null on a single site.
	 */
	public function __construct( private FileSizes $files, private \Closure $database, private ?NetworkSoftware $network = null ) {
	}

	/**
	 * Removes the work files of an earlier measuring (unfinished ones too).
	 *
	 * @param Site $site Snapshot of the site now.
	 * @param int  $now  Unix time.
	 * @return array<string, mixed> A new state (nothing measured yet).
	 */
	public function start( Site $site, int $now ): array {
		$this->files->clear();

		return [
			'startedAt' => $now,
			'touchedAt' => $now,
			'phase'     => null === $this->network ? 'database' : 'sites',
			'site'      => $site->toArray(),
			'network'   => null === $this->network ? null : $this->network->start(),
			'database'  => null,
			'cursor'    => ( new EnumCursor() )->toArray(),
			'tally'     => ( new FileTally() )->toArray(),
			'work'      => '',
		];
	}

	/** Removes the work files of a measuring that is given up. */
	public function discard(): void {
		$this->files->clear();
	}

	/**
	 * Measures until done or the deadline; a failure leaves the given state
	 * as it was (the caller keeps the last saved one).
	 *
	 * @param array<string, mixed> $state    start() or an earlier run().
	 * @param Deadline             $deadline Time budget.
	 * @param int                  $now      Unix time.
	 * @param string               $work     This slice's work id (its lease token): the files it writes.
	 * @return array<string, mixed> The state afterwards (phase DONE when finished).
	 */
	public function run( array $state, Deadline $deadline, int $now, string $work ): array {
		$state['touchedAt'] = $now;
		do {
			switch ( $state['phase'] ) {
				case 'sites':
					$state = $this->sites( $state, $deadline );
					break;
				case 'database':
					$state = $this->database( $state, $deadline );
					if ( 'files' === $state['phase'] && is_array( $state['database'] ) ) {
						return $state;
					}
					break;
				default:
					$state = $this->files( $state, $deadline, $work );
			}
		} while ( self::DONE !== $state['phase'] && ! $deadline->reached() );

		return $state;
	}

	/**
	 * @param array<string, mixed> $state    State.
	 * @param Deadline             $deadline Time budget.
	 * @return array<string, mixed>
	 */
	private function sites( array $state, Deadline $deadline ): array {
		if ( null === $this->network || ! is_array( $state['network'] ) ) {
			$state['phase'] = 'database';
			return $state;
		}
		$state['network'] = $this->network->collect( $state['network'], $deadline );
		if ( $state['network']['done'] ) {
			$state['phase'] = 'database';
		}

		return $state;
	}

	/**
	 * A database this plugin cannot read (a drop-in without one MySQL
	 * connection, a failed size query) stays unmeasured (null); the files are
	 * measured all the same.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param Deadline             $deadline Time budget.
	 * @return array<string, mixed>
	 */
	private function database( array $state, Deadline $deadline ): array {
		try {
			$sizes = ( $this->database )();
			if ( ! is_array( $state['database'] ) ) {
				$state['database'] = $sizes->measure( Site::fromArray( (array) $state['site'] )->tablePrefix, null !== $this->network );
				if ( $deadline->reached() ) {
					return $state;
				}
			}
			$state['database'] = $sizes->count( $state['database'], $deadline );
		} catch ( DatabaseException $e ) {
			unset( $e ); // Nothing to show: the part stays unknown.
			$state['database'] = null;
			$state['phase']    = 'files';

			return $state;
		}
		if ( DatabaseSizes::done( $state['database'] ) ) {
			$state['phase'] = 'files';
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $state    State.
	 * @param Deadline             $deadline Time budget.
	 * @param string               $work     This slice's work id.
	 * @return array<string, mixed>
	 */
	private function files( array $state, Deadline $deadline, string $work ): array {
		$cursor = EnumCursor::fromArray( (array) $state['cursor'] );
		$tally  = new FileTally( (array) $state['tally'] );
		$done   = $this->files->run( Site::fromArray( (array) $state['site'] ), $cursor, $tally, $deadline, (string) ( $state['work'] ?? '' ), $work );

		$state['work']   = $work;
		$state['cursor'] = $cursor->toArray();
		$state['tally']  = $tally->toArray();
		$state['phase']  = $done ? self::DONE : 'files';

		return $state;
	}
}

<?php
/**
 * Export step: dumps the database.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DumpCursor;
use LightweightPlugins\Migration\Database\Dumper;
use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\TableLister;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Multisite\ExportScope;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * The table list is fixed on the first run so every tick dumps the same set.
 * Include/exclude names are only matched against the listed tables, never
 * put into SQL. A network backup dumps the tables of its ExportScope with the
 * scope's renames and row filters (built again on every tick from state.network).
 * The connection is built per tick (a reconnect replaces the
 * handle), and the cursor goes into the job only after the dumper returned.
 */
final class DatabaseStep implements StepInterface {

	/**
	 * @param JobStore $store   Job store.
	 * @param \Closure $connect fn(): Connection.
	 */
	public function __construct( private JobStore $store, private \Closure $connect ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$options = ExportOptions::fromArray( $job->options );
		if ( $options->noDatabase ) {
			return StepResult::done();
		}
		$db    = ( $this->connect )();
		$site  = Site::fromArray( (array) $job->state['site'] );
		$scope = isset( $job->state['network'] ) ? self::scope( $site, (array) $job->state['network'] ) : null;
		if ( ! isset( $job->state['tables'] ) ) {
			$job->state['tables'] = $this->objects( $db, $site, $options, $job, $scope );
		}
		$tables = array_values( array_map( 'strval', (array) $job->state['tables']['tables'] ) );
		$views  = array_values( array_map( 'strval', (array) $job->state['tables']['views'] ) );
		$dump   = null === $scope
			? new DumpOptions( $site->tablePrefix, $options->noSpamComments, $options->noPostRevisions, DumpOptions::STATUS_OPTIONS, Options::DUMP_EXCLUDED_PREFIXES )
			: self::networkDump( $db, $site, $options, $scope, $tables );
		$path   = WorkDir::path( $this->store, $job ) . '/' . ExportFiles::DATABASE;
		Files::privateFile( $path );
		$dumper = new Dumper( $db, $dump, $tables, $views, $path );
		$cursor = DumpCursor::fromArray( $job->cursor );
		$done   = $dumper->run( $cursor, $deadline );

		$job->cursor = $cursor->toArray();
		if ( $done ) {
			return StepResult::done( __( 'Database exported.', 'lw-migration' ) );
		}
		$total = max( 1, count( $tables ) + count( $views ) );

		return StepResult::more(
			(int) ( 100 * $cursor->index / $total ),
			// The bytes written move on every tick, also within one huge table: a connected site driving this job
			// gives up on one that shows the same state for too long (RemoteJobStep).
			/* translators: 1: current table number, 2: number of tables, 3: size of the dump so far, e.g. "12 MB" */
			sprintf( __( 'Exporting the database: table %1$d of %2$d (%3$s written)...', 'lw-migration' ), min( $cursor->index + 1, $total ), $total, size_format( $cursor->fileOffset, 1 ) )
		);
	}

	/**
	 * @param Connection       $db      Source database.
	 * @param Site             $site    Site snapshot.
	 * @param ExportOptions    $options Export options.
	 * @param Job              $job     Job (receives warnings).
	 * @param ExportScope|null $scope   The sites of a network backup; null on a single site.
	 * @return array{tables: array<int, string>, views: array<int, string>}
	 */
	private function objects( Connection $db, Site $site, ExportOptions $options, Job $job, ?ExportScope $scope ): array {
		$lister = new TableLister( $db );
		if ( null === $scope ) {
			$prefixes = '' === $site->tablePrefix ? [] : array_merge( [ $site->tablePrefix ], ImportPlan::DEFAULT_EXTRA_PREFIXES );
		} else {
			$prefixes = $scope->listPrefixes();
		}
		$listed   = $lister->list( $prefixes );
		$existing = $lister->list( [] )['tables'];
		foreach ( array_diff( $options->includeTables, $existing ) as $missing ) {
			/* translators: %s: table name */
			$job->addWarning( sprintf( __( 'The table to include does not exist: %s', 'lw-migration' ), $missing ) );
		}
		$include = array_values( array_intersect( $options->includeTables, $existing ) );
		$tables  = null === $scope ? $listed['tables'] : $scope->tables( $listed['tables'] );
		$views   = null === $scope ? $listed['views'] : $scope->tables( $listed['views'] );
		if ( null !== $scope && ExportScope::NETWORK !== $scope->mode() ) {
			$include = self::withinScope( $scope, $include, $job );
		}

		return [
			'tables' => TableLister::select( $tables, $options->excludeTables, $include ),
			'views'  => array_values( array_diff( $views, $options->excludeTables ) ),
		];
	}

	/**
	 * A backup of chosen sites carries only their tables (and the network's
	 * shared ones the scope keeps): an included table outside them is left out.
	 *
	 * @param ExportScope        $scope   The chosen sites.
	 * @param array<int, string> $include Existing tables asked for.
	 * @param Job                $job     Job (receives warnings).
	 * @return array<int, string>
	 */
	private static function withinScope( ExportScope $scope, array $include, Job $job ): array {
		$kept = $scope->tables( $include );
		foreach ( array_diff( $include, $kept ) as $outside ) {
			/* translators: %s: table name */
			$job->addWarning( sprintf( __( 'The table to include is not part of the chosen sites: %s', 'lw-migration' ), $outside ) );
		}

		return $kept;
	}

	/**
	 * @param Site                 $site    Site snapshot (its prefix is the network's base prefix).
	 * @param array<string, mixed> $network state.network.
	 * @return ExportScope
	 * @throws ExportException For a base prefix a network backup cannot carry (R24).
	 */
	private static function scope( Site $site, array $network ): ExportScope {
		$blogIds = array_map( 'intval', (array) ( $network['blogIds'] ?? [] ) );
		$chosen  = 'network' === ( $network['mode'] ?? '' ) ? null : array_map( 'intval', (array) ( $network['chosen'] ?? [] ) );

		return new ExportScope( $site->tablePrefix, $blogIds, $chosen );
	}

	/**
	 * The dump options of a network backup: the scope's renames, prefixed columns and row filters.
	 *
	 * @param Connection         $db      Source database.
	 * @param Site               $site    Site snapshot.
	 * @param ExportOptions      $options Export options.
	 * @param ExportScope        $scope   The exported sites.
	 * @param array<int, string> $tables  Tables of the dump.
	 * @return DumpOptions
	 */
	private static function networkDump( Connection $db, Site $site, ExportOptions $options, ExportScope $scope, array $tables ): DumpOptions {
		$quote = static fn( string $value ): string => "'" . $db->escape( $value ) . "'";

		return new DumpOptions(
			$site->tablePrefix,
			$options->noSpamComments,
			$options->noPostRevisions,
			DumpOptions::STATUS_OPTIONS,
			Options::DUMP_EXCLUDED_PREFIXES,
			$scope->renamer(),
			$scope->prefixColumns( $tables ),
			$scope->tableWhere( $options->noSpamComments, $options->noPostRevisions, DumpOptions::STATUS_OPTIONS, Options::DUMP_EXCLUDED_PREFIXES, $quote ),
			$scope->optionsTables()
		);
	}
}

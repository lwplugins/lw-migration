<?php
/**
 * Import step: merges a sites or single-site archive's users into the network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Multisite\UserMerge;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Right after the swap (decision 19). Phases, kept in the cursor {phase,
 * index, targets, max}: users (add missing users, find what to renumber),
 * shift, remap, unshift (one statement per target, the cursor saved after
 * each and before the first statement of every new phase, so a replay never
 * runs shift once remap started), meta (allowed user meta), drop (the
 * held staging tables, then the run's reservation). A database error after
 * the swap is a warning, never a failure (ruling R13): the step always ends
 * through drop. A whole-network archive and a single site have nothing
 * staged: nothing to do.
 */
final class NetworkUsersStep implements StepInterface {

	public const KEY = 'network_users';

	/** Each renumbering phase and the one after it. */
	private const NEXT = [
		'shift'   => 'remap',
		'remap'   => 'unshift',
		'unshift' => 'meta',
	];

	/** @var \Closure fn( Connection $db, string $base, string $tmp ): UserMerge */
	private \Closure $merge;

	/**
	 * @param JobStore      $store   Job store (the cursor is saved between statements).
	 * @param \Closure      $connect fn(): Connection.
	 * @param \Closure      $forget  fn( string $runId ): bool, removes the run from the site's registry.
	 * @param \Closure|null $merge   fn( Connection $db, string $base, string $tmp ): UserMerge (tests).
	 */
	public function __construct( private JobStore $store, private \Closure $connect, private \Closure $forget, ?\Closure $merge = null ) {
		$this->merge = $merge ?? static fn( Connection $db, string $base, string $tmp ): UserMerge => new UserMerge( $db, $base, $tmp );
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$tmp = (string) ( $job->state['tmpPrefix'] ?? '' );
		if ( [] === ImportState::heldSuffixes( $job ) || ! ImportState::hasDatabase( $job ) || '' === $tmp ) {
			return StepResult::done();
		}
		$db    = ( $this->connect )();
		$base  = ImportState::site( $job )->tablePrefix;
		$to    = ImportState::target( $job );
		$merge = ( $this->merge )( $db, $base, $tmp );
		$phase = (string) ( $job->cursor['phase'] ?? 'users' );
		if ( 'users' === $phase ) {
			return $merge->hasStaging() ? $this->users( $job, $merge, $to ) : StepResult::done();
		}
		if ( isset( self::NEXT[ $phase ] ) ) {
			return $this->renumber( $job, $merge, $deadline, $phase );
		}
		if ( 'meta' === $phase ) {
			$this->meta( $job, $merge, $to );
		}

		return $this->drop( $job, $db, $base, $tmp );
	}

	/**
	 * Adds the missing users and plans the renumbering. The largest live id
	 * and the clash warnings are saved first (review C1/M1): a replay after
	 * the insert still knows which users this merge added.
	 *
	 * @param Job           $job   Import job.
	 * @param UserMerge     $merge The merge.
	 * @param TargetNetwork $to    Target network.
	 */
	private function users( Job $job, UserMerge $merge, TargetNetwork $to ): StepResult {
		if ( ! isset( $job->cursor['liveMax'] ) && ! $this->prepare( $job, $merge ) ) {
			return StepResult::more( 30 );
		}
		$liveMax = (int) $job->cursor['liveMax'];
		try {
			$merge->insertUsers();
			$next = $this->plan( $job, $merge, $to, $liveMax );
		} catch ( QueryException $e ) {
			self::notMerged( $job, $e );
			$next = [ 'phase' => 'drop' ];
		}
		$job->cursor = $next;
		$this->store->save( $job );

		return StepResult::more( 30, __( 'Merging the users of the archive...', 'lw-migration' ) );
	}

	/**
	 * The cursor after the user insert: the renumbering targets, or straight
	 * to meta when ids are too large (review I3) or nothing is to renumber. A
	 * column that already holds values above the offset is left out (review I2).
	 *
	 * @param Job           $job     Import job.
	 * @param UserMerge     $merge   The merge.
	 * @param TargetNetwork $to      Target network.
	 * @param int           $liveMax Largest live id before the insert.
	 * @return array<string, mixed>
	 * @throws QueryException When the ids or columns cannot be read.
	 */
	private function plan( Job $job, UserMerge $merge, TargetNetwork $to, int $liveMax ): array {
		$meta = [
			'phase'   => 'meta',
			'liveMax' => $liveMax,
		];
		$max  = $merge->maxStagedId();
		if ( $max >= UserMerge::OFFSET || $merge->liveMaxId() >= UserMerge::OFFSET ) {
			$job->addWarning( __( 'User IDs in this archive are too large to renumber, so the authors of its posts may point to other users. Check the authors after the restore.', 'lw-migration' ) );

			return $meta;
		}
		$targets = [];
		foreach ( $merge->targets( self::newIds( $job, $to ), self::swappedTables( $job ) ) as [ $table, $column ] ) {
			if ( $merge->holdsShifted( $table, $column ) ) {
				/* translators: %s: table name */
				$job->addWarning( sprintf( __( 'The user IDs in %s were not renumbered: the table already holds IDs outside the range this restore can renumber.', 'lw-migration' ), $table ) );
				continue;
			}
			$targets[] = [ $table, $column ];
		}

		return $max > 0 && [] !== $targets ? array_merge(
			$meta,
			[
				'phase'   => 'shift',
				'index'   => 0,
				'targets' => $targets,
				'max'     => $max,
			]
		) : $meta;
	}

	/**
	 * Saves the largest live user id and warns about the clashes, once.
	 *
	 * @param Job       $job   Import job.
	 * @param UserMerge $merge The merge.
	 * @return bool False when the merge cannot go on (the drop phase is saved).
	 */
	private function prepare( Job $job, UserMerge $merge ): bool {
		try {
			$liveMax = $merge->liveMaxId();
			$clashes = $merge->emailClashes();
			$slugs   = $merge->nicenameClashes();
			$matches = $merge->loginMatches();
		} catch ( QueryException $e ) {
			self::notMerged( $job, $e );
			$job->cursor = [ 'phase' => 'drop' ];
			$this->store->save( $job );

			return false;
		}
		foreach ( $clashes as $new => $existing ) {
			/* translators: 1: login of a user added from the archive, 2: login of an existing user */
			$job->addWarning( sprintf( __( 'The added user %1$s has the same e-mail address as the existing user %2$s. Change one of them, so password resets reach the right user.', 'lw-migration' ), $new, $existing ) );
		}
		foreach ( $slugs as $new => $existing ) {
			/* translators: 1: login of a user added from the archive, 2: login of an existing user */
			$job->addWarning( sprintf( __( 'The added user %1$s has the same profile address name as the existing user %2$s, so their author pages share an address. Change one of them.', 'lw-migration' ), $new, $existing ) );
		}
		foreach ( $matches as $archived => $existing ) {
			/* translators: 1: login of a user in the archive, 2: login of an existing user */
			$job->addWarning( sprintf( __( 'The archive\'s user %1$s was taken as the existing user %2$s, because this network treats the two logins as the same. Check that they are the same person.', 'lw-migration' ), $archived, $existing ) );
		}
		$job->cursor = [
			'phase'   => 'users',
			'liveMax' => $liveMax,
		];
		$this->store->save( $job );

		return true;
	}

	/**
	 * One phase of the renumbering, a statement per target.
	 *
	 * @param Job       $job      Import job.
	 * @param UserMerge $merge    The merge.
	 * @param Deadline  $deadline Time budget.
	 * @param string    $phase    shift, remap or unshift.
	 */
	private function renumber( Job $job, UserMerge $merge, Deadline $deadline, string $phase ): StepResult {
		$targets = self::targetList( $job->cursor['targets'] ?? null );
		$max     = (int) ( $job->cursor['max'] ?? 0 );
		$count   = count( $targets );
		$message = __( 'Renumbering the users of the restored sites...', 'lw-migration' );
		for ( $i = (int) ( $job->cursor['index'] ?? 0 ); $i < $count; ) {
			[ $table, $column ] = $targets[ $i ];
			try {
				if ( 'shift' === $phase ) {
					$merge->shift( $table, $column, $max );
				} elseif ( 'remap' === $phase ) {
					$merge->remap( $table, $column );
				} else {
					$merge->unshift( $table, $column );
				}
			} catch ( QueryException $e ) {
				/* translators: 1: table name, 2: database error number */
				$job->addWarning( sprintf( __( 'The user IDs in %1$s could not be renumbered (database error %2$d).', 'lw-migration' ), $table, $e->errno() ) );
			}
			$job->cursor['index'] = ++$i;
			$this->store->save( $job );
			if ( $i < $count && $deadline->reached() ) {
				return StepResult::more( 50, $message );
			}
		}
		$job->cursor = array_merge(
			$job->cursor,
			[
				'phase' => self::NEXT[ $phase ],
				'index' => 0,
			]
		);
		$this->store->save( $job );

		return StepResult::more( 70, $message );
	}

	/**
	 * The allowed user meta (roles on the restored sites checked first, review
	 * N1; never network meta, review I1); then the drop phase is saved.
	 *
	 * @param Job           $job   Import job.
	 * @param UserMerge     $merge The merge.
	 * @param TargetNetwork $to    Target network.
	 */
	private function meta( Job $job, UserMerge $merge, TargetNetwork $to ): void {
		[ $blogIds, $hosts ] = self::moves( $job );
		try {
			$refused = $merge->sanitizeCapabilities( self::newIds( $job, $to ) );
			if ( [] !== $refused ) {
				/* translators: %s: comma-separated logins */
				$job->addWarning( sprintf( __( 'These users got no role on the restored sites, because the archive gave them roles those sites do not have or rights beyond a role: %s. Give them their roles again under the site\'s Users.', 'lw-migration' ), implode( ', ', $refused ) ) );
			}
			$merge->insertUserMeta( $blogIds, $hosts, $to->mainSiteId, $to->blogDomain( $to->domain ), (int) ( $job->cursor['liveMax'] ?? PHP_INT_MAX ) );
		} catch ( QueryException $e ) {
			self::notMerged( $job, $e );
		}
		$job->cursor = [ 'phase' => 'drop' ];
		$this->store->save( $job );
	}

	/**
	 * Drops the held staging tables; the run is released once nothing of it is left.
	 *
	 * @param Job        $job  Import job.
	 * @param Connection $db   Database.
	 * @param string     $base Base prefix.
	 * @param string     $tmp  Temporary prefix.
	 */
	private function drop( Job $job, Connection $db, string $base, string $tmp ): StepResult {
		foreach ( ( new TableSwap( $db, $tmp, $base, ImportPlan::DEFAULT_EXTRA_PREFIXES, ImportState::heldSuffixes( $job ) ) )->dropHeld() as $warning ) {
			/* translators: %s: technical message from the database */
			$job->addWarning( sprintf( __( 'Database note after the swap: %s', 'lw-migration' ), $warning ) );
		}
		try {
			RunMarker::releaseIfUnused( $db, $tmp, $this->forget );
		} catch ( QueryException $e ) {
			unset( $e ); // The daily sweep frees the run later.
		}

		return StepResult::done( __( 'Users merged into the network.', 'lw-migration' ) );
	}

	/**
	 * @param Job            $job Import job.
	 * @param QueryException $e   The error.
	 */
	private static function notMerged( Job $job, QueryException $e ): void {
		/* translators: %d: database error number */
		$job->addWarning( sprintf( __( 'The users of the archive could not be merged (database error %d), so the restored sites\' authors may point to other users.', 'lw-migration' ), $e->errno() ) );
	}

	/**
	 * @param Job           $job Import job.
	 * @param TargetNetwork $to  Target network.
	 * @return array<int, int> The restored sites' ids (never the main site).
	 */
	private static function newIds( Job $job, TargetNetwork $to ): array {
		$ids = [];
		foreach ( ImportState::blogMap( $job )->all() as $map ) {
			$id = $map['newId'] ?? null;
			if ( is_int( $id ) && $id > 1 && $id !== $to->mainSiteId ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Ruling R5 / Task 7 carry: the swap's table list counts only once the swap is recorded.
	 *
	 * @param Job $job Import job.
	 * @return array<int, string>
	 */
	private static function swappedTables( Job $job ): array {
		$tables = $job->state['swappedTables'] ?? null;

		return ! empty( $job->state['swapped'] ) && is_array( $tables ) ? array_values( array_filter( $tables, 'is_string' ) ) : [];
	}

	/**
	 * @param Job $job Import job.
	 * @return array{0: array<int|string, int>, 1: array<string, string>} Old blog id ('' for a single site) => new id; old host => new host.
	 */
	private static function moves( Job $job ): array {
		$blogIds = [];
		$hosts   = [];
		foreach ( ImportState::blogMap( $job )->all() as $map ) {
			$id = $map['newId'] ?? null;
			if ( ! is_int( $id ) ) {
				continue;
			}
			$old                                        = SiteEntry::fromArray( (array) $map['old'] );
			$blogIds[ (string) ( $old->blogId ?? '' ) ] = $id;
			$host                                       = self::hostOf( (string) ( $map['newHomeUrl'] ?? '' ) );
			if ( '' !== $old->domain && '' !== $host ) {
				$hosts[ $old->domain ] = $host;
			}
		}

		return [ $blogIds, $hosts ];
	}

	/**
	 * Host of an address with :port when it has one (as wp_blogs.domain keeps it).
	 *
	 * @param string $url Address.
	 */
	private static function hostOf( string $url ): string {
		$host = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of a stored address.
		$port = parse_url( $url, PHP_URL_PORT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of a stored address.

		return '' === $host || ! is_int( $port ) ? $host : $host . ':' . $port;
	}

	/**
	 * @param mixed $list Saved cursor targets.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function targetList( $list ): array {
		$targets = [];
		foreach ( is_array( $list ) ? $list : [] as $target ) {
			if ( is_array( $target ) && is_string( $target[0] ?? null ) && is_string( $target[1] ?? null ) ) {
				$targets[] = [ $target[0], $target[1] ];
			}
		}

		return $targets;
	}
}

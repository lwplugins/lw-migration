<?php
/**
 * Reserves a temporary-table run (lwtXXXX_) for a job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;

defined( 'ABSPATH' ) || exit;

/**
 * Picks a random lwtXXXX_ prefix whose temporary, extra and replaced-table
 * prefixes (lwt/lwx/lwo/lwy) match no table or view (a RunMarker of any site
 * is an lwy table), reserves it database-wide (RunMarker) and registers its
 * run id as this site's, all before any other table of the run exists (the
 * daily sweep drops only registered runs). Each candidate is saved in the job
 * (state.tmpCandidate, with the token its marker will carry) before it is
 * reserved, so a request that dies in between leaves a reservation the replay
 * recognises; another site's marker (other token) is never taken over. Used
 * by the import's database step and the database reset.
 */
final class TmpRun {

	/** State key: {prefix, token} of the run id being reserved (saved before the reservation). */
	public const CANDIDATE = 'tmpCandidate';

	public const TRIES = 20;

	/** @var \Closure fn( string $tmpPrefix ): bool, records the run as this site's (TmpRunRegistry). */
	private \Closure $register;

	/** @var \Closure fn(): string, a candidate temporary prefix. */
	private \Closure $newPrefix;

	/**
	 * @param JobStore      $store     Job store (the candidate is saved before each reservation).
	 * @param \Closure      $register  fn( string $tmpPrefix ): bool.
	 * @param \Closure|null $newPrefix fn(): string; default TableSwap::newTempPrefix().
	 */
	public function __construct( private JobStore $store, \Closure $register, ?\Closure $newPrefix = null ) {
		$this->register  = $register;
		$this->newPrefix = $newPrefix ?? static fn(): string => TableSwap::newTempPrefix();
	}

	/**
	 * The caller stores the prefix in the job and removes state.tmpCandidate.
	 *
	 * @param Connection $db         Site database.
	 * @param string     $basePrefix Site table prefix.
	 * @param Job        $job        Job that reserves the run.
	 * @return string The reserved and registered lwtXXXX_ prefix.
	 * @throws TmpRunException With NO_PREFIX when every try hit a prefix in use, UNREGISTERED when the run cannot be recorded (its marker is dropped).
	 */
	public function reserve( Connection $db, string $basePrefix, Job $job ): string {
		$prefix = self::reservedBefore( $db, $job ) ?? $this->freePrefix( $db, $basePrefix, $job );
		if ( true !== ( $this->register )( $prefix ) ) {
			RunMarker::drop( $db, $prefix );
			unset( $job->state[ self::CANDIDATE ] );
			throw new TmpRunException( TmpRunException::UNREGISTERED );
		}

		return $prefix;
	}

	/**
	 * @param Job $job Job.
	 * @return array{prefix: string, token: string}|null The saved candidate of a reservation in progress.
	 */
	public static function candidate( Job $job ): ?array {
		$saved  = $job->state[ self::CANDIDATE ] ?? null;
		$prefix = is_array( $saved ) ? (string) ( $saved['prefix'] ?? '' ) : '';
		$token  = is_array( $saved ) ? (string) ( $saved['token'] ?? '' ) : '';

		return PrefixRules::isValidTempPrefix( $prefix ) && '' !== $token ? [
			'prefix' => $prefix,
			'token'  => $token,
		] : null;
	}

	/**
	 * @param Connection $db         Site database.
	 * @param string     $basePrefix Site table prefix.
	 * @param Job        $job        Job.
	 * @throws TmpRunException When every try hit a prefix in use.
	 */
	private function freePrefix( Connection $db, string $basePrefix, Job $job ): string {
		$objects = [];
		foreach ( $db->query( 'SHOW FULL TABLES' ) as $row ) {
			$objects[] = (string) array_values( $row )[0]; // Base tables and views.
		}
		for ( $i = 0; $i < self::TRIES; $i++ ) {
			$prefix = (string) ( $this->newPrefix )();
			$swap   = new TableSwap( $db, $prefix, $basePrefix );
			if ( self::anyStartsWith( $objects, [ $prefix, $swap->extraTmpPrefix(), $swap->oldPrefix(), $swap->extraOldPrefix() ] ) ) {
				continue;
			}
			$token                         = RunMarker::newToken();
			$job->state[ self::CANDIDATE ] = [
				'prefix' => $prefix,
				'token'  => $token,
			];
			$this->store->save( $job );
			if ( RunMarker::reserve( $db, $prefix, $token ) ) {
				return $prefix; // Reserved for the whole database: no other site can pick it now.
			}
		}
		throw new TmpRunException( TmpRunException::NO_PREFIX );
	}

	/**
	 * The run id this job reserved in a request that died before registering
	 * it: the saved candidate whose marker carries this job's token.
	 *
	 * @param Connection $db  Site database.
	 * @param Job        $job Job.
	 */
	private static function reservedBefore( Connection $db, Job $job ): ?string {
		$candidate = self::candidate( $job );

		return null !== $candidate && RunMarker::heldWith( $db, $candidate['prefix'], $candidate['token'] ) ? $candidate['prefix'] : null;
	}

	/**
	 * Case-insensitive, since table names may fold on this server.
	 *
	 * @param array<int, string> $tables   Table and view names.
	 * @param array<int, string> $prefixes Prefixes.
	 */
	private static function anyStartsWith( array $tables, array $prefixes ): bool {
		foreach ( $tables as $table ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strncasecmp( $table, $prefix, strlen( $prefix ) ) ) {
					return true;
				}
			}
		}

		return false;
	}
}

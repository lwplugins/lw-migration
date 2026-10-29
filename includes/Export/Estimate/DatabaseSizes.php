<?php
/**
 * Measures the database part of a backup.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Estimate;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\TableLister;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The tables are the ones a backup dumps (TableLister with the export's
 * prefixes: the site's, or on a network the network's base prefix, and the
 * extra prefixes), sized by the server's own figures (information_schema:
 * data plus index length, an approximation the server keeps). Then the spam
 * comments and revisions a backup may leave out are counted, one table per
 * cheap COUNT query (on a network each site's comments and posts table),
 * until the time budget is used; a table that cannot be counted is skipped.
 * Table names come from the server's own listing, never from a request.
 */
final class DatabaseSizes {

	public function __construct( private Connection $db ) {
	}

	/**
	 * Lists and sizes the tables (one listing, one size query).
	 *
	 * @param string $prefix  Base table prefix.
	 * @param bool   $network Whether every site's tables of a network are counted.
	 * @return array{bytes: int, tables: int, counted: array<int, string>, index: int, spamComments: int, revisions: int}
	 */
	public function measure( string $prefix, bool $network ): array {
		$prefixes = '' === $prefix ? [] : array_merge( [ $prefix ], ImportPlan::DEFAULT_EXTRA_PREFIXES );
		$tables   = ( new TableLister( $this->db ) )->list( $prefixes )['tables'];
		$sizes    = [];
		foreach ( $this->db->query( $this->sizeQuery( $prefixes ) ) as $row ) {
			$sizes[ (string) ( $row['n'] ?? '' ) ] = (int) ( $row['b'] ?? 0 );
		}
		$pattern = '/^' . preg_quote( $prefix, '/' ) . ( $network ? '([0-9]+_)?' : '' ) . '(comments|posts)\z/';

		return [
			'bytes'        => array_sum( array_map( static fn( string $t ): int => $sizes[ $t ] ?? 0, $tables ) ),
			'tables'       => count( $tables ),
			'counted'      => array_values( array_filter( $tables, static fn( string $t ): bool => 1 === preg_match( $pattern, $t ) ) ),
			'index'        => 0,
			'spamComments' => 0,
			'revisions'    => 0,
		];
	}

	/**
	 * The size query, limited in SQL to the listed prefixes (a shared schema
	 * may hold thousands of other tables, and MySQL may refresh the
	 * statistics of every table it reads here). Each prefix is a LIKE pattern
	 * with its wildcards escaped and the value escaped by the connection, as
	 * TableLister builds its listing; no prefix (an empty table prefix) means
	 * every table, as the backup lists them. The listed tables are matched
	 * again in PHP.
	 *
	 * @param array<int, string> $prefixes Table name prefixes.
	 */
	private function sizeQuery( array $prefixes ): string {
		$likes = array_map( fn( string $p ): string => "TABLE_NAME LIKE '" . $this->db->escape( addcslashes( $p, '\\%_' ) ) . "%'", $prefixes );

		return "SELECT TABLE_NAME AS n, DATA_LENGTH + INDEX_LENGTH AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
			. ( [] === $likes ? '' : ' AND (' . implode( ' OR ', $likes ) . ')' );
	}

	/**
	 * Counts table by table from the saved index until done or the deadline.
	 *
	 * @param array{bytes: int, tables: int, counted: array<int, string>, index: int, spamComments: int, revisions: int} $state measure() result, as far as counted.
	 * @param Deadline                                                                                                   $deadline Time budget.
	 * @return array{bytes: int, tables: int, counted: array<int, string>, index: int, spamComments: int, revisions: int}
	 */
	public function count( array $state, Deadline $deadline ): array {
		$total = count( $state['counted'] );
		while ( $state['index'] < $total ) {
			$table = $state['counted'][ $state['index'] ];
			$spam  = str_ends_with( $table, 'comments' );
			$where = $spam ? "`comment_approved` = 'spam'" : "`post_type` = 'revision'";
			try {
				$rows           = $this->db->query( 'SELECT COUNT(*) AS n FROM ' . DumpFormat::quoteIdentifier( $table ) . ' WHERE ' . $where );
				$key            = $spam ? 'spamComments' : 'revisions';
				$state[ $key ] += (int) ( $rows[0]['n'] ?? 0 );
			} catch ( DatabaseException $e ) {
				unset( $e ); // Not a core table after all (no such column): nothing a backup would leave out.
			}
			++$state['index'];
			if ( $deadline->reached() ) {
				break;
			}
		}

		return $state;
	}

	/**
	 * @param array{counted: array<int, string>, index: int} $state Database state.
	 */
	public static function done( array $state ): bool {
		return $state['index'] >= count( $state['counted'] );
	}
}

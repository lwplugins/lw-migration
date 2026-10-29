<?php
/**
 * The live sites a network archive leaves out, kept in the restored sites table.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Final review I2/I3: every live site the archive does not hold comes into
 * the swapped-in sites table marked deleted (registration kept), so it stays
 * visible and restorable and its id is never handed out again. One whose
 * address equals an archive site's (domain and path compared without letter
 * case and trailing slash; the domain carries the port) would shadow it in
 * WordPress' site lookup, so it is kept under a unique path below its own
 * (<path>lwm-left-out-<id>/, or /lwm-left-out-<id>/ when that would not fit)
 * and named (review flag: site identity collision). Only the temporary table
 * is written; rows already there are left, so a replay changes nothing.
 */
final class LeftOutSites {

	private const COLUMNS = 'blog_id, site_id, domain, registered, public, archived, mature, spam, lang_id';

	/** Longest path wp_blogs holds. */
	private const PATH_MAX = 100;

	/**
	 * @param Connection $db   Database.
	 * @param string     $tmp  Temporary sites table, quoted (the only one written).
	 * @param string     $live Live sites table, quoted (only read).
	 */
	public function __construct( private Connection $db, private string $tmp, private string $live ) {
	}

	public function keep(): void {
		$archive = "(SELECT blog_id, domain, path FROM {$this->tmp}) AS archive";
		$clash   = "EXISTS (SELECT 1 FROM {$archive} WHERE " . self::key( 'archive' ) . ' = ' . self::key( 'l' ) . ')';
		$aside   = "CONCAT(TRIM(TRAILING '/' FROM l.path), '/lwm-left-out-', l.blog_id, '/')";
		$path    = "CASE WHEN NOT {$clash} THEN l.path WHEN CHAR_LENGTH({$aside}) <= " . self::PATH_MAX . " THEN {$aside} ELSE CONCAT('/lwm-left-out-', l.blog_id, '/') END";
		$cols    = implode( ', ', array_map( static fn( string $c ): string => 'l.' . trim( $c ), explode( ',', self::COLUMNS ) ) );
		$this->db->execute( "INSERT INTO {$this->tmp} (" . self::COLUMNS . ", path, last_updated, deleted) SELECT {$cols}, {$path}, UTC_TIMESTAMP(), 1 FROM {$this->live} AS l WHERE l.blog_id NOT IN (SELECT blog_id FROM (SELECT blog_id FROM {$this->tmp}) AS ids)" );
	}

	/**
	 * The left-out sites that were moved aside.
	 *
	 * @param array<int, int> $ids Ids of the left-out sites.
	 * @return array<int, string> Id => new address (domain and path).
	 */
	public function movedAside( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( [] === $ids ) {
			return [];
		}
		$moved = [];
		foreach ( $this->db->query( "SELECT t.blog_id, t.domain, t.path FROM {$this->tmp} AS t JOIN {$this->live} AS l ON l.blog_id = t.blog_id WHERE t.blog_id IN (" . implode( ',', $ids ) . ') AND BINARY t.path <> BINARY l.path ORDER BY t.blog_id' ) as $row ) {
			$moved[ (int) $row['blog_id'] ] = (string) $row['domain'] . (string) $row['path'];
		}

		return $moved;
	}

	/**
	 * Comparable address of a sites-table row (any table collation).
	 *
	 * @param string $alias Table alias.
	 */
	private static function key( string $alias ): string {
		return "CONCAT(LOWER(CONVERT({$alias}.domain USING utf8mb4)), '|', TRIM(TRAILING '/' FROM LOWER(CONVERT({$alias}.path USING utf8mb4)))) COLLATE utf8mb4_bin";
	}
}

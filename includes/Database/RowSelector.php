<?php
/**
 * Builds the SELECT for one batch of dump rows.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Keyset pagination over the primary key (same row order as offset paging, but
 * constant cost per batch); offset paging with ORDER BY 1 when there is no key.
 */
final class RowSelector {

	public function __construct( private Connection $db, private DumpOptions $options ) {
	}

	/**
	 * @param string $table Table.
	 * @return array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>}
	 */
	public function describe( string $table ): array {
		$quoted    = DumpFormat::quoteIdentifier( $table );
		$columns   = [];
		$generated = [];
		foreach ( $this->db->query( 'SHOW COLUMNS FROM ' . $quoted ) as $row ) {
			$columns[ (string) $row['Field'] ] = (string) $row['Type'];
			// MySQL: 'VIRTUAL GENERATED' / 'STORED GENERATED'; MariaDB: 'VIRTUAL GENERATED' / 'STORED GENERATED' (PERSISTENT is an alias).
			if ( 1 === preg_match( '/\b(?:VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', (string) ( $row['Extra'] ?? '' ) ) ) {
				$generated[] = (string) $row['Field'];
			}
		}
		$keys = [];
		foreach ( $this->db->query( 'SHOW KEYS FROM ' . $quoted . " WHERE `Key_name` = 'PRIMARY'" ) as $row ) {
			$keys[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		ksort( $keys );

		return [
			'columns'   => $columns,
			'keys'      => array_values( $keys ),
			'generated' => $generated,
		];
	}

	/**
	 * @param string                                                                                         $table     Table.
	 * @param array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>} $described From describe().
	 * @param array<int, string>|null                                                                        $lastKey   Key values of the last dumped row.
	 * @param int                                                                                            $offset    Rows already dumped (no-key tables).
	 * @param int                                                                                            $limit     Batch size.
	 * @return string
	 */
	public function batchSql( string $table, array $described, ?array $lastKey, int $offset, int $limit ): string {
		$quoted = DumpFormat::quoteIdentifier( $table );
		$where  = $this->where( $table );
		$keys   = $described['keys'];

		if ( [] === $keys ) {
			$select = $this->selectList( $table, $described, '' );

			return sprintf( 'SELECT %s FROM %s WHERE %s ORDER BY 1 LIMIT %d, %d', $select, $quoted, $where, $offset, $limit );
		}
		$order = implode( ', ', array_map( [ DumpFormat::class, 'quoteIdentifier' ], $keys ) );
		if ( $this->usesKeyset( $described ) ) {
			if ( null !== $lastKey ) {
				$where .= ' AND ' . $this->after( $keys, $lastKey );
			}
			$select = $this->selectList( $table, $described, '' );

			return sprintf( 'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d', $select, $quoted, $where, $order, $limit );
		}
		// Deferred join: offset paging over the key columns only, then fetch the rows.
		$select = $this->selectList( $table, $described, 't1.' );
		$outer  = implode( ', ', array_map( fn( string $k ): string => 't1.' . DumpFormat::quoteIdentifier( $k ), $keys ) );

		return sprintf(
			'SELECT %s FROM %s AS t1 JOIN (SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d, %d) AS t2 USING (%s) ORDER BY %s',
			$select,
			$quoted,
			$order,
			$quoted,
			$where,
			$order,
			$offset,
			$limit,
			$order,
			$outer
		);
	}

	/**
	 * Keyset paging is safe only when every key column compares the same way in
	 * SQL ordering and in a literal comparison (not FLOAT/DOUBLE, ENUM, SET ...).
	 *
	 * @param array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>} $described From describe().
	 * @return bool
	 */
	public function usesKeyset( array $described ): bool {
		if ( [] === $described['keys'] ) {
			return false;
		}
		if ( [] !== array_intersect( $described['keys'], $described['generated'] ) ) {
			return false; // The row would not carry the key value.
		}
		$safe = '/^(tinyint|smallint|mediumint|int|bigint|decimal|char|varchar|binary|varbinary|date|datetime|timestamp|time|year)\b/i';
		foreach ( $described['keys'] as $key ) {
			if ( ! isset( $described['columns'][ $key ] ) || 1 !== preg_match( $safe, $described['columns'][ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	private function where( string $table ): string {
		if ( null !== $this->options->tableWhere ) {
			return $this->options->tableWhere[ $table ] ?? '1'; // A network dump: ExportScope built every filter.
		}
		$p       = $this->options->basePrefix;
		$clauses = [];
		if ( $this->options->noSpamComments && $table === $p . 'comments' ) {
			$clauses[] = "`comment_approved` != 'spam'";
		}
		if ( $this->options->noSpamComments && $table === $p . 'commentmeta' ) {
			$clauses[] = '`comment_ID` IN ( SELECT `comment_ID` FROM ' . DumpFormat::quoteIdentifier( $p . 'comments' ) . " WHERE `comment_approved` != 'spam' )";
		}
		if ( $this->options->noPostRevisions && $table === $p . 'posts' ) {
			$clauses[] = "`post_type` != 'revision'";
		}
		if ( $this->options->noPostRevisions && $table === $p . 'postmeta' ) {
			$clauses[] = '`post_id` IN ( SELECT `ID` FROM ' . DumpFormat::quoteIdentifier( $p . 'posts' ) . " WHERE `post_type` != 'revision' )";
		}
		if ( $table === $p . 'options' && [] !== $this->options->excludedOptions ) {
			$names     = array_map( fn( string $n ): string => "'" . $this->db->escape( $n ) . "'", $this->options->excludedOptions );
			$clauses[] = '`option_name` NOT IN (' . implode( ',', $names ) . ')';
		}
		if ( $table === $p . 'options' ) {
			foreach ( $this->options->excludedOptionPrefixes as $prefix ) {
				$clauses[] = "`option_name` NOT LIKE '" . $this->db->escape( addcslashes( $prefix, '\\%_' ) ) . "%'";
			}
		}

		return [] === $clauses ? '1' : implode( ' AND ', $clauses );
	}

	/**
	 * '*' unless a column needs special handling: generated columns are left out,
	 * BIT is read as a number, and wp_options.option_value is neutralised.
	 *
	 * @param string                                                                                         $table     Table.
	 * @param array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>} $described From describe().
	 * @param string                                                                                         $alias     Column qualifier ('' or 't1.').
	 * @return string
	 */
	private function selectList( string $table, array $described, string $alias ): string {
		$columns = $described['columns'];
		$options = in_array( $table, $this->options->optionsTables(), true ) && isset( $columns['option_value'] );
		$special = $options || [] !== $described['generated'];
		foreach ( $columns as $type ) {
			$special = $special || 1 === preg_match( '/^bit\b/i', $type );
		}
		if ( ! $special ) {
			return '' === $alias ? '*' : $alias . '*';
		}
		$list = [];
		foreach ( $this->dumpedColumns( $described ) as $column ) {
			$quoted = $alias . DumpFormat::quoteIdentifier( $column );
			if ( $options && 'option_value' === $column ) {
				$list[] = "(CASE WHEN {$alias}`option_name` = 'active_plugins' THEN 'a:0:{}' WHEN ({$alias}`option_name` = 'template' OR {$alias}`option_name` = 'stylesheet') THEN '' ELSE {$alias}`option_value` END) AS `option_value`";
			} elseif ( 1 === preg_match( '/^bit\b/i', $columns[ $column ] ) ) {
				$list[] = "CAST({$quoted} AS UNSIGNED) AS " . DumpFormat::quoteIdentifier( $column );
			} else {
				$list[] = $quoted;
			}
		}

		return implode( ', ', $list );
	}

	/**
	 * Columns the dump writes, in table order (generated ones excluded).
	 *
	 * @param array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>} $described From describe().
	 * @return array<int, string>
	 */
	public function dumpedColumns( array $described ): array {
		return array_values( array_diff( array_keys( $described['columns'] ), $described['generated'] ) );
	}

	/**
	 * @param array<int, string> $keys    Key columns.
	 * @param array<int, string> $lastKey Last key values.
	 * @return string
	 */
	private function after( array $keys, array $lastKey ): string {
		$cols   = array_map( [ DumpFormat::class, 'quoteIdentifier' ], $keys );
		$values = array_map( fn( string $v ): string => "'" . $this->db->escape( $v ) . "'", array_values( $lastKey ) );
		$n      = count( $cols );
		// Lexicographic expansion, built from the last column outwards.
		$expr = $cols[ $n - 1 ] . ' > ' . $values[ $n - 1 ];
		for ( $i = $n - 2; $i >= 0; $i-- ) {
			$expr = $cols[ $i ] . ' > ' . $values[ $i ] . ' OR (' . $cols[ $i ] . ' = ' . $values[ $i ] . ' AND (' . $expr . '))';
		}
		if ( 1 === $n ) {
			return $expr;
		}

		// The leading-column range lets the optimizer seek instead of scanning the index.
		return $cols[0] . ' >= ' . $values[0] . ' AND (' . $expr . ')';
	}
}

<?php
/**
 * Lists the tables and views a dump includes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Prefix match is literal (LIKE with escaped wildcards), binary when table
 * names are case-sensitive on the server.
 */
final class TableLister {

	public function __construct( private Connection $db ) {
	}

	/**
	 * @param array<int, string> $prefixes Table name prefixes; empty = all tables.
	 * @return array{tables: array<int, string>, views: array<int, string>}
	 */
	public function list( array $prefixes ): array {
		return [
			'tables' => $this->names( 'BASE TABLE', $prefixes ),
			'views'  => $this->names( 'VIEW', $prefixes ),
		];
	}

	/**
	 * @param array<int, string> $tables  Listed tables.
	 * @param array<int, string> $exclude Tables to drop from the list.
	 * @param array<int, string> $include Tables to add.
	 * @return array<int, string>
	 */
	public static function select( array $tables, array $exclude, array $include ): array {
		$selected = array_values( array_diff( $tables, $exclude ) );
		foreach ( $include as $table ) {
			if ( ! in_array( $table, $selected, true ) ) {
				$selected[] = $table;
			}
		}

		return $selected;
	}

	/**
	 * @param string             $type     BASE TABLE or VIEW.
	 * @param array<int, string> $prefixes Prefixes.
	 * @return array<int, string>
	 */
	private function names( string $type, array $prefixes ): array {
		$column    = 'Tables_in_' . $this->db->databaseName();
		$sensitive = '0' === (string) $this->db->variable( 'lower_case_table_names' );
		$filters   = [];
		foreach ( $prefixes as $prefix ) {
			$escaped   = $this->db->escape( addcslashes( $prefix, '\\%_' ) );
			$col       = DumpFormat::quoteIdentifier( $column );
			$filters[] = $sensitive ? "CAST({$col} AS BINARY) LIKE CAST('{$escaped}%' AS BINARY)" : "{$col} LIKE '{$escaped}%'";
		}
		$sql   = sprintf(
			"SHOW FULL TABLES FROM %s WHERE `Table_type` = '%s'%s",
			DumpFormat::quoteIdentifier( $this->db->databaseName() ),
			$type,
			[] === $filters ? '' : ' AND (' . implode( ' OR ', $filters ) . ')'
		);
		$names = [];
		foreach ( $this->db->query( $sql ) as $row ) {
			$names[] = (string) $row[ $column ];
		}

		return $names;
	}
}

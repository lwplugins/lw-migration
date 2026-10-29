<?php
/**
 * Every stored row of a site's roles option, in any letter case or padding.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\QueryException;

defined( 'ABSPATH' ) || exit;

/**
 * Review N3/N4: an archive's options table need not keep option names
 * unique and chooses its own collation, and WordPress keeps the last of
 * several rows when it loads the options, so every row whose name could
 * equal the option's (table collation, exact bytes, or lower-cased and
 * trimmed) is read, and a replace removes all of them before it writes one
 * exact, autoloaded row.
 */
final class RoleOptions {

	/**
	 * @param Connection $db    Database.
	 * @param string     $table Options table, quoted.
	 */
	public function __construct( private Connection $db, private string $table ) {
	}

	/**
	 * @param string $name Option name.
	 * @return array<int, string> The value of every matching row.
	 * @throws QueryException When the table cannot be read.
	 */
	public function rows( string $name ): array {
		return array_map( static fn( array $row ): string => (string) $row['option_value'], $this->db->query( "SELECT option_value FROM {$this->table} WHERE " . $this->match( $name ) ) );
	}

	/**
	 * Removes every matching row and stores one, in one transaction.
	 *
	 * @param string $name  Option name.
	 * @param string $value Serialized value.
	 * @throws QueryException On a database error (rolled back).
	 */
	public function replace( string $name, string $value ): void {
		$this->db->execute( 'START TRANSACTION' );
		try {
			$this->db->execute( "DELETE FROM {$this->table} WHERE " . $this->match( $name ) );
			$this->db->execute( "INSERT INTO {$this->table} (option_name, option_value, autoload) VALUES ('" . $this->db->escape( $name ) . "', '" . $this->db->escape( $value ) . "', 'yes')" );
		} catch ( QueryException $e ) {
			$this->db->execute( 'ROLLBACK' );
			throw $e;
		}
		$this->db->execute( 'COMMIT' );
	}

	/**
	 * Review N4: a superset of how WordPress and the table could equal the
	 * name — the table's own collation (the archive chose it), the exact
	 * bytes, and lower-cased and trimmed in utf8mb4.
	 *
	 * @param string $name Option name.
	 */
	private function match( string $name ): string {
		$quoted = "'" . $this->db->escape( $name ) . "'";

		return "(option_name = {$quoted} OR CAST(option_name AS BINARY) = CAST({$quoted} AS BINARY) OR LOWER(TRIM(CONVERT(option_name USING utf8mb4))) = LOWER(TRIM(CONVERT({$quoted} USING utf8mb4))))";
	}
}

<?php
/**
 * Points the importing admin's incoming links at its id after the import.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Link\LinkRow;
use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Runs in the swap step, after this site's own rows were copied into the
 * temporary options and before the RENAME: PreserveStep kept the importing
 * admin by login, usually under another id, so every incoming link that
 * admin made (its live id and login, exactly) is pointed at that id (ruling I3, fix round 1 of Task 5). The
 * link then still acts for its admin on the first request after the swap,
 * and a pushed import can be driven to its end. Only the temporary options
 * table is written (the exact row, its autoload untouched); a replay writes
 * the same value again.
 */
final class TmpLinkOwners {

	/**
	 * @param Connection $db  Site database.
	 * @param string     $tmp Temporary prefix (lwtXXXX_).
	 * @throws DatabaseException For any other prefix.
	 */
	public function __construct( private Connection $db, private string $tmp ) {
		if ( ! PrefixRules::isValidTempPrefix( $tmp ) ) {
			throw new DatabaseException( 'Invalid temporary table prefix.' );
		}
	}

	/**
	 * @param int    $from  The kept admin's live id (before the import).
	 * @param string $login The kept admin's login.
	 * @param int    $id    Its id in the temporary users table.
	 * @return bool Whether a link was pointed at it.
	 */
	public function move( int $from, string $login, int $id ): bool {
		$table = DumpFormat::quoteIdentifier( $this->tmp . 'options' );
		$name  = "'" . $this->db->escape( Options::LINKS ) . "'";
		$rows  = $this->db->query( "SELECT option_value FROM {$table} WHERE option_name = {$name}" );
		$value = 1 === count( $rows ) ? LinkRow::reowned( (string) $rows[0]['option_value'], $from, $login, $id ) : null;
		if ( null === $value ) {
			return false;
		}
		$this->db->execute( "UPDATE {$table} SET option_value = '" . $this->db->escape( $value ) . "' WHERE option_name = {$name}" );

		return true;
	}
}

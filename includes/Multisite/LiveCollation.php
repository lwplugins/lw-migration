<?php
/**
 * How the live users table compares its text columns.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Staged values are converted into the character set and collation of the
 * live column before they are compared (read once from SHOW FULL COLUMNS), so
 * a login matches the way the network itself compares logins, whatever the
 * archive's tables use. A collation that is not a plain name is not used
 * (plain comparison).
 */
final class LiveCollation {

	/** @var array<string, array{0: string, 1: string}|null>|null Column => [charset, collation]. */
	private ?array $forms = null;

	/**
	 * @param Connection $db    Database.
	 * @param string     $users The live users table, quoted.
	 */
	public function __construct( private Connection $db, private string $users ) {
	}

	/**
	 * Login match of a live and a staged users alias.
	 *
	 * @param string $live   Alias of the live users table.
	 * @param string $staged Alias of the staged users table.
	 */
	public function login( string $live, string $staged ): string {
		return "{$live}.user_login = " . $this->form( 'user_login', $staged . '.user_login' );
	}

	/**
	 * A staged value in the character set and collation of a live users column.
	 *
	 * @param string $column Live users column.
	 * @param string $expr   Staged expression.
	 */
	public function form( string $column, string $expr ): string {
		if ( null === $this->forms ) {
			$this->forms = [];
			foreach ( $this->db->query( 'SHOW FULL COLUMNS FROM ' . $this->users ) as $row ) {
				$collation = (string) ( $row['Collation'] ?? '' );
				$this->forms[ strtolower( (string) ( $row['Field'] ?? '' ) ) ] = 1 === preg_match( '/^([a-z0-9]+)_[a-z0-9_]+\z/', $collation, $m ) ? [ $m[1], $collation ] : null;
			}
		}
		$form = $this->forms[ $column ] ?? null;

		return null === $form ? $expr : "CONVERT({$expr} USING {$form[0]}) COLLATE {$form[1]}";
	}
}

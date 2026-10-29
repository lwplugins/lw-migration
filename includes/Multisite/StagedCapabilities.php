<?php
/**
 * Checks the archive's user roles on the restored sites before they are copied.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\QueryException;

defined( 'ABSPATH' ) || exit;

/**
 * Review N1, after the swap and before the user meta is copied: a staged
 * <base><id>_capabilities value (any letter case) of a restored site is kept
 * only when it names roles of that site's (cleaned, live) roles, each
 * granted; any other value is replaced by an empty role list in the staging
 * table. A repeat finds nothing more to replace.
 */
final class StagedCapabilities {

	/**
	 * @param Connection $db       Database.
	 * @param string     $base     Base table prefix (validated by the caller).
	 * @param string     $usermeta The staged usermeta table, quoted.
	 * @param string     $users    The staged users table, quoted.
	 */
	public function __construct( private Connection $db, private string $base, private string $usermeta, private string $users ) {
	}

	/**
	 * @param array<int, int> $newIds Restored site ids.
	 * @return array<int, string> Staged logins whose role on a restored site was refused.
	 * @throws QueryException On a database error.
	 */
	public function sanitize( array $newIds ): array {
		$refused = [];
		foreach ( $newIds as $id ) {
			$roles = $this->roles( (int) $id );
			$key   = "'" . $this->db->escape( strtolower( $this->base . (int) $id . '_capabilities' ) ) . "'";
			$exact = $this->base . (int) $id . '_';
			$rows  = $this->db->query( "SELECT m.umeta_id AS i, m.meta_key AS k, m.meta_value AS v, s.user_login AS l FROM {$this->usermeta} AS m LEFT JOIN {$this->users} AS s ON s.ID = m.user_id WHERE LOWER(m.meta_key) = {$key} ORDER BY m.umeta_id" );
			foreach ( $rows as $row ) {
				if ( 'a:0:{}' === (string) $row['v'] || SiteRoles::grantsOnly( (string) $row['v'], $roles ) ) {
					continue;
				}
				$this->db->execute( "UPDATE {$this->usermeta} SET meta_value = 'a:0:{}' WHERE umeta_id = " . (int) $row['i'] );
				if ( str_starts_with( (string) $row['k'], $exact ) ) {
					$refused[] = (string) $row['l']; // Only keys insertUserMeta() copies are named (review m1).
				}
			}
		}

		return array_values( array_unique( $refused ) );
	}

	/**
	 * @param int $id Restored site id.
	 * @return array<int, string> Role names of its live (restored, cleaned) roles; several rows or none count as the default roles.
	 */
	private function roles( int $id ): array {
		$prefix = $this->base . $id . '_';
		$rows   = ( new RoleOptions( $this->db, DumpFormat::quoteIdentifier( $prefix . 'options' ) ) )->rows( $prefix . 'user_roles' );

		return SiteRoles::names( SiteRoles::fromRows( $rows )[0] );
	}
}

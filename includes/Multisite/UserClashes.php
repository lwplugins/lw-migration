<?php
/**
 * Archive users the network would confuse with its own.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Read before the user insert, for the warnings of the merge: users the
 * merge will add that share an e-mail address or profile slug with an
 * existing user, and archive logins the live collation takes as an existing
 * login that is not the same bytes (case, accents, look-alike letters).
 */
final class UserClashes {

	/**
	 * @param Connection    $db     Database.
	 * @param LiveCollation $users  How the live users table compares.
	 * @param string        $staged The staged users table, quoted.
	 * @param string        $live   The live users table, quoted.
	 */
	public function __construct( private Connection $db, private LiveCollation $users, private string $staged, private string $live ) {
	}

	/**
	 * @param string $column user_email or user_nicename.
	 * @return array<string, string> New login => existing login with the same value.
	 */
	public function newUsersSharing( string $column ): array {
		$c = 'user_email' === $column ? 'user_email' : 'user_nicename';

		return $this->pairs(
			"SELECT s.user_login AS n, e.user_login AS o FROM {$this->staged} AS s LEFT JOIN {$this->live} AS u ON " . $this->users->login( 'u', 's' )
			. " JOIN {$this->live} AS e ON e.{$c} = " . $this->users->form( $c, 's.' . $c ) . " WHERE u.ID IS NULL AND s.{$c} <> '' ORDER BY s.ID, e.ID"
		);
	}

	/**
	 * @return array<string, string> Archive login => the existing login it was taken as.
	 */
	public function loginMatches(): array {
		return $this->pairs(
			"SELECT s.user_login AS n, u.user_login AS o FROM {$this->staged} AS s JOIN {$this->live} AS u ON " . $this->users->login( 'u', 's' )
			. ' WHERE CAST(s.user_login AS BINARY) <> CAST(u.user_login AS BINARY) ORDER BY s.ID, u.ID'
		);
	}

	/**
	 * @param string $sql SELECT of n, o.
	 * @return array<string, string> First o per n.
	 */
	private function pairs( string $sql ): array {
		$pairs = [];
		foreach ( $this->db->query( $sql ) as $row ) {
			$pairs[ (string) $row['n'] ] ??= (string) $row['o'];
		}

		return $pairs;
	}
}

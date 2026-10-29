<?php
/**
 * Writes into the temporary tables before the swap.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Multisite\LeftOutSites;
use LightweightPlugins\Migration\Multisite\RoleOptions;

defined( 'ABSPATH' ) || exit;

/**
 * Live tables (<base>…) are only read; every write goes to <tmp>…, so an
 * import that stops before the swap leaves the site as it was. The temporary
 * prefix must have the lwtXXXX_ shape and the live prefix may not overlap it,
 * so no write can address a live table. Values are stored verbatim (callers
 * pass serialized strings) and always escaped. Each method runs its writes in
 * one transaction and replaces by key, so it is safe to run again after a crash.
 * On a network the same rules hold for a subsite's options table (a suffix
 * such as "7_options") and the network's sitemeta.
 */
final class TmpTables {

	private const USER_COLUMNS = [ 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' ];

	private const IDENTIFIER = '/^[A-Za-z0-9_]+\z/';

	/**
	 * @param Connection $db   Connection to the site database.
	 * @param string     $tmp  Temporary prefix (lwtXXXX_), the only one written to.
	 * @param string     $base Live site prefix, only read.
	 * @throws DatabaseException When a prefix could address a live table.
	 */
	public function __construct( private Connection $db, private string $tmp, private string $base ) {
		if ( ! PrefixRules::isValidTempPrefix( $tmp ) ) {
			throw new DatabaseException( 'Invalid temporary table prefix.' );
		}
		if ( 1 !== preg_match( self::IDENTIFIER, $base ) || PrefixRules::isValidTempPrefix( $base ) || str_starts_with( $base, $tmp ) ) {
			throw new DatabaseException( 'Invalid site table prefix for a temporary-table write.' );
		}
	}

	public function has( string $suffix ): bool {
		$like = $this->db->escape( addcslashes( $this->tmp . self::suffix( $suffix ), '\\%_' ) );

		return [] !== $this->db->query( "SHOW TABLES LIKE '{$like}'" );
	}

	/**
	 * @param array<int, string> $names Option names to carry over from the live site.
	 * @param string             $table Options table suffix on both sides (a subsite's: "<id>_options").
	 */
	public function copyOptions( array $names, string $table = 'options' ): void {
		if ( [] === $names ) {
			return;
		}
		$this->replaceOptions( 'option_name IN (' . implode( ',', array_map( [ $this, 'quote' ], $names ) ) . ')', $table );
	}

	/**
	 * @param array<int, string> $prefixes Option name prefixes to carry over (matched literally).
	 * @param array<int, string> $except   Names under them that are not carried (an archive's copy goes too).
	 */
	public function copyOptionPrefixes( array $prefixes, array $except = [] ): void {
		if ( [] === $prefixes ) {
			return;
		}
		$like = array_map( fn( string $p ): string => 'option_name LIKE ' . $this->quote( addcslashes( $p, '\\%_' ) . '%' ), $prefixes );
		$skip = [] === $except ? '' : ' AND option_name NOT IN (' . implode( ',', array_map( [ $this, 'quote' ], $except ) ) . ')';
		$this->replaceOptions( '(' . implode( ' OR ', $like ) . ')', 'options', $skip );
	}

	/**
	 * Every row of a temporary options table's option in any letter case (review N3).
	 *
	 * @param string $suffix Options table suffix (a subsite's: "<id>_options").
	 */
	public function roleOptions( string $suffix ): RoleOptions {
		return new RoleOptions( $this->db, $this->tmpTable( $suffix ) );
	}

	/**
	 * @param string $name   Option name.
	 * @param string $value  Stored as given (already serialized by the caller).
	 * @param string $suffix Options table suffix (a subsite's: "<id>_options").
	 */
	public function setOption( string $name, string $value, string $suffix = 'options' ): void {
		$table = $this->tmpTable( $suffix );
		$this->transaction(
			function () use ( $table, $name, $value ): void {
				$this->db->execute( "DELETE FROM {$table} WHERE option_name = " . $this->quote( $name ) );
				$this->db->execute( "INSERT INTO {$table} (option_name, option_value, autoload) VALUES (" . $this->quote( $name ) . ', ' . $this->quote( $value ) . ", 'yes')" );
			}
		);
	}

	/**
	 * @param int                $networkId site_id.
	 * @param array<int, string> $keys      Live sitemeta keys to carry over (a key the live network lacks ends up missing).
	 */
	public function copySiteMeta( int $networkId, array $keys ): void {
		if ( [] === $keys ) {
			return;
		}
		$where = 'site_id = ' . $networkId . ' AND meta_key IN (' . implode( ',', array_map( [ $this, 'quote' ], $keys ) ) . ')';
		$tmp   = $this->tmpTable( 'sitemeta' );
		$live  = $this->liveTable( 'sitemeta' );
		$this->transaction(
			function () use ( $tmp, $live, $where ): void {
				$this->db->execute( "DELETE FROM {$tmp} WHERE {$where}" );
				$this->db->execute( "INSERT INTO {$tmp} (site_id, meta_key, meta_value) SELECT site_id, meta_key, meta_value FROM {$live} WHERE {$where}" );
			}
		);
	}

	/**
	 * @param int    $networkId site_id.
	 * @param string $key       Meta key.
	 * @return string|null The value in the temporary sitemeta table.
	 */
	public function siteMeta( int $networkId, string $key ): ?string {
		$rows = $this->db->query( 'SELECT meta_value FROM ' . $this->tmpTable( 'sitemeta' ) . ' WHERE site_id = ' . $networkId . ' AND meta_key = ' . $this->quote( $key ) . ' ORDER BY meta_id LIMIT 1' );

		return [] === $rows ? null : (string) $rows[0]['meta_value'];
	}

	/**
	 * @param int    $networkId site_id.
	 * @param string $key       Meta key.
	 * @param string $value     Stored as given (already serialized by the caller).
	 */
	public function setSiteMeta( int $networkId, string $key, string $value ): void {
		$table = $this->tmpTable( 'sitemeta' );
		$this->transaction(
			function () use ( $table, $networkId, $key, $value ): void {
				$this->db->execute( "DELETE FROM {$table} WHERE site_id = {$networkId} AND meta_key = " . $this->quote( $key ) );
				$this->db->execute( "INSERT INTO {$table} (site_id, meta_key, meta_value) VALUES ({$networkId}, " . $this->quote( $key ) . ', ' . $this->quote( $value ) . ')' );
			}
		);
	}

	/**
	 * @return int|null The id of the archive's one network (temporary site table), null unless there is exactly one.
	 */
	public function networkId(): ?int {
		$rows = $this->db->query( 'SELECT id FROM ' . $this->tmpTable( 'site' ) . ' LIMIT 2' );

		return 1 === count( $rows ) ? (int) $rows[0]['id'] : null;
	}

	/**
	 * Puts the archive's network under this network's id: its site row, its
	 * sites and its network meta (rows already under the new id are the
	 * archive's leftovers and give way). One transaction; once moved, a replay
	 * finds no row under the old id and changes nothing.
	 *
	 * @param int $from The archive's network id.
	 * @param int $to   This network's id.
	 */
	public function moveNetwork( int $from, int $to ): void {
		$site = $this->tmpTable( 'site' );
		if ( $from === $to || [] === $this->db->query( "SELECT id FROM {$site} WHERE id = {$from} LIMIT 1" ) ) {
			return;
		}
		$blogs = $this->tmpTable( 'blogs' );
		$meta  = $this->tmpTable( 'sitemeta' );
		$this->transaction(
			function () use ( $site, $blogs, $meta, $from, $to ): void {
				$this->db->execute( "DELETE FROM {$site} WHERE id = {$to}" );
				$this->db->execute( "UPDATE {$site} SET id = {$to} WHERE id = {$from}" );
				$this->db->execute( "UPDATE {$blogs} SET site_id = {$to} WHERE site_id = {$from}" );
				$this->db->execute( "DELETE FROM {$meta} WHERE site_id = {$to}" );
				$this->db->execute( "UPDATE {$meta} SET site_id = {$to} WHERE site_id = {$from}" );
			}
		);
	}

	/**
	 * The live sites a network archive leaves out (final review I2/I3).
	 */
	public function leftOutSites(): LeftOutSites {
		return new LeftOutSites( $this->db, $this->tmpTable( 'blogs' ), $this->liveTable( 'blogs' ) );
	}

	/**
	 * Ruling R16: rows added after the swap get ids above every id the live
	 * table holds or has handed out and every id of the archive, so a new site
	 * never takes an id whose tables or files are still there.
	 *
	 * @param string $suffix Table name without prefix.
	 * @param string $column Auto-increment column.
	 */
	public function raiseAutoIncrement( string $suffix, string $column ): void {
		$tmp  = $this->tmpTable( $suffix );
		$live = $this->liveTable( $suffix );
		$col  = DumpFormat::quoteIdentifier( self::suffix( $column ) );
		$rows = $this->db->query( "SELECT GREATEST( COALESCE( ( SELECT MAX({$col}) FROM {$live} ), 0 ), COALESCE( ( SELECT MAX({$col}) FROM {$tmp} ), 0 ) ) + 1 AS next_id" );
		$next = max( (int) ( $rows[0]['next_id'] ?? 1 ), $this->autoIncrement( $live ) );
		$this->db->execute( "ALTER TABLE {$tmp} AUTO_INCREMENT = {$next}" );
	}

	/**
	 * The live admin keeps password, session and role: a user with the same
	 * login in the archive is updated, otherwise the admin is added.
	 *
	 * @param int $userId Live user ID.
	 * @return int|null The admin's ID in the temporary users table, null when the live user is missing.
	 * @throws DatabaseException When the added user cannot be found again.
	 */
	public function keepAdmin( int $userId ): ?int {
		$live = $this->db->query( 'SELECT user_login, user_pass FROM ' . $this->liveTable( 'users' ) . ' WHERE ID = ' . $userId );
		if ( [] === $live ) {
			return null;
		}
		$login = (string) $live[0]['user_login'];
		$pass  = (string) $live[0]['user_pass'];

		return (int) $this->transaction(
			function () use ( $userId, $login, $pass ): int {
				$id = $this->tmpUserId( $login );
				if ( null === $id ) {
					return $this->addAdmin( $userId, $login );
				}
				$this->db->execute( 'UPDATE ' . $this->tmpTable( 'users' ) . ' SET user_pass = ' . $this->quote( $pass ) . ' WHERE ID = ' . $id );
				$this->copyMeta( $userId, $id, [ 'session_tokens', $this->base . 'capabilities', $this->base . 'user_level' ] );
				$this->copyMissingMeta( $userId, $id );

				return $id;
			}
		);
	}

	/**
	 * Other temporary users whose e-mail or nicename equals the admin's (by the
	 * table's collation, like WordPress' own lookups); empty values never clash.
	 *
	 * @param int $tmpId The admin's ID in the temporary users table.
	 * @return array<string, array<int, string>> Login => clashing columns (user_email, user_nicename).
	 */
	public function sharedIdentity( int $tmpId ): array {
		$users = $this->tmpTable( 'users' );
		$rows  = $this->db->query( "SELECT o.user_login, o.user_email = a.user_email AND a.user_email <> '' AS email, o.user_nicename = a.user_nicename AND a.user_nicename <> '' AS nicename FROM {$users} AS a JOIN {$users} AS o ON o.ID <> a.ID AND ( ( a.user_email <> '' AND o.user_email = a.user_email ) OR ( a.user_nicename <> '' AND o.user_nicename = a.user_nicename ) ) WHERE a.ID = {$tmpId} ORDER BY o.ID" );
		$out   = [];
		foreach ( $rows as $row ) {
			$columns = [];
			if ( ! empty( $row['email'] ) ) {
				$columns[] = 'user_email';
			}
			if ( ! empty( $row['nicename'] ) ) {
				$columns[] = 'user_nicename';
			}
			$out[ (string) $row['user_login'] ] = $columns;
		}

		return $out;
	}

	private function addAdmin( int $userId, string $login ): int {
		$columns = implode( ', ', self::USER_COLUMNS );
		$this->db->execute( 'INSERT INTO ' . $this->tmpTable( 'users' ) . " ({$columns}) SELECT {$columns} FROM " . $this->liveTable( 'users' ) . ' WHERE ID = ' . $userId );
		$id = $this->tmpUserId( $login );
		if ( null === $id ) {
			throw new DatabaseException( 'The administrator could not be added to the temporary users table.' );
		}
		$this->copyMeta( $userId, $id, null );

		return $id;
	}

	/**
	 * The counter as the server reports it now (SHOW CREATE TABLE is never a cached statistic).
	 *
	 * @param string $table Quoted table name.
	 */
	private function autoIncrement( string $table ): int {
		$create = (string) ( array_values( $this->db->query( "SHOW CREATE TABLE {$table}" )[0] ?? [] )[1] ?? '' );

		return 1 === preg_match( '/\bAUTO_INCREMENT=(\d+)/', $create, $m ) ? (int) $m[1] : 0;
	}

	private function replaceOptions( string $where, string $table = 'options', string $skip = '' ): void {
		$tmp  = $this->tmpTable( $table );
		$live = $this->liveTable( $table );
		$this->transaction(
			function () use ( $tmp, $live, $where, $skip ): void {
				$this->db->execute( "DELETE FROM {$tmp} WHERE {$where}" );
				$this->db->execute( "INSERT INTO {$tmp} (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM {$live} WHERE {$where}{$skip}" );
			}
		);
	}

	/**
	 * @param int                     $from Live user ID.
	 * @param int                     $to   Temporary user ID.
	 * @param array<int, string>|null $keys Meta keys, or null for all.
	 * @return void
	 */
	private function copyMeta( int $from, int $to, ?array $keys ): void {
		$only = null === $keys ? '' : ' AND meta_key IN (' . implode( ',', array_map( [ $this, 'quote' ], $keys ) ) . ')';
		$this->db->execute( 'DELETE FROM ' . $this->tmpTable( 'usermeta' ) . ' WHERE user_id = ' . $to . $only );
		$this->db->execute( 'INSERT INTO ' . $this->tmpTable( 'usermeta' ) . " (user_id, meta_key, meta_value) SELECT {$to}, meta_key, meta_value FROM " . $this->liveTable( 'usermeta' ) . ' WHERE user_id = ' . $from . $only );
	}

	/**
	 * Adds every live meta key the temporary user lacks (archive values win), so a
	 * replay after a partial add on a non-transactional table recovers the rest.
	 * Keys are compared byte for byte: the archive's table may use another
	 * character set than the live one (utf8 vs utf8mb4), and mixing their
	 * collations is an error.
	 *
	 * @param int $from Live user ID.
	 * @param int $to   Temporary user ID.
	 * @return void
	 */
	private function copyMissingMeta( int $from, int $to ): void {
		$tmp = $this->tmpTable( 'usermeta' );
		$this->db->execute( "INSERT INTO {$tmp} (user_id, meta_key, meta_value) SELECT {$to}, l.meta_key, l.meta_value FROM " . $this->liveTable( 'usermeta' ) . " AS l WHERE l.user_id = {$from} AND NOT EXISTS (SELECT 1 FROM {$tmp} AS t WHERE t.user_id = {$to} AND BINARY t.meta_key <=> BINARY l.meta_key)" );
	}

	private function tmpUserId( string $login ): ?int {
		$rows = $this->db->query( 'SELECT ID FROM ' . $this->tmpTable( 'users' ) . ' WHERE user_login = ' . $this->quote( $login ) . ' ORDER BY ID LIMIT 1' );

		return [] === $rows ? null : (int) $rows[0]['ID'];
	}

	/**
	 * Runs the writes in one transaction; on any error rolls back and rethrows.
	 *
	 * @param \Closure $writes Statements to run.
	 * @throws \Throwable Whatever the writes threw, after the rollback.
	 * @return mixed What the closure returns.
	 */
	private function transaction( \Closure $writes ) {
		$this->db->execute( 'START TRANSACTION' );
		try {
			$result = $writes();
		} catch ( \Throwable $e ) {
			try {
				$this->db->execute( 'ROLLBACK' );
			} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the original error is the one to report.
				unset( $ignored );
			}
			throw $e;
		}
		$this->db->execute( 'COMMIT' );

		return $result;
	}

	/**
	 * The only table names writes use: always under the validated temporary prefix.
	 *
	 * @param string $suffix Table name without prefix.
	 * @return string Quoted identifier.
	 */
	private function tmpTable( string $suffix ): string {
		return DumpFormat::quoteIdentifier( $this->tmp . self::suffix( $suffix ) );
	}

	/**
	 * Live tables appear only in SELECT positions.
	 *
	 * @param string $suffix Table name without prefix.
	 * @return string Quoted identifier.
	 */
	private function liveTable( string $suffix ): string {
		return DumpFormat::quoteIdentifier( $this->base . self::suffix( $suffix ) );
	}

	private static function suffix( string $suffix ): string {
		if ( 1 !== preg_match( self::IDENTIFIER, $suffix ) ) {
			throw new DatabaseException( 'Invalid table name suffix.' );
		}

		return $suffix;
	}

	private function quote( string $value ): string {
		return "'" . $this->db->escape( $value ) . "'";
	}
}

<?php
/**
 * Merges a sites or single-site archive's users into the network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\QueryException;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 19, after the swap. The archive's users and user meta wait in the
 * held staging tables <tmp>lwmsrc_users|usermeta (its network meta, staged
 * too, is never copied).
 * Users are matched by login the way the live users table compares logins
 * (its column collation); a missing login is added, an existing user's row
 * never changes. The restored sites' user ids are renumbered in two phases
 * that are safe to repeat statement by statement: shift (every id up to the
 * largest staged one moves above OFFSET), remap (a shifted staged id becomes
 * the live id of its login), unshift (what was shifted but not remapped moves
 * back). The caller never runs shift again once remap started. User meta is
 * an allowlist (review C1/C2): keys of the restored sites' new ids for any
 * matched user, and a fixed list of harmless profile keys for users this
 * merge added; nothing else of the archive (no main-site or network role, no
 * application passwords, no session) and no network meta ever reaches the
 * live tables. Meta keys are compared byte for byte, so tables in another
 * character set or collation work together. Not final: the step tests use a
 * stand-in.
 */
class UserMerge {

	/** 2^30: shifted ids sit above it, every real id below it. */
	public const OFFSET = 1073741824;

	private const USER_COLUMNS = [ 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' ];

	/** Profile keys a user added by this merge may take from the archive (exact names). */
	public const PROFILE_KEYS = [ 'nickname', 'first_name', 'last_name', 'description', 'rich_editing', 'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale', 'primary_blog', 'source_domain' ];

	/** @var array<int, string>|null Held staging tables of the run. */
	private ?array $held = null;

	/**
	 * Login and text comparisons the way the live users table makes them.
	 *
	 * @var LiveCollation
	 */
	private LiveCollation $users;

	/**
	 * @param Connection $db   Database.
	 * @param string     $base Base table prefix of the network.
	 * @param string     $tmp  Temporary prefix of the run (lwtXXXX_).
	 * @throws DatabaseException When a prefix has the wrong shape.
	 */
	public function __construct( private Connection $db, private string $base, private string $tmp ) {
		if ( ! PrefixRules::isValidTempPrefix( $tmp ) || 1 !== preg_match( '/^[A-Za-z0-9_]+\z/', $base ) ) {
			throw new DatabaseException( 'Invalid table prefix for the user merge.' );
		}
		$this->users = new LiveCollation( $db, $this->live( 'users' ) );
	}

	/** Whether any held staging table of the run exists. */
	public function hasStaging(): bool {
		return [] !== $this->held();
	}

	/**
	 * Adds every staged user whose login the network does not have (the first
	 * of logins the live column sees as one), without a pending activation key.
	 */
	public function insertUsers(): void {
		if ( ! $this->has( 'users' ) ) {
			return;
		}
		$staged = $this->staged( 'users' );
		$this->db->execute(
			'INSERT INTO ' . $this->live( 'users' ) . ' (' . implode( ', ', self::USER_COLUMNS ) . ') SELECT ' . implode( ', ', array_map( static fn( string $c ): string => 'user_activation_key' === $c ? "''" : 's.' . $c, self::USER_COLUMNS ) )
			. " FROM {$staged} AS s LEFT JOIN " . $this->live( 'users' ) . ' AS u ON ' . $this->users->login( 'u', 's' )
			. ' WHERE u.ID IS NULL AND ' . $this->firstOfLogin( 's' ) . ' ORDER BY s.ID'
		);
	}

	/**
	 * Users insertUsers() will add whose e-mail address an existing user has (call it before insertUsers()).
	 *
	 * @return array<string, string> New login => existing login.
	 */
	public function emailClashes(): array {
		return $this->has( 'users' ) ? $this->clashes()->newUsersSharing( 'user_email' ) : [];
	}

	/**
	 * Users insertUsers() will add whose profile slug an existing user has (call it before insertUsers()).
	 *
	 * @return array<string, string> New login => existing login.
	 */
	public function nicenameClashes(): array {
		return $this->has( 'users' ) ? $this->clashes()->newUsersSharing( 'user_nicename' ) : [];
	}

	/**
	 * Archive logins taken as an existing user although they are not the same bytes.
	 *
	 * @return array<string, string> Archive login => existing login.
	 */
	public function loginMatches(): array {
		return $this->has( 'users' ) ? $this->clashes()->loginMatches() : [];
	}

	/**
	 * Whether a column already holds a value above OFFSET (it cannot be renumbered safely).
	 *
	 * @param string $table  Table.
	 * @param string $column User-id column.
	 */
	public function holdsShifted( string $table, string $column ): bool {
		$c = DumpFormat::quoteIdentifier( $column );

		return [] !== $this->db->query( 'SELECT 1 AS x FROM ' . DumpFormat::quoteIdentifier( $table ) . " WHERE {$c} > " . self::OFFSET . ' LIMIT 1' );
	}

	/**
	 * The renumbering targets: user-id columns (RenumberColumns) of the mapped
	 * sites' tables that the swap brought in (ruling R5) and whose type holds
	 * the offset. Never a table of the base prefix itself (the main site).
	 *
	 * @param array<int, int>    $newIds  Site ids of the restored sites.
	 * @param array<int, string> $swapped Final table names the swap brought in.
	 * @return array<int, array{0: string, 1: string}> Table, column.
	 */
	public function targets( array $newIds, array $swapped ): array {
		$swapped = array_flip( array_filter( $swapped, 'is_string' ) );
		$wanted  = [];
		foreach ( $newIds as $id ) {
			foreach ( $id > 1 ? RenumberColumns::LIST : [] as [ $suffix, $column ] ) {
				$table = $this->base . $id . '_' . $suffix;
				if ( isset( $swapped[ $table ] ) ) {
					$wanted[ $table ][] = $column;
				}
			}
		}
		$types   = [] === $wanted ? [] : $this->columnTypes( array_keys( $wanted ) );
		$targets = [];
		foreach ( $wanted as $table => $columns ) {
			foreach ( $columns as $column ) {
				$found = $types[ $table ][ $column ] ?? null;
				if ( null !== $found && RenumberColumns::fits( $found[1] ) ) {
					$targets[] = [ $table, $found[0] ];
				}
			}
		}

		return $targets;
	}

	/** The largest staged user id (0 without staged users). */
	public function maxStagedId(): int {
		if ( ! $this->has( 'users' ) ) {
			return 0;
		}

		return (int) ( $this->db->query( 'SELECT MAX(ID) AS m FROM ' . $this->staged( 'users' ) )[0]['m'] ?? 0 );
	}

	/**
	 * Phase 1: every id from 1 to $max moves above OFFSET (a repeat finds none left).
	 *
	 * @param string $table  Table.
	 * @param string $column User-id column.
	 * @param int    $max    maxStagedId().
	 */
	public function shift( string $table, string $column, int $max ): void {
		$c = DumpFormat::quoteIdentifier( $column );
		$this->db->execute( 'UPDATE ' . DumpFormat::quoteIdentifier( $table ) . " SET {$c} = {$c} + " . self::OFFSET . " WHERE {$c} BETWEEN 1 AND " . $max );
	}

	/**
	 * Phase 2: a shifted staged id becomes the live id of its login (below OFFSET, so a repeat finds it done).
	 *
	 * @param string $table  Table.
	 * @param string $column User-id column.
	 */
	public function remap( string $table, string $column ): void {
		if ( ! $this->has( 'users' ) ) {
			return;
		}
		$c = 'x.' . DumpFormat::quoteIdentifier( $column );
		$this->db->execute(
			'UPDATE ' . DumpFormat::quoteIdentifier( $table ) . ' AS x JOIN ' . $this->staged( 'users' ) . " AS s ON {$c} = s.ID + " . self::OFFSET
			. ' JOIN ' . $this->live( 'users' ) . ' AS u ON ' . $this->users->login( 'u', 's' ) . " SET {$c} = u.ID WHERE {$c} > " . self::OFFSET
		);
	}

	/**
	 * Phase 3: shifted ids no staged user had move back.
	 *
	 * @param string $table  Table.
	 * @param string $column User-id column.
	 */
	public function unshift( string $table, string $column ): void {
		$c = DumpFormat::quoteIdentifier( $column );
		$this->db->execute( 'UPDATE ' . DumpFormat::quoteIdentifier( $table ) . " SET {$c} = {$c} - " . self::OFFSET . " WHERE {$c} > " . self::OFFSET );
	}

	/**
	 * Review N1: refuses (empties) staged roles on the restored sites that are
	 * not roles of that site. Call it before insertUserMeta().
	 *
	 * @param array<int, int> $newIds Restored site ids.
	 * @return array<int, string> Staged logins whose role was refused.
	 */
	public function sanitizeCapabilities( array $newIds ): array {
		if ( ! $this->has( 'usermeta' ) || ! $this->has( 'users' ) ) {
			return [];
		}

		return ( new StagedCapabilities( $this->db, $this->base, $this->staged( 'usermeta' ), $this->staged( 'users' ) ) )->sanitize( $newIds );
	}

	/** The largest live user id (save it before insertUsers(): users above it were added by this merge). */
	public function liveMaxId(): int {
		return (int) ( $this->db->query( 'SELECT MAX(ID) AS m FROM ' . $this->live( 'users' ) )[0]['m'] ?? 0 );
	}

	/**
	 * Adds the allowed staged user meta each (live user, key) lacks, one row
	 * per (live user, key): the first row of the first archive user of the
	 * login (review M4). Allowed: keys starting <base><newId>_ of a restored
	 * site (exact case) for every matched user, and PROFILE_KEYS for users
	 * added by this merge (id above $liveMax). primary_blog and source_domain
	 * point to the new site (else the main site and the network host).
	 *
	 * @param array<int|string, int> $blogIds     Old blog id ('' for a single-site archive) => new site id.
	 * @param array<string, string>  $hosts       Old site host => new site host.
	 * @param int                    $mainSiteId  The network's main site.
	 * @param string                 $networkHost The network's host.
	 * @param int                    $liveMax     liveMaxId() before insertUsers().
	 */
	public function insertUserMeta( array $blogIds, array $hosts, int $mainSiteId, string $networkHost, int $liveMax ): void {
		if ( ! $this->has( 'usermeta' ) || ! $this->has( 'users' ) ) {
			return;
		}
		$case = 'CASE';
		foreach ( $blogIds as $old => $new ) {
			$case .= " WHEN m.meta_key = 'primary_blog' AND m.meta_value = " . $this->literal( (string) $old ) . ' THEN ' . $this->literal( (string) $new );
		}
		foreach ( $hosts as $old => $new ) {
			$case .= " WHEN m.meta_key = 'source_domain' AND m.meta_value = " . $this->literal( (string) $old ) . ' THEN ' . $this->literal( $new );
		}
		$case  .= " WHEN m.meta_key = 'primary_blog' THEN " . $this->literal( (string) $mainSiteId ) . " WHEN m.meta_key = 'source_domain' THEN " . $this->literal( $networkHost ) . ' ELSE m.meta_value END';
		$staged = $this->staged( 'usermeta' );
		$this->db->execute(
			'INSERT INTO ' . $this->live( 'usermeta' ) . " (user_id, meta_key, meta_value) SELECT u.ID, m.meta_key, {$case} FROM {$staged} AS m JOIN " . $this->staged( 'users' ) . ' AS s ON m.user_id = s.ID'
			. ' JOIN ' . $this->live( 'users' ) . ' AS u ON ' . $this->users->login( 'u', 's' ) . ' LEFT JOIN ' . $this->live( 'usermeta' ) . ' AS e ON e.user_id = u.ID AND ' . self::sameKey( 'e', 'm' )
			. ' WHERE e.umeta_id IS NULL AND ' . $this->firstOfLogin( 's' ) . ' AND (' . $this->siteKey( 'm', array_values( $blogIds ) ) . ' OR (u.ID > ' . $liveMax . ' AND ' . $this->profileKey( 'm' ) . '))'
			. " AND m.umeta_id = (SELECT MIN(f.umeta_id) FROM {$staged} AS f WHERE f.user_id = m.user_id AND " . self::sameKey( 'f', 'm' ) . ') ORDER BY m.umeta_id'
		);
	}

	/**
	 * @param array<int, string> $tables Table names.
	 * @return array<string, array<string, array{0: string, 1: string}>> Table => lower-case column => [column, COLUMN_TYPE].
	 * @throws QueryException When the columns cannot be read.
	 */
	private function columnTypes( array $tables ): array {
		$names = implode( ', ', array_map( fn( string $t ): string => $this->literal( $t ), $tables ) );
		$types = [];
		foreach ( $this->db->query( "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS y FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$names})" ) as $row ) {
			$types[ (string) $row['t'] ][ strtolower( (string) $row['c'] ) ] = [ (string) $row['c'], (string) $row['y'] ];
		}

		return $types;
	}

	/**
	 * The staged user is the first (lowest id) of the staged logins the live column sees as one (review M4).
	 *
	 * @param string $alias Alias of the staged users table.
	 */
	private function firstOfLogin( string $alias ): string {
		return 'NOT EXISTS (SELECT 1 FROM ' . $this->staged( 'users' ) . " AS d WHERE d.ID < {$alias}.ID AND " . $this->users->form( 'user_login', 'd.user_login' ) . ' = ' . $this->users->form( 'user_login', $alias . '.user_login' ) . ')';
	}

	/**
	 * Byte-for-byte meta key match (Task 8: collations of two tables may not mix).
	 *
	 * @param string $a Alias.
	 * @param string $b Alias.
	 */
	private static function sameKey( string $a, string $b ): string {
		return "CAST({$a}.meta_key AS BINARY) = CAST({$b}.meta_key AS BINARY)";
	}

	/**
	 * A key of a restored site: <base><id>_… for one of $newIds, exact case.
	 *
	 * @param string          $alias  Alias of the staged usermeta table.
	 * @param array<int, int> $newIds New site ids.
	 */
	private function siteKey( string $alias, array $newIds ): string {
		$parts = [];
		foreach ( $newIds as $id ) {
			$prefix  = $this->base . (int) $id . '_';
			$parts[] = "LEFT(CAST({$alias}.meta_key AS BINARY), " . strlen( $prefix ) . ') = CAST(' . $this->literal( $prefix ) . ' AS BINARY)';
		}

		return [] === $parts ? 'FALSE' : '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * One of PROFILE_KEYS, exact case.
	 *
	 * @param string $alias Alias of the staged usermeta table.
	 */
	private function profileKey( string $alias ): string {
		$keys = implode( ', ', array_map( fn( string $key ): string => 'CAST(' . $this->literal( $key ) . ' AS BINARY)', self::PROFILE_KEYS ) );

		return "CAST({$alias}.meta_key AS BINARY) IN ({$keys})";
	}

	private function clashes(): UserClashes {
		return new UserClashes( $this->db, $this->users, $this->staged( 'users' ), $this->live( 'users' ) );
	}

	private function has( string $name ): bool {
		return in_array( $this->tmp . NetworkPrefixPairs::STAGE . $name, $this->held(), true );
	}

	/**
	 * @return array<int, string>
	 */
	private function held(): array {
		if ( null === $this->held ) {
			$this->held = [];
			foreach ( $this->db->query( "SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'" ) as $row ) {
				$name = (string) array_values( $row )[0];
				if ( str_starts_with( $name, $this->tmp . NetworkPrefixPairs::STAGE ) ) {
					$this->held[] = $name;
				}
			}
		}

		return $this->held;
	}

	private function live( string $name ): string {
		return DumpFormat::quoteIdentifier( $this->base . $name );
	}

	private function staged( string $name ): string {
		return DumpFormat::quoteIdentifier( $this->tmp . NetworkPrefixPairs::STAGE . $name );
	}

	private function literal( string $value ): string {
		return "'" . $this->db->escape( $value ) . "'";
	}
}

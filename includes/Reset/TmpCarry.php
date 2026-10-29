<?php
/**
 * What a database reset carries from the live tables into the fresh ones.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Import\TmpTables;
use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Decision D13 with rulings M1/M2: the fresh tables get the site's address,
 * name, visibility, permalinks, admin e-mail, time, date and language
 * settings, uploads folder settings and salts from the live site, our own
 * lw_migration_* rows (the current lock, connections, schedules), LW
 * Migration as the only active
 * plugin and, when given, the live theme. Of the users only the one who
 * started the reset stays: same id, login, password hash, e-mail, sessions
 * and profile, as an administrator; the counter of the users table is raised
 * above every id the live table holds or handed out (ruling M3), so a new
 * user never takes the id a removed administrator's connections name.
 *
 * Every write goes to the temporary tables through TmpTables' validated
 * names (live tables are only read); every value is escaped and the user id
 * is an int. Each part replaces by key, so a replay changes nothing.
 */
final class TmpCarry {

	public const KEEP_OPTIONS = [ 'siteurl', 'home', 'blogname', 'blog_public', 'permalink_structure', 'admin_email', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG', 'upload_path', 'upload_url_path' ];

	public const SALTS = [ 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secret_key' ];

	public const PROFILE_KEYS = [ 'first_name', 'last_name', 'nickname', 'description', 'rich_editing', 'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale' ];

	private const USER_COLUMNS = [ 'ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name' ];

	/** The administrator role as WordPress stores it in <prefix>capabilities. */
	private const ADMINISTRATOR = 'a:1:{s:13:"administrator";b:1;}';

	/**
	 * Validated writes into the temporary tables.
	 *
	 * @var TmpTables
	 */
	private TmpTables $tables;

	/**
	 * @param Connection $db   Site database.
	 * @param string     $tmp  The run's temporary prefix (lwtXXXX_), the only one written to.
	 * @param string     $base Site table prefix, only read.
	 * @throws \LightweightPlugins\Migration\Database\DatabaseException When a prefix could address a live table (TmpTables).
	 */
	public function __construct( private Connection $db, private string $tmp, private string $base ) {
		$this->tables = new TmpTables( $db, $tmp, $base );
	}

	/**
	 * @param int                                              $userId    The administrator who started the reset.
	 * @param string                                           $login     Their login, as recorded in the job.
	 * @param string                                           $ownPlugin This plugin's basename.
	 * @param array{template: string, stylesheet: string}|null $theme The live theme to keep, null for the fresh default.
	 * @return bool False when the live user with that id and login is gone (nothing is written).
	 */
	public function carry( int $userId, string $login, string $ownPlugin, ?array $theme ): bool {
		if ( ! $this->liveUser( $userId, $login ) ) {
			return false;
		}
		$this->tables->copyOptions( array_merge( self::KEEP_OPTIONS, self::SALTS ) );
		$this->ownRows();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the list WordPress stores in active_plugins.
		$this->tables->setOption( 'active_plugins', serialize( [ $ownPlugin ] ) );
		if ( null !== $theme ) {
			$this->tables->setOption( 'template', (string) $theme['template'] );
			$this->tables->setOption( 'stylesheet', (string) $theme['stylesheet'] );
		}
		$this->renameRoles();
		$this->keepUser( $userId );
		$this->tables->raiseAutoIncrement( 'users', 'ID' );

		return true;
	}

	/**
	 * Our lw_migration_* rows (the lock, the run registry, connections,
	 * schedules, sealed job secrets) as they are live right now; not the
	 * size estimate (Options::NOT_CARRIED).
	 */
	public function ownRows(): void {
		$this->tables->copyOptionPrefixes( Options::DUMP_EXCLUDED_PREFIXES, Options::NOT_CARRIED );
	}

	/**
	 * Byte-exact: the job recorded the login WordPress gave it.
	 *
	 * @param int    $userId User id.
	 * @param string $login  Login.
	 */
	private function liveUser( int $userId, string $login ): bool {
		$rows = $this->db->query( 'SELECT user_login FROM ' . $this->live( 'users' ) . ' WHERE ID = ' . $userId );

		return 1 === count( $rows ) && (string) $rows[0]['user_login'] === $login;
	}

	/**
	 * The roles populate_roles() wrote under the temporary prefix's name; the
	 * site reads them under its own. Renamed only while the temporary row is
	 * there, so a replay keeps the roles it renamed before.
	 */
	private function renameRoles(): void {
		$options = $this->temp( 'options' );
		$fresh   = $this->quote( $this->tmp . 'user_roles' );
		if ( [] === $this->db->query( "SELECT option_id FROM {$options} WHERE option_name = {$fresh}" ) ) {
			return;
		}
		$site = $this->quote( $this->base . 'user_roles' );
		$this->transaction(
			function () use ( $options, $fresh, $site ): void {
				$this->db->execute( "DELETE FROM {$options} WHERE option_name = {$site}" );
				$this->db->execute( "UPDATE {$options} SET option_name = {$site} WHERE option_name = {$fresh}" );
			}
		);
	}

	/**
	 * The user row and the kept meta, then the administrator role; every
	 * other user and their meta are not carried.
	 *
	 * @param int $id User id.
	 */
	private function keepUser( int $id ): void {
		$users    = $this->temp( 'users' );
		$usermeta = $this->temp( 'usermeta' );
		$columns  = implode( ', ', array_map( [ DumpFormat::class, 'quoteIdentifier' ], self::USER_COLUMNS ) );
		$copied   = implode( ', ', array_map( [ $this, 'quote' ], array_merge( [ 'session_tokens' ], self::PROFILE_KEYS ) ) );
		$caps     = $this->quote( $this->base . 'capabilities' );
		$level    = $this->quote( $this->base . 'user_level' );
		$this->transaction(
			function () use ( $id, $users, $usermeta, $columns, $copied, $caps, $level ): void {
				$this->db->execute( "DELETE FROM {$users} WHERE ID = {$id}" );
				$this->db->execute( "INSERT INTO {$users} ({$columns}) SELECT {$columns} FROM " . $this->live( 'users' ) . " WHERE ID = {$id}" );
				$this->db->execute( "DELETE FROM {$usermeta} WHERE user_id = {$id} AND meta_key IN ({$copied}, {$caps}, {$level})" );
				$this->db->execute( "INSERT INTO {$usermeta} (user_id, meta_key, meta_value) SELECT {$id}, meta_key, meta_value FROM " . $this->live( 'usermeta' ) . " WHERE user_id = {$id} AND meta_key IN ({$copied})" );
				$this->db->execute( "INSERT INTO {$usermeta} (user_id, meta_key, meta_value) VALUES ({$id}, {$caps}, " . $this->quote( self::ADMINISTRATOR ) . "), ({$id}, {$level}, '10')" );
			}
		);
	}

	/**
	 * Runs the writes in one transaction; on any error rolls back and rethrows.
	 *
	 * @param \Closure $writes Statements to run.
	 * @throws \Throwable Whatever the writes threw, after the rollback.
	 */
	private function transaction( \Closure $writes ): void {
		$this->db->execute( 'START TRANSACTION' );
		try {
			$writes();
		} catch ( \Throwable $e ) {
			try {
				$this->db->execute( 'ROLLBACK' );
			} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the original error is the one to report.
				unset( $ignored );
			}
			throw $e;
		}
		$this->db->execute( 'COMMIT' );
	}

	/**
	 * @param string $suffix Core table name without prefix (a constant of this class).
	 * @return string Quoted temporary table name.
	 */
	private function temp( string $suffix ): string {
		return DumpFormat::quoteIdentifier( $this->tmp . $suffix );
	}

	/**
	 * Live tables appear only in SELECT positions.
	 *
	 * @param string $suffix Core table name without prefix (a constant of this class).
	 * @return string Quoted live table name.
	 */
	private function live( string $suffix ): string {
		return DumpFormat::quoteIdentifier( $this->base . $suffix );
	}

	private function quote( string $value ): string {
		return "'" . $this->db->escape( $value ) . "'";
	}
}

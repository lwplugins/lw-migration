<?php
/**
 * Safety checks on a database connection before dumping or importing.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * SQL escaping and literal parsing here are charset-agnostic, which is only
 * safe for charsets where a multibyte character cannot end in a backslash or
 * quote byte; the server reads a statement in character_set_client and
 * converts it to character_set_connection, so both are checked. The quote
 * scanner of the import also reads backslashes as escapes, which the server
 * does only without NO_BACKSLASH_ESCAPES in the session sql_mode, and reads
 * "..." as a string, which the server does only without ANSI_QUOTES (ANSI).
 */
final class ConnectionGuard {

	/** Connection charsets where charset-agnostic escaping is unsafe. */
	private const UNSAFE_CHARSETS = [ 'big5', 'cp932', 'gbk', 'gb2312', 'gb18030', 'sjis' ];

	/** Flags of the sql_mode the statement checks cannot work under => the flag a refusal names. */
	private const UNSAFE_MODES = [
		'NO_BACKSLASH_ESCAPES' => 'NO_BACKSLASH_ESCAPES',
		'ANSI_QUOTES'          => 'ANSI_QUOTES',
		'ANSI'                 => 'ANSI_QUOTES',
	];

	/** Session variables naming the charset a statement is read in. */
	private const CHARSET_VARIABLES = [ 'character_set_client', 'character_set_connection' ];

	/**
	 * @param Connection $db Connection to check; its charset is never changed.
	 * @throws DatabaseException For an unsafe charset.
	 * @return void
	 */
	public static function assertSafeCharset( Connection $db ): void {
		foreach ( self::CHARSET_VARIABLES as $variable ) {
			$rows    = $db->query( 'SELECT @@' . $variable . ' AS cs' );
			$charset = strtolower( (string) ( $rows[0]['cs'] ?? '' ) );
			if ( in_array( $charset, self::UNSAFE_CHARSETS, true ) ) {
				throw new DatabaseException( sprintf( 'The database connection uses the %1$s character set (%2$s), which is not safe for SQL dumps and imports. Switch DB_CHARSET to utf8mb4.', $charset, $variable ) );
			}
		}
	}

	/**
	 * Run after the session sql_mode was set, before any statement of an archive.
	 *
	 * @param Connection $db Connection to check.
	 * @throws DatabaseException When the session reads backslashes literally or "..." as a name, or does not tell its sql_mode.
	 * @return void
	 */
	public static function assertSqlMode( Connection $db ): void {
		$rows = $db->query( 'SELECT @@SESSION.sql_mode AS mode' );
		if ( ! isset( $rows[0]['mode'] ) ) {
			throw new DatabaseException( 'The database server did not tell its session sql_mode, so the import cannot check how it reads the statements. The import stopped before any statement of the archive ran.' );
		}
		$modes = explode( ',', strtoupper( (string) $rows[0]['mode'] ) );
		foreach ( self::UNSAFE_MODES as $mode => $named ) {
			if ( in_array( $mode, $modes, true ) ) {
				throw new DatabaseException( sprintf( 'The database session keeps %s in its sql_mode, so it would read the statements of the archive differently than the import checks them. The import stopped before any statement of the archive ran.', $named ) );
			}
		}
	}
}

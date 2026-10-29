<?php
/**
 * Creates the views an import deferred until after its swap.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The statements run one by one on a checked session with an empty sql_mode,
 * like the import (ConnectionGuard; a failed check skips them all with one
 * warning, since the swap is done by now). Anything that is not a final-prefix
 * DROP VIEW IF EXISTS / CREATE VIEW … AS (ImportScope, defence in depth), or
 * that reads another database (ViewReferences), is skipped with a warning; a
 * view the server refuses is a warning too.
 */
final class DeferredViews {

	private const MAX_VIEW_BYTES = 16777216;

	/** Bytes of a warning at most. */
	private const WARNING_BYTES = 400;

	/**
	 * @param Connection  $db    Site database.
	 * @param ImportScope $scope Allow-list of the swap.
	 */
	public function __construct( private Connection $db, private ImportScope $scope ) {
	}

	/**
	 * @param string $path Deferred statements file.
	 * @return array<int, string> Warnings.
	 */
	public function apply( string $path ): array {
		if ( ! is_file( $path ) ) {
			return [];
		}
		try {
			ConnectionGuard::assertSafeCharset( $this->db );
		} catch ( DatabaseException $e ) {
			return [ self::skippedAll( $e ) ];
		}

		return (array) SessionMode::relaxed(
			$this->db,
			function () use ( $path ): array {
				try {
					ConnectionGuard::assertSqlMode( $this->db );
				} catch ( DatabaseException $e ) {
					return [ self::skippedAll( $e ) ];
				}
				return $this->run( $path );
			}
		);
	}

	/**
	 * @param string $path Deferred statements file.
	 * @return array<int, string> Warnings.
	 */
	private function run( string $path ): array {
		$warnings = [];
		$reader   = new StatementReader( $path );
		$offset   = 0;
		try {
			for ( $statement = $reader->next( $offset, self::MAX_VIEW_BYTES ); null !== $statement; $statement = $reader->next( $offset, self::MAX_VIEW_BYTES ) ) {
				$offset = $statement->end;
				if ( $statement->oversized ) {
					$warnings[] = 'View statement skipped: larger than the allowed size.';
					continue;
				}
				if ( $statement->truncated ) {
					$warnings[] = 'View statement skipped: the deferred file ends with an incomplete statement.';
					continue;
				}
				$warning = $this->one( $statement->sql );
				if ( null !== $warning ) {
					$warnings[] = $warning;
				}
			}
		} finally {
			$reader->close();
		}

		return $warnings;
	}

	/**
	 * @param string $statement One deferred statement.
	 * @return string|null Warning, null when the statement ran.
	 */
	private function one( string $statement ): ?string {
		$sql = $this->scope->deferrableView( $statement );
		if ( null === $sql ) {
			return 'Statement skipped: not a view statement for this site.';
		}
		$name    = 1 === preg_match( '/\bVIEW\s+(`(?:[^`]|``)+`)/i', $sql, $m ) ? $m[1] : '`?`'; // Our own parse, never the server's text.
		$foreign = ViewReferences::otherDatabase( $sql, $this->db->databaseName() );
		if ( null !== $foreign ) {
			return Text::clip( sprintf( 'View statement skipped for %s: %s.', $name, ViewReferences::reason( $foreign ) ), self::WARNING_BYTES );
		}
		try {
			$this->db->execute( $sql );
		} catch ( QueryException $e ) {
			return Text::clip( sprintf( 'View statement failed for %s (%d): %s', $name, $e->errno(), ServerError::describe( $e->errno() ) ), self::WARNING_BYTES );
		}

		return null;
	}

	private static function skippedAll( DatabaseException $e ): string {
		return Text::clip( 'View statements skipped: ' . $e->getMessage(), self::WARNING_BYTES );
	}
}

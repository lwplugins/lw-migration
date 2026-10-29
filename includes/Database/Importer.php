<?php
/**
 * Runs database.sql into temporary tables, resumably.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Memory;
use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Table identifiers go to the temporary prefix (exact whole names first); prefix
 * placeholders inside data go to the final prefix; tables with a left-out prefix
 * are skipped and listed in the cursor. Only statements that write the import tables
 * run (ImportScope); views are deferred until after the swap, except one that
 * reads another database (ViewReferences), which is skipped with a warning.
 * Each tick checks the session (ConnectionGuard: charset, then the relaxed
 * sql_mode) before any statement of the dump runs.
 *
 * The importer owns the transaction: the dump's own START TRANSACTION/COMMIT
 * are skipped, each tick runs in one transaction that is committed when run()
 * returns and rolled back when it throws. A table DROP/CREATE commits
 * implicitly, so it never follows data in the same tick: the tick ends before
 * it, and a DROP TABLE never ends a tick before its CREATE TABLE. The job
 * must save the cursor only after run() returns normally; then a tick killed
 * or failed mid-way replays exactly from the last saved offset. The one
 * inherent gap: if the process dies after run() returned (COMMIT done) but
 * before the cursor is saved, that tick's rows are replayed once more.
 *
 * run() may return false even without a deadline (at a table boundary):
 * callers loop until it returns true.
 */
final class Importer {

	private const SKIP = [ "'_transient_", "'_site_transient_", "'_wc_session_", "'_wpallimport_session_" ];

	private const MAX_ATTEMPTS = 4;

	/** Server errors that end or keep failing the transaction: stop the import. */
	private const FATAL = [ 1021, 1114, 1205, 1213, 1813, 2006, 2013, 4031 ];

	/** Bytes of a statement description (kind and table, never values) in a warning. */
	private const EXCERPT = 60;

	/** Peak memory of processing one statement, in multiples of its size. */
	private const MEMORY_FACTOR = 6;

	/** Bytes of a server message quoted in a warning. */
	private const MESSAGE = 512;

	/** @var array<int, string> */
	private array $identifierSearch;

	/** @var array<int, string> */
	private array $identifierReplace;

	/** @var array<int, string> */
	private array $dataSearch;

	/** @var array<int, string> */
	private array $dataReplace;

	/** @var ImportScope */
	private ImportScope $scope;

	/** @var string Temporary prefix of extra-prefix tables ('' when there are none). */
	private string $extraTmpPrefix;

	/**
	 * @param Connection      $db     Destination database.
	 * @param StatementReader $reader database.sql.
	 * @param ImportPlan      $plan   Prefixes, replacement and limits.
	 * @throws DatabaseException When a prefix has the wrong shape.
	 */
	public function __construct( private Connection $db, private StatementReader $reader, private ImportPlan $plan ) {
		PrefixRules::assertImportPrefixes( $plan->tmpPrefix, $plan->extraPrefixes );
		$ids                     = PrefixRules::exactIdentifierPairs( $plan->exactTables, $plan->basePrefix, $plan->tmpPrefix ) + PrefixRules::identifierPairs( $plan->prefixPairs, $plan->basePrefix, $plan->tmpPrefix );
		$this->identifierSearch  = array_map( 'strval', array_keys( $ids ) );
		$this->identifierReplace = array_values( $ids );
		$this->dataSearch        = array_map( 'strval', array_keys( $plan->prefixPairs ) );
		$this->dataReplace       = array_values( $plan->prefixPairs );
		$this->extraTmpPrefix    = [] === $plan->extraPrefixes ? '' : PrefixRules::extraTempPrefix( $plan->tmpPrefix );
		$this->scope             = new ImportScope( $plan->basePrefix, $plan->tmpPrefix, $this->extraTmpPrefix, $plan->siteSuffixes, $plan->stageSuffixes );
	}

	/**
	 * @param ImportCursor $cursor   Progress; save it only when this returns.
	 * @param Deadline     $deadline Time budget.
	 * @throws DatabaseException On an unsafe charset or sql_mode, a fatal server error or a failed commit.
	 * @throws \Throwable Anything else that stopped the tick, after rolling it back.
	 * @return bool True when the whole file is imported.
	 */
	public function run( ImportCursor $cursor, Deadline $deadline ): bool {
		ConnectionGuard::assertSafeCharset( $this->db );

		return (bool) SessionMode::relaxed(
			$this->db,
			function () use ( $cursor, $deadline ): bool {
				ConnectionGuard::assertSqlMode( $this->db );
				return $this->tick( $cursor, $deadline );
			}
		);
	}

	private function tick( ImportCursor $cursor, Deadline $deadline ): bool {
		$this->db->execute( 'START TRANSACTION' );
		try {
			$done = $this->loop( $cursor, $deadline );
		} catch ( \Throwable $e ) {
			$this->rollback();
			throw $e;
		}
		try {
			$this->db->execute( 'COMMIT' );
		} catch ( QueryException $e ) {
			throw new DatabaseException( sprintf( 'Could not commit the imported rows (%d): %s', $e->errno(), ServerError::describe( $e->errno() ) ), $e->errno(), $e );
		}

		return $done;
	}

	private function loop( ImportCursor $cursor, Deadline $deadline ): bool {
		$dirty = false;
		while ( null !== ( $statement = $this->reader->next( $cursor->offset, $this->plan->maxPacket ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition,WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- read loop.
			$ddl = ! $statement->oversized && ! $statement->truncated && $this->scope->isTableDdl( $statement->sql );
			if ( $ddl && $dirty ) {
				return false; // Its implicit commit would make this tick's rows unreplayable.
			}
			$cursor->offset = $statement->end;
			++$cursor->statements;
			if ( $statement->truncated ) {
				$cursor->addWarning( sprintf( 'The database file ends with an incomplete statement (byte %d); it was not imported.', $statement->start ) );
			} elseif ( $statement->oversized ) {
				$cursor->addWarning( sprintf( 'Skipped a statement at byte %d: it is larger than the server allows (max_allowed_packet).', $statement->start ) );
			} else {
				$this->process( $statement, $cursor );
			}
			if ( $ddl ) {
				$this->db->execute( 'START TRANSACTION' );
			} else {
				$dirty = true;
			}
			// A DROP TABLE and the CREATE TABLE after it run in the same tick, so a replay never re-creates an existing table.
			$drop = $ddl && 1 === preg_match( '/^DROP\s/i', $statement->sql );
			if ( ! $drop && $deadline->reached() ) {
				return false;
			}
		}

		return true;
	}

	private function rollback(): void {
		try {
			$this->db->execute( 'ROLLBACK' );
		} catch ( DatabaseException $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the original error is the one to report.
			unset( $ignored );
		}
	}

	private function process( Statement $statement, ImportCursor $cursor ): void {
		$sql = $statement->sql;
		if ( $this->scope->isTransactionControl( $sql ) ) {
			return; // The importer owns the transaction.
		}
		if ( $this->exceedsMemory( $statement, $cursor ) ) {
			return;
		}
		if ( $this->scope->isView( $sql ) && [] !== $this->plan->siteSuffixes ) {
			$view = $this->scope->viewName( str_ireplace( $this->dataSearch, $this->dataReplace, $sql ) );
			if ( null !== $view ) {
				$cursor->skipView( Text::clip( $view, self::EXCERPT ) ); // A subsite restore brings no view; named once by the caller.
			}
			return;
		}
		if ( $this->scope->isView( $sql ) ) {
			$this->deferView( str_ireplace( $this->dataSearch, $this->dataReplace, $sql ), $statement, $cursor );
			return;
		}
		$left = PrefixRules::leftOutTable( $sql, $this->plan->leftOutPrefixes );
		if ( null !== $left ) {
			$cursor->leaveOut( $left ); // Listed once by the caller, never imported.
			return;
		}
		$sql    = str_ireplace( $this->identifierSearch, $this->identifierReplace, $sql );
		$sql    = PrefixRules::mapExtraTables( $sql, $this->plan->extraPrefixes, $this->extraTmpPrefix );
		$sql    = str_ireplace( $this->dataSearch, $this->dataReplace, $sql );
		$create = $this->scope->isCreateTable( $sql );
		if ( $create ) {
			$sql = $this->plan->fixer->fixCreate( $sql ); // Checked below in its final form.
		}
		if ( ! $this->scope->targetsImportTables( $sql ) ) {
			$cursor->addWarning( $this->outOfScope( $sql, $create, $statement ) );
			return;
		}
		if ( $this->skipped( $sql ) ) {
			return;
		}
		if ( $create ) {
			$this->assertNameLength( $sql );
			$this->execute( $sql, $statement, $cursor, true );
			return;
		}
		$replaced = $this->replace( $sql, $statement, $cursor );
		if ( $replaced !== $sql && ! $this->scope->targetsImportTables( $replaced ) ) {
			$cursor->addWarning( sprintf( 'Skipped a statement that does not target the import tables after URL replacement (byte %d): %s', $statement->start, self::describe( $replaced ) ) );
			return;
		}
		$this->execute( $replaced, $statement, $cursor, false );
	}

	/**
	 * A value that cannot be replaced must never abort the import: the
	 * statement then runs unchanged and a warning records it.
	 *
	 * @param string       $sql       Statement.
	 * @param Statement    $statement Source position.
	 * @param ImportCursor $cursor    Progress.
	 * @return string
	 */
	private function replace( string $sql, Statement $statement, ImportCursor $cursor ): string {
		try {
			$replaced = $this->plan->replacer->statement( $sql );
		} catch ( DatabaseException | \TypeError | \ValueError $e ) {
			$cursor->addWarning( sprintf( 'Statement at byte %d was imported without URL replacement: %s', $statement->start, Text::clip( $e->getMessage(), self::MESSAGE ) ) );

			return $sql;
		}
		foreach ( array_filter( array_unique( array_map( [ $this, 'columnLabel' ], $this->plan->replacer->limitedColumns() ) ), [ $cursor, 'limitOnce' ] ) as $label ) {
			$cursor->addWarning( sprintf( 'A serialized value in %s (first in the statement at byte %d) is nested too deep or is too large to search in full: its innermost part was imported unchanged, without URL replacement.', $label, $statement->start ) );
		}

		return $replaced;
	}

	/**
	 * `table`.`column` with the table's final name, `table` column N without a column list, or the table alone past the label cap.
	 *
	 * @param array{table: string, column: string|null, position: int} $column Where the value went.
	 */
	private function columnLabel( array $column ): string {
		$table = $column['table'];
		if ( str_starts_with( $table, $this->plan->tmpPrefix ) ) {
			$table = $this->plan->basePrefix . substr( $table, strlen( $this->plan->tmpPrefix ) );
		} elseif ( '' !== $this->extraTmpPrefix && str_starts_with( $table, $this->extraTmpPrefix ) ) {
			$table = substr( $table, strlen( $this->extraTmpPrefix ) );
		}
		$table = DumpFormat::quoteIdentifier( $table );

		if ( null !== $column['column'] ) {
			return $table . '.' . DumpFormat::quoteIdentifier( $column['column'] );
		}

		return 0 === $column['position'] ? $table . ' (more columns)' : $table . ' column ' . $column['position'];
	}

	private function execute( string $sql, Statement $statement, ImportCursor $cursor, bool $create ): void {
		$seen = [];
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			if ( $create && ! $this->scope->isSafeCreate( $sql ) ) {
				$cursor->addWarning( $this->refusedCreate( $sql, $statement ) );
				return;
			}
			try {
				$this->db->execute( $sql );
				return;
			} catch ( QueryException $e ) {
				$this->assertRecoverable( $e, $create );
				$fixed = $create && ! isset( $seen[ $e->errno() ] ) ? $this->plan->fixer->fixForError( $sql, $e->errno() ) : null;
				if ( null === $fixed || self::MAX_ATTEMPTS === $attempt ) {
					$cursor->addWarning( sprintf( 'Statement at byte %d failed (%d): %s — %s', $statement->start, $e->errno(), ServerError::describe( $e->errno() ), self::describe( $sql ) ) );
					return;
				}
				$seen[ $e->errno() ] = true;
				$sql                 = $fixed;
			}
		}
	}

	/**
	 * @param QueryException $e      Failure.
	 * @param bool           $create Whether the statement is a CREATE TABLE (1030 is fixable there).
	 * @throws DatabaseException When the error ends the transaction or would repeat for every statement.
	 * @return void
	 */
	private function assertRecoverable( QueryException $e, bool $create ): void {
		$errno = $e->errno();
		$fatal = in_array( $errno, self::FATAL, true )
			|| ( 1030 === $errno && ! $create )
			|| ( 1226 === $errno && false !== stripos( $e->serverText(), 'max_' ) );
		if ( $fatal ) {
			throw new DatabaseException( sprintf( 'The database server stopped the import (%d): %s', $errno, ServerError::describe( $errno ) ), $errno, $e );
		}
	}

	private function outOfScope( string $sql, bool $create, Statement $statement ): string {
		if ( $create && $this->scope->namesImportTable( $sql ) ) {
			return $this->refusedCreate( $sql, $statement );
		}

		return sprintf( 'Skipped a statement that does not target the import tables (byte %d): %s', $statement->start, self::describe( $sql ) );
	}

	private function refusedCreate( string $sql, Statement $statement ): string {
		$format = $this->scope->refusesColumnExpression( $sql )
			? 'Skipped a CREATE TABLE whose column default, generated column, CHECK constraint or key part uses a function or expression the import does not allow (byte %d): %s'
			: 'Skipped a CREATE TABLE whose options reach outside the import tables (byte %d): %s';

		return sprintf( $format, $statement->start, self::describe( $sql ) );
	}

	/**
	 * Locates a statement for a warning without quoting row data: its leading
	 * keywords and first quoted name (e.g. "INSERT INTO `wp_options`"), at most
	 * EXCERPT bytes; only the first keyword when no name follows them.
	 *
	 * @param string $sql Statement.
	 */
	private static function describe( string $sql ): string {
		if ( 1 === preg_match( '/^\s*((?:[A-Za-z]+\s+){1,6})(`(?:[^`]|``)*`)/', $sql, $m ) ) {
			return Text::clip( strtoupper( (string) preg_replace( '/\s+/', ' ', $m[1] ) ) . $m[2], self::EXCERPT );
		}

		return 1 === preg_match( '/^\s*([A-Za-z]+)/', $sql, $m ) ? strtoupper( $m[1] ) : 'statement';
	}

	/**
	 * Mapping and replacement copy a statement several times; a fatal
	 * out-of-memory error could not be reported, so the statement is skipped.
	 *
	 * @param Statement    $statement Statement.
	 * @param ImportCursor $cursor    Progress.
	 * @return bool
	 */
	private function exceedsMemory( Statement $statement, ImportCursor $cursor ): bool {
		$needed    = strlen( $statement->sql ) * self::MEMORY_FACTOR;
		$available = Memory::available();
		if ( null === $available || $needed <= $available ) {
			return false;
		}
		$cursor->addWarning( sprintf( 'Skipped a statement at byte %d: too large for the PHP memory limit (%d MB needed)', $statement->start, (int) ceil( $needed / 1048576 ) ) );

		return true;
	}

	private function skipped( string $sql ): bool {
		foreach ( self::SKIP as $needle ) {
			if ( str_contains( $sql, $needle ) ) {
				return true;
			}
		}

		return 0 === stripos( $sql, 'INSERT INTO `' . $this->plan->tmpPrefix . 'eum_logs`' );
	}

	private function assertNameLength( string $sql ): void {
		if ( 1 === preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $m ) && strlen( $m[1] ) > 64 ) {
			throw new DatabaseException( sprintf( 'Table name is too long for a safe import: %s', Text::clip( $m[1], self::EXCERPT ) ) );
		}
	}

	private function deferView( string $sql, Statement $statement, ImportCursor $cursor ): void {
		$deferrable = $this->scope->deferrableView( $sql );
		if ( null === $deferrable ) {
			$cursor->addWarning( sprintf( 'Skipped a view statement that does not name a site view (byte %d): %s', $statement->start, self::describe( $sql ) ) );
			return;
		}
		$sql     = $deferrable;
		$foreign = ViewReferences::otherDatabase( $sql, $this->db->databaseName() );
		if ( null !== $foreign ) {
			$cursor->addWarning( sprintf( 'Skipped the view `%s` (byte %d): %s.', Text::clip( (string) $this->scope->viewName( $sql ), self::EXCERPT ), $statement->start, ViewReferences::reason( $foreign ) ) );
			return;
		}
		if ( false === file_put_contents( $this->plan->deferredPath, $sql . "\n", FILE_APPEND ) ) {
			throw new DatabaseException( 'Cannot write the deferred view statements.' );
		}
	}
}

<?php
/**
 * Writes database.sql in the .wpress dump format, resumably.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Tables first (DROP/CREATE + rows in 1000-row transactions), then views.
 * The cursor only advances after a complete batch is on disk.
 */
final class Dumper {

	/** Rows per transaction and per batch. */
	private const BATCH = 1000;

	/** Bytes written per tick before a batch yields. */
	private const MAX_BATCH_BYTES = 8388608;

	/**
	 * @var PrefixRenamer
	 */
	private PrefixRenamer $prefixes;

	/**
	 * @var RowSelector
	 */
	private RowSelector $selector;

	/**
	 * @var array<string, array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>}>
	 */
	private array $describedCache = [];

	/**
	 * @param Connection         $db      Source database.
	 * @param DumpOptions        $options Filters and prefix.
	 * @param array<int, string> $tables  Base tables in dump order.
	 * @param array<int, string> $views   Views.
	 * @param string             $path    database.sql path.
	 * @param string             $driver  Name written to the header.
	 */
	public function __construct(
		private Connection $db,
		private DumpOptions $options,
		private array $tables,
		private array $views,
		private string $path,
		private string $driver = 'mysqli'
	) {
		$this->prefixes = $options->renamer ?? new PrefixRules( $options->basePrefix );
		$this->selector = new RowSelector( $db, $options );
	}

	public function run( DumpCursor $cursor, Deadline $deadline ): bool {
		ConnectionGuard::assertSafeCharset( $this->db );
		$out = $this->open( $cursor );
		try {
			return (bool) SessionMode::relaxed( $this->db, fn(): bool => $this->dump( $out, $cursor, $deadline ) );
		} finally {
			fclose( $out );
		}
	}

	/**
	 * @param resource   $out      Output.
	 * @param DumpCursor $cursor   Progress.
	 * @param Deadline   $deadline Time budget.
	 * @return bool True when everything is dumped.
	 */
	private function dump( $out, DumpCursor $cursor, Deadline $deadline ): bool {
		if ( 0 === $cursor->fileOffset ) {
			$this->write( $out, DumpFormat::header( $this->db->host(), $this->db->databaseName(), $this->driver ), $cursor );
		}
		$objects = count( $this->tables ) + count( $this->views );
		while ( $cursor->index < $objects ) {
			$this->step( $out, $cursor, $deadline );
			if ( $cursor->index < $objects && $deadline->reached() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param resource   $out    Output.
	 * @param DumpCursor $cursor Progress.
	 * @param Deadline   $deadline Time budget.
	 * @return void
	 */
	private function step( $out, DumpCursor $cursor, Deadline $deadline ): void {
		if ( $cursor->index >= count( $this->tables ) ) {
			$view = $this->views[ $cursor->index - count( $this->tables ) ];
			$this->write( $out, $this->viewBlock( $view ), $cursor );
			$cursor->nextTable();
			return;
		}
		$table = $this->tables[ $cursor->index ];
		if ( ! $cursor->started ) {
			$this->write( $out, $this->tableBlock( $table ), $cursor );
			$cursor->started = true;
		}
		if ( $this->batch( $out, $table, $cursor, $deadline ) ) {
			$cursor->nextTable();
		}
	}

	/**
	 * Streams one batch straight to the file; the cursor always matches the disk.
	 *
	 * @param resource   $out    Output.
	 * @param string     $table  Table.
	 * @param DumpCursor $cursor Progress.
	 * @param Deadline   $deadline Time budget.
	 * @return bool True when the table is complete.
	 */
	private function batch( $out, string $table, DumpCursor $cursor, Deadline $deadline ): bool {
		$described = $this->described( $table );
		$keyset    = $this->selector->usesKeyset( $described );
		$name      = $this->prefixes->exportTableName( $table );
		$start     = $cursor->fileOffset;
		$onRow     = function ( array $row ) use ( $out, $table, $name, $described, $keyset, $cursor, $deadline, $start ): void {
			$line  = 0 === $cursor->rows % self::BATCH ? DumpFormat::START : '';
			$line .= DumpFormat::insert( $name, $this->encodeRow( $table, $row, $described['columns'] ), [] === $described['generated'] ? [] : $this->selector->dumpedColumns( $described ) );
			if ( 0 === ( $cursor->rows + 1 ) % self::BATCH ) {
				$line .= DumpFormat::COMMIT;
			}
			$this->write( $out, $line, $cursor );
			++$cursor->rows;
			if ( $keyset ) {
				$cursor->lastKey = array_map( static fn( string $k ): string => (string) $row[ $k ], $described['keys'] );
			}
			if ( $cursor->fileOffset - $start > self::MAX_BATCH_BYTES || $deadline->reached() ) {
				throw new BatchInterrupted();
			}
		};
		try {
			$count = $this->stream( $table, $described, $cursor, $onRow );
		} catch ( BatchInterrupted $e ) {
			return false; // The cursor already matches the file; the next tick continues.
		}
		$complete = $count < self::BATCH;
		if ( $complete && 0 !== $cursor->rows % self::BATCH ) {
			$this->write( $out, DumpFormat::COMMIT, $cursor );
		}

		return $complete;
	}

	/**
	 * @param string $table Table.
	 * @return array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>}
	 */
	private function described( string $table ): array {
		if ( ! isset( $this->describedCache[ $table ] ) ) {
			$this->describedCache[ $table ] = $this->selector->describe( $table );
		}

		return $this->describedCache[ $table ];
	}

	/**
	 * @param string                                                          $table     Table.
	 * @param array{columns: array<string, string>, keys: array<int, string>, generated: array<int, string>} $described Columns and keys.
	 * @param DumpCursor                                                      $cursor    Progress.
	 * @param callable                                                        $onRow     Row callback.
	 * @throws QueryException On a server error other than a crashed table.
	 * @throws BatchInterrupted When the row callback asks to stop.
	 * @return int Rows delivered.
	 */ // phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- BatchInterrupted propagates from the row callback, not a throw here.
	private function stream( string $table, array $described, DumpCursor $cursor, callable $onRow ): int {
		$sql       = $this->selector->batchSql( $table, $described, $cursor->lastKey, $cursor->rows, self::BATCH );
		$delivered = 0;
		$counting  = static function ( array $row ) use ( $onRow, &$delivered ): void {
			++$delivered;
			$onRow( $row );
		};
		try {
			return $this->db->stream( $sql, $counting );
		} catch ( QueryException $e ) {
			if ( 1194 !== $e->errno() || $delivered > 0 ) {
				throw $e;
			}
			$this->db->execute( 'REPAIR TABLE ' . DumpFormat::quoteIdentifier( $table ) );

			return $this->db->stream( $sql, $onRow );
		}
	}

	/**
	 * @param string                     $table   Table.
	 * @param array<string, string|null> $row     Row.
	 * @param array<string, string>      $columns Column types.
	 * @return array<int, string>
	 */
	private function encodeRow( string $table, array $row, array $columns ): array {
		$values = [];
		foreach ( $row as $column => $value ) {
			$isPrefixed = $this->isPrefixed( $table, (string) $column );
			if ( $isPrefixed && null !== $value ) {
				$value = $this->prefixes->exportColumnValue( $value );
			}
			$values[] = SqlValue::encode( $value, $columns[ $column ] ?? 'longtext' );
		}

		return $values;
	}

	/**
	 * Whether a column's values start with the table prefix (options.option_name, usermeta.meta_key).
	 *
	 * @param string $table  Table.
	 * @param string $column Column.
	 * @return bool
	 */
	private function isPrefixed( string $table, string $column ): bool {
		if ( [] !== $this->options->prefixColumns ) {
			return ( $this->options->prefixColumns[ $table ] ?? null ) === $column;
		}
		$p = $this->options->basePrefix;

		return ( $table === $p . 'options' && 'option_name' === $column )
			|| ( $table === $p . 'usermeta' && 'meta_key' === $column );
	}

	private function tableBlock( string $table ): string {
		$rows   = $this->db->query( 'SHOW CREATE TABLE ' . DumpFormat::quoteIdentifier( $table ) );
		$create = (string) ( $rows[0]['Create Table'] ?? '' );
		$name   = $this->prefixes->exportTableName( $table );

		return DumpFormat::table( $name, DumpFormat::renameFirst( $create, $table, $name ) );
	}

	private function viewBlock( string $view ): string {
		$rows   = $this->db->query( 'SHOW CREATE VIEW ' . DumpFormat::quoteIdentifier( $view ) );
		$create = (string) ( $rows[0]['Create View'] ?? '' );
		$name   = $this->prefixes->exportTableName( $view );
		$create = DumpFormat::renameFirst( $create, $view, $name );
		foreach ( $this->tables as $table ) {
			$create = str_ireplace( DumpFormat::quoteIdentifier( $table ), DumpFormat::quoteIdentifier( $this->prefixes->exportTableName( $table ) ), $create );
		}
		$create = (string) preg_replace( '/CREATE(.+?)VIEW/i', 'CREATE VIEW', $create );

		return DumpFormat::view( $name, $create );
	}

	/**
	 * @param DumpCursor $cursor Progress.
	 * @throws DatabaseException If the file cannot be opened or resumed.
	 * @return resource
	 */
	private function open( DumpCursor $cursor ) {
		$out = fopen( $this->path, 'c+b' );
		if ( false === $out ) {
			throw new DatabaseException( sprintf( 'Cannot open dump file: %s', $this->path ) );
		}
		$size = (int) ( fstat( $out )['size'] ?? 0 );
		if ( $size < $cursor->fileOffset ) {
			fclose( $out );
			throw new DatabaseException( 'The dump file is shorter than the saved progress; cannot resume.' );
		}
		if ( ! ftruncate( $out, $cursor->fileOffset ) || 0 !== fseek( $out, $cursor->fileOffset ) ) {
			fclose( $out );
			throw new DatabaseException( 'Cannot position the dump file to resume.' );
		}

		return $out;
	}

	/**
	 * @param resource   $out    Output.
	 * @param string     $bytes  Data.
	 * @param DumpCursor $cursor Progress.
	 * @throws DatabaseException If writing fails.
	 * @return void
	 */
	private function write( $out, string $bytes, DumpCursor $cursor ): void {
		$length  = strlen( $bytes );
		$written = 0;
		while ( $written < $length ) {
			$n = fwrite( $out, substr( $bytes, $written ) );
			if ( false === $n || 0 === $n ) {
				throw new DatabaseException( 'Could not write the database dump. The disk may be full.' );
			}
			$written += $n;
		}
		$cursor->fileOffset += $length;
	}
}

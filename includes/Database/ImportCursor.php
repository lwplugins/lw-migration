<?php
/**
 * Progress of Importer::run().
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Byte offset of the next statement, statement count, warnings, left-out tables.
 */
final class ImportCursor {

	private const MAX_WARNINGS = 100;

	/** Names of left-out tables kept at most. */
	private const MAX_LEFT_OUT = 500;

	/** Names of left-out tables shown in the warning. */
	private const SHOWN_LEFT_OUT = 20;

	/**
	 * @param int                $offset       Next statement offset.
	 * @param int                $statements   Statements processed.
	 * @param array<int, string> $warnings     First warnings.
	 * @param int                $warningCount All warnings.
	 * @param array<int, string> $leftOut      Tables left out (ImportPlan::$leftOutPrefixes), each once.
	 * @param array<int, string> $skippedViews Views a subsite restore into a network did not bring, each once.
	 * @param array<int, string> $limited      Columns already named for a value nested too deep, each once.
	 */
	public function __construct(
		public int $offset = 0,
		public int $statements = 0,
		public array $warnings = [],
		public int $warningCount = 0,
		public array $leftOut = [],
		public array $skippedViews = [],
		public array $limited = []
	) {
	}

	public function addWarning( string $warning ): void {
		++$this->warningCount;
		if ( count( $this->warnings ) < self::MAX_WARNINGS ) {
			$this->warnings[] = $warning;
		}
	}

	public function leaveOut( string $table ): void {
		self::addOnce( $this->leftOut, $table );
	}

	public function skipView( string $view ): void {
		self::addOnce( $this->skippedViews, $view );
	}

	/**
	 * Whether a column is named for a value nested too deep for the first
	 * time in this import (repeats would crowd out the other warnings).
	 *
	 * @param string $column Column label.
	 */
	public function limitOnce( string $column ): bool {
		$before = count( $this->limited );
		self::addOnce( $this->limited, $column );

		return count( $this->limited ) > $before;
	}

	/**
	 * @return array<int, string> The skipped views a warning names.
	 */
	public function skippedViewsShown(): array {
		return array_slice( $this->skippedViews, 0, self::SHOWN_LEFT_OUT );
	}

	/** How many more skipped views the warning only counts. */
	public function skippedViewsMore(): int {
		return max( 0, count( $this->skippedViews ) - self::SHOWN_LEFT_OUT );
	}

	/**
	 * @param array<int, string> $list Names, at most MAX_LEFT_OUT.
	 * @param string             $name Name to add once.
	 */
	private static function addOnce( array &$list, string $name ): void {
		if ( count( $list ) < self::MAX_LEFT_OUT && ! in_array( $name, $list, true ) ) {
			$list[] = $name;
		}
	}

	/**
	 * @return array<int, string> The left-out tables a warning names.
	 */
	public function leftOutShown(): array {
		return array_slice( $this->leftOut, 0, self::SHOWN_LEFT_OUT );
	}

	/** How many more left-out tables the warning only counts. */
	public function leftOutMore(): int {
		return max( 0, count( $this->leftOut ) - self::SHOWN_LEFT_OUT );
	}

	/**
	 * @return array<string, mixed> Without leftOut, skippedViews and limited while they are empty (the saved shape of every other import).
	 */
	public function toArray(): array {
		$a = get_object_vars( $this );
		foreach ( [ 'leftOut', 'skippedViews', 'limited' ] as $key ) {
			if ( [] === $a[ $key ] ) {
				unset( $a[ $key ] );
			}
		}

		return $a;
	}

	/**
	 * @param array<string, mixed> $a Saved cursor.
	 * @return self
	 */
	public static function fromArray( array $a ): self {
		return new self(
			(int) ( $a['offset'] ?? 0 ),
			(int) ( $a['statements'] ?? 0 ),
			array_values( array_map( 'strval', (array) ( $a['warnings'] ?? [] ) ) ),
			(int) ( $a['warningCount'] ?? 0 ),
			array_values( array_map( 'strval', (array) ( $a['leftOut'] ?? [] ) ) ),
			array_values( array_map( 'strval', (array) ( $a['skippedViews'] ?? [] ) ) ),
			array_values( array_map( 'strval', (array) ( $a['limited'] ?? [] ) ) )
		);
	}
}

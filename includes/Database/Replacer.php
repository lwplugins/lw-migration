<?php
/**
 * Applies old → new value pairs to SQL statements.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Only statements that contain an (escaped) old value are touched; then every
 * quoted literal is unescaped, replaced serialized-aware, and re-escaped.
 * Unquoted numbers and 0x-hex binaries are never changed.
 */
final class Replacer {

	/** Columns a statement names in its warning at most; past that only its table. */
	private const MAX_LABELS = 32;

	/**
	 * Serialized-aware replacer.
	 *
	 * @var SerializedReplacer
	 */
	private SerializedReplacer $serialized;

	/** @var array<int, string> */
	private array $escapedOld = [];

	/** @var array<int, int> Ordinals of the literals of the last statement() searched only in part. */
	private array $limitedLiterals = [];

	/** @var array<int, array{table: string, column: string|null, position: int}> */
	private array $limited = [];

	/**
	 * Length of the shortest old value.
	 *
	 * @var int
	 */
	private int $minLength;

	/**
	 * @param array<string, string> $pairs    Old => new.
	 * @param array<string, string> $rawPairs Applied with strtr on the whole statement.
	 * @param BuilderValues|null    $builders Page-builder handler.
	 */
	public function __construct( private array $pairs, private array $rawPairs = [], private ?BuilderValues $builders = null ) {
		$this->serialized = new SerializedReplacer( $pairs );
		$lengths          = [];
		foreach ( array_keys( $pairs ) as $old ) {
			$this->escapedOld[] = SqlEscape::escape( (string) $old );
			$lengths[]          = strlen( (string) $old );
		}
		$this->minLength = [] === $lengths ? PHP_INT_MAX : min( $lengths );
	}

	public function isEmpty(): bool {
		return [] === $this->pairs && [] === $this->rawPairs;
	}

	public function statement( string $sql ): string {
		$this->limitedLiterals = [];
		$this->limited         = [];
		if ( $this->isEmpty() ) {
			return $sql;
		}
		$original = $sql;
		if ( null !== $this->builders && $this->builders->isActive() ) {
			$sql = $this->builders->apply( $sql, $this );
		}
		if ( [] !== $this->pairs && $this->containsOld( $sql ) ) {
			$sql = $this->literals( $sql );
		}
		$this->limited = InsertColumns::ofLiterals( $original, $this->limitedLiterals(), self::MAX_LABELS );

		return [] === $this->rawPairs ? $sql : strtr( $sql, $this->rawPairs );
	}

	/**
	 * @return array<int, int> Ordinals recorded by valueAt() during this statement().
	 */
	private function limitedLiterals(): array {
		return $this->limitedLiterals;
	}

	/**
	 * Rewrites every quoted literal: unescape, replace serialized-aware, re-escape.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	private function literals( string $sql ): string {
		return SqlLiterals::map(
			$sql,
			fn( string $raw, int $literal ): string => SqlEscape::escape( $this->valueAt( SqlEscape::unescape( $raw ), $literal ) )
		);
	}

	public function value( string $value ): string {
		return strlen( $value ) < $this->minLength ? $value : $this->serialized->replace( $value );
	}

	/**
	 * Like value(), for the $literal-th single-quoted literal of the statement;
	 * remembers it when a nested string was kept unsearched.
	 *
	 * @param string $value   Unescaped literal.
	 * @param int    $literal Its ordinal among the single-quoted literals.
	 */
	public function valueAt( string $value, int $literal ): string {
		if ( strlen( $value ) < $this->minLength ) {
			return $value;
		}
		$out = $this->serialized->replace( $value );
		if ( $this->serialized->limited() ) {
			$this->limitedLiterals[] = $literal;
		}

		return $out;
	}

	/**
	 * Where the last statement() kept part of a serialized value unsearched
	 * (SerializedReplacer::limited()): each column once, at most MAX_LABELS,
	 * then one entry with only the table (position 0).
	 *
	 * @return array<int, array{table: string, column: string|null, position: int}>
	 */
	public function limitedColumns(): array {
		return $this->limited;
	}

	public function plain( string $value ): string {
		return strlen( $value ) < $this->minLength ? $value : strtr( $value, $this->pairs );
	}

	private function containsOld( string $sql ): bool {
		foreach ( $this->escapedOld as $old ) {
			if ( str_contains( $sql, $old ) ) {
				return true;
			}
		}

		return false;
	}
}

<?php
/**
 * Ordered prefix pairs of a network dump.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Every pair is tried in order; a pair whose old prefix starts the name
 * (case-insensitively) replaces it, and the next pair sees the result — the
 * format's own rule, so a network dump names its tables exactly as the
 * established .wpress tooling does (decision 6).
 */
final class PrefixMap implements PrefixRenamer {

	/**
	 * @param array<int, array{0: string, 1: string}> $tablePairs  Table name pairs.
	 * @param array<int, array{0: string, 1: string}> $columnPairs Column value pairs (option_name, meta_key).
	 */
	public function __construct( private array $tablePairs, private array $columnPairs ) {
	}

	public function exportTableName( string $table ): string {
		return self::apply( $table, $this->tablePairs );
	}

	public function exportColumnValue( string $value ): string {
		foreach ( PrefixRules::RESERVED as $reserved ) {
			if ( 0 === stripos( $value, $reserved ) ) {
				return $value;
			}
		}

		return self::apply( $value, $this->columnPairs );
	}

	/**
	 * @param string                                  $name  Name or value.
	 * @param array<int, array{0: string, 1: string}> $pairs Pairs in order.
	 * @return string
	 */
	private static function apply( string $name, array $pairs ): string {
		foreach ( $pairs as [ $old, $new ] ) {
			if ( '' !== $old && 0 === stripos( $name, $old ) ) {
				$name = $new . substr( $name, strlen( $old ) );
			}
		}

		return $name;
	}
}

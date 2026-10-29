<?php
/**
 * Runs a rewrite on SQL while keeping quoted text untouched.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * '…', "…" and `…` are swapped for opaque placeholders (SqlQuotes, the one
 * quote tokenizer), the rewrite sees only the structural SQL (comments
 * included), and the quoted parts are put back afterwards (unless the
 * rewrite removed them). Input that cannot be scanned is returned unchanged.
 */
final class SqlSegments {

	/**
	 * Applies a rewrite to the SQL outside quotes.
	 *
	 * @param string                  $sql     Statement.
	 * @param callable(string):string $rewrite Receives the masked SQL.
	 * @return string
	 */
	public static function apply( string $sql, callable $rewrite ): string {
		$parts = SqlQuotes::maskQuotes( $sql );
		if ( null === $parts ) {
			return $sql;
		}
		[ $masked, $saved ] = $parts;
		$result             = preg_replace_callback(
			'/\x01(\d+)\x02/',
			static fn( array $m ): string => $saved[ (int) $m[1] ] ?? $m[0],
			$rewrite( $masked )
		);

		return null === $result ? $sql : $result;
	}
}

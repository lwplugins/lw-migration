<?php
/**
 * Everything the importer needs besides the connection and the file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Prefixes, replacement, schema fixes, deferred-view file, packet limit; on a
 * network also exact table names and table prefixes to leave out.
 */
final class ImportPlan {

	/** Non-site table prefixes exported alongside the site tables (also TableSwap's default). */
	public const DEFAULT_EXTRA_PREFIXES = [ 'wbk_', 'civicrm_' ];

	/**
	 * @param string                $basePrefix   Final table prefix.
	 * @param string                $tmpPrefix    Temporary table prefix.
	 * @param array<string, string> $prefixPairs  PrefixRules::importPairs().
	 * @param Replacer              $replacer     Value replacement.
	 * @param SchemaFixer           $fixer        CREATE TABLE fixes.
	 * @param string                $deferredPath File collecting view statements.
	 * @param int                   $maxPacket    Server max_allowed_packet.
	 * @param array<int, string>    $extraPrefixes Non-site table prefixes the dump keeps unprefixed (exported alongside the site tables).
	 * @param array<string, string> $exactTables  Whole placeholder table names => final names, matched before the prefix pairs (NetworkPrefixPairs).
	 * @param array<int, string>    $leftOutPrefixes Table prefixes whose statements are skipped and listed once (ImportCursor::$leftOut).
	 * @param array<int, string>    $siteSuffixes  Sites/single restore into a network: the only site suffixes ("<newId>_") tables and views may use ([] = no restriction).
	 * @param array<int, string>    $stageSuffixes Held staging suffixes allowed next to them (lwmsrc_).
	 */
	public function __construct(
		public string $basePrefix,
		public string $tmpPrefix,
		public array $prefixPairs,
		public Replacer $replacer,
		public SchemaFixer $fixer,
		public string $deferredPath,
		public int $maxPacket,
		public array $extraPrefixes = self::DEFAULT_EXTRA_PREFIXES,
		public array $exactTables = [],
		public array $leftOutPrefixes = [],
		public array $siteSuffixes = [],
		public array $stageSuffixes = []
	) {
	}
}

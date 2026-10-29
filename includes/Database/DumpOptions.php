<?php
/**
 * What goes into a database dump.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Prefix, content filters and option rows that are never exported.
 */
final class DumpOptions {

	/** Runtime status options of existing .wpress tooling: never carried into a backup. */
	public const STATUS_OPTIONS = [
		'ai1wm_status',
		'ai1wm_secret_key',
		'ai1wm_auth_user',
		'ai1wm_auth_password',
		'ai1wm_auth_header',
		'ai1wm_backups_labels',
		'ai1wm_sites_links',
	];

	/**
	 * @param string                     $basePrefix             Table prefix of the site.
	 * @param bool                       $noSpamComments         Skip spam comments and their meta.
	 * @param bool                       $noPostRevisions        Skip revisions and their meta.
	 * @param array<int, string>         $excludedOptions        Option names never exported.
	 * @param array<int, string>         $excludedOptionPrefixes Option name prefixes never exported.
	 * @param PrefixRenamer|null         $renamer                Network dump: the scope's prefix pairs; null = PrefixRules of $basePrefix.
	 * @param array<string, string>      $prefixColumns          Network dump: table => column whose values carry the prefix; [] = options/usermeta of $basePrefix.
	 * @param array<string, string>|null $tableWhere             Network dump: table => WHERE condition (built by the scope); null = the single-site filters.
	 * @param array<int, string>         $optionsTables          Network dump: options tables that get the plugin/theme override; [] = $basePrefix options.
	 */
	public function __construct(
		public string $basePrefix,
		public bool $noSpamComments = false,
		public bool $noPostRevisions = false,
		public array $excludedOptions = self::STATUS_OPTIONS,
		public array $excludedOptionPrefixes = [],
		public ?PrefixRenamer $renamer = null,
		public array $prefixColumns = [],
		public ?array $tableWhere = null,
		public array $optionsTables = []
	) {
	}

	/**
	 * @return array<int, string> Tables whose option_value gets the active_plugins/template/stylesheet override.
	 */
	public function optionsTables(): array {
		return [] === $this->optionsTables ? [ $this->basePrefix . 'options' ] : $this->optionsTables;
	}
}

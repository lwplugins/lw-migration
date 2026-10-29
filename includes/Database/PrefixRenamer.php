<?php
/**
 * Renames prefixes in a dump.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Export side: real prefix → placeholder, for table names and prefixed column values.
 */
interface PrefixRenamer {

	/**
	 * @param string $table Real table (or view) name.
	 * @return string Name written to the dump.
	 */
	public function exportTableName( string $table ): string;

	/**
	 * @param string $value An options.option_name or usermeta.meta_key value.
	 * @return string Value written to the dump.
	 */
	public function exportColumnValue( string $value ): string;
}

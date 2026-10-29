<?php
/**
 * Option name prefixes are excluded from dumps, matched literally.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Database\RowSelector;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;

final class OptionPrefixExclusionTest extends DatabaseTestCase {

	public function test_options_with_excluded_prefixes_are_not_selected(): void {
		$this->exec(
			"CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')",
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','x'),('lw_migration_lock','l'),('_transient_lw_migration_job_secret_ab','hunter2'),('_transient_timeout_lw_migration_job_secret_ab','1'),('lwXmigration_other','keep'),('ai1wm_status','s')"
		);
		$options = new DumpOptions( 'wp_', false, false, DumpOptions::STATUS_OPTIONS, [ 'lw_migration_', '_transient_lw_migration_', '_transient_timeout_lw_migration_' ] );

		$this->assertSame( [ 'lwXmigration_other', 'siteurl' ], $this->selectedNames( $options ), '_ in a prefix is literal, not a LIKE wildcard.' );
	}

	public function test_percent_and_backslash_in_a_prefix_are_literal(): void {
		$this->exec(
			"CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')",
			"INSERT INTO wp_options (option_name, option_value) VALUES ('a%b_x','1'),('aZZb_x','2'),('a\\\\b_y','3'),('aQb_y','4'),('keep','5')"
		);
		$options = new DumpOptions( 'wp_', false, false, [], [ 'a%b', 'a\\b' ] );

		$this->assertSame( [ 'aQb_y', 'aZZb_x', 'keep' ], $this->selectedNames( $options ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function selectedNames( DumpOptions $options ): array {
		$selector = new RowSelector( self::db(), $options );
		$rows     = self::db()->query( $selector->batchSql( 'wp_options', $selector->describe( 'wp_options' ), null, 0, 100 ) );
		$names    = array_column( $rows, 'option_name' );
		sort( $names );

		return $names;
	}
}

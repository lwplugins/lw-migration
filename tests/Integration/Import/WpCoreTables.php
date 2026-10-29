<?php
/**
 * WordPress core table DDL for integration tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Import;

final class WpCoreTables {

	/** @return array<int, string> CREATE TABLE statements for options, users, usermeta with a prefix (engine: server default when empty). */
	public static function create( string $prefix, string $engine = '' ): array {
		$suffix = '' === $engine ? '' : " ENGINE={$engine}";
		return array_map( static fn( string $sql ): string => $sql . $suffix, self::ddl( $prefix ) );
	}

	/** @return array<int, string> */
	private static function ddl( string $prefix ): array {
		return [
			"CREATE TABLE `{$prefix}options` (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')",
			"CREATE TABLE `{$prefix}users` (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_login VARCHAR(60) NOT NULL DEFAULT '', user_pass VARCHAR(255) NOT NULL DEFAULT '', user_nicename VARCHAR(50) NOT NULL DEFAULT '', user_email VARCHAR(100) NOT NULL DEFAULT '', user_url VARCHAR(100) NOT NULL DEFAULT '', user_registered DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00', user_activation_key VARCHAR(255) NOT NULL DEFAULT '', user_status INT NOT NULL DEFAULT 0, display_name VARCHAR(250) NOT NULL DEFAULT '', KEY user_login_key (user_login))",
			"CREATE TABLE `{$prefix}usermeta` (umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)",
		];
	}
}

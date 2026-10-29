<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\ImportScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Column defaults come from an allow-list (literals, NULL, the current date
 * and time, UUID() and UUID_TO_BIN(UUID())); generated columns, CHECK
 * constraints call only pure built-ins (string, number, JSON reading, NULL
 * handling); a functional key part is refused.
 */
final class CreateTableExpressionsTest extends TestCase {

	private const WP_POSTS = "CREATE TABLE `lwt_posts` (\n  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `post_author` bigint unsigned NOT NULL DEFAULT '0',\n  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\n  `post_content` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,\n  `post_title` text COLLATE utf8mb4_unicode_520_ci NOT NULL,\n  `post_status` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'publish',\n  `post_password` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',\n  `post_parent` bigint unsigned NOT NULL DEFAULT '0',\n  `menu_order` int NOT NULL DEFAULT '0',\n  `post_type` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'post',\n  `comment_count` bigint NOT NULL DEFAULT '0',\n  PRIMARY KEY (`ID`),\n  KEY `post_name` (`post_name`(191)),\n  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),\n  KEY `post_parent` (`post_parent`)\n) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

	private const WP_OPTIONS = "CREATE TABLE `lwt_options` (\n  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `option_name` varchar(191) NOT NULL DEFAULT '',\n  `option_value` longtext NOT NULL,\n  `autoload` varchar(20) NOT NULL DEFAULT 'yes',\n  PRIMARY KEY (`option_id`),\n  UNIQUE KEY `option_name` (`option_name`),\n  KEY `autoload` (`autoload`)\n) ENGINE=InnoDB AUTO_INCREMENT=200 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;";

	private const WC_STYLE = "CREATE TABLE `lwt_wc_orders_meta_log` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `order_id` bigint(20) unsigned DEFAULT NULL,\n  `status` varchar(20) DEFAULT NULL,\n  `total_amount` decimal(26,8) DEFAULT NULL,\n  `date_created_gmt` datetime DEFAULT NULL,\n  `last_modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  `precise` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),\n  PRIMARY KEY (`id`),\n  KEY `order_id` (`order_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function allowed(): array {
		$t = static fn( string $column ): string => "CREATE TABLE `lwt_x` (\n  `id` int NOT NULL,\n  {$column},\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";

		return [
			'wp_posts'                => [ self::WP_POSTS ],
			'wp_options'              => [ self::WP_OPTIONS ],
			'woocommerce style'       => [ self::WC_STYLE ],
			'mariadb timestamps'      => [ $t( '`u` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()' ) ],
			'every time function'     => [ $t( '`a` datetime DEFAULT NOW(), `b` datetime DEFAULT LOCALTIME, `c` datetime DEFAULT LOCALTIMESTAMP(3), `d` date DEFAULT curdate(), `e` time DEFAULT CURTIME, `f` date DEFAULT utc_date(), `g` time DEFAULT UTC_TIME(2), `h` datetime DEFAULT UTC_TIMESTAMP' ) ],
			'expression time'         => [ $t( '`d` date DEFAULT (curdate())' ) ],
			'uuid'                    => [ $t( '`u` char(36) NOT NULL DEFAULT uuid()' ) ],
			'expression uuid'         => [ $t( '`u` varchar(36) DEFAULT (uuid())' ) ],
			'uuid_to_bin'             => [ $t( '`u` binary(16) DEFAULT (uuid_to_bin(uuid()))' ) ],
			'uuid_to_bin swapped'     => [ $t( '`u` binary(16) DEFAULT (UUID_TO_BIN(UUID(), 1))' ) ],
			'expression literal'      => [ $t( "`s` varchar(9) DEFAULT (_utf8mb4'abc')" ) ],
			'literals'                => [ $t( "`a` int DEFAULT -1, `b` decimal(5,2) DEFAULT 1.50, `c` double DEFAULT 1e3, `d` varbinary(4) DEFAULT 0x1f, `e` bit(2) DEFAULT b'01', `f` varchar(9) DEFAULT N'x', `g` varbinary(2) DEFAULT X'0a', `h` int DEFAULT NULL, `i` tinyint(1) DEFAULT TRUE, `j` varchar(9) DEFAULT 'a' COMMENT 'b'" ) ],
			'mariadb json check'      => [ $t( '`data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`))' ) ],
			'check with operators'    => [ $t( "`n` int DEFAULT 0, CONSTRAINT `c` CHECK (((`n` >= 0) and (`n` < 100) or `n` is null))" ) ],
			'generated with operator' => [ $t( '`a` int DEFAULT NULL, `b` int GENERATED ALWAYS AS ((`a` * 2)) VIRTUAL' ) ],
			'generated concat'        => [ $t( "`a` int NOT NULL, `b` int NOT NULL, `s` varchar(20) GENERATED ALWAYS AS (concat(`a`,_utf8mb4'-',`b`)) STORED" ) ],
			'generated json path'     => [ $t( "`a` json DEFAULT NULL, `b` varchar(9) GENERATED ALWAYS AS (json_unquote(json_extract(`a`,_utf8mb4'\$.x'))) VIRTUAL" ) ],
			'mariadb generated'       => [ $t( "`a` int NOT NULL, `s` varchar(20) GENERATED ALWAYS AS (concat(`a`,'-')) STORED, `l` varchar(20) AS (lower(`s`)) VIRTUAL" ) ],
		];
	}

	#[DataProvider( 'allowed' )]
	public function test_wordpress_style_defaults_are_accepted( string $sql ): void {
		$this->assertTrue( ( new ImportScope( 'wp_', 'lwt_' ) )->isSafeCreate( $sql ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refusedDefaults(): array {
		$t = static fn( string $column ): string => "CREATE TABLE `lwt_x` (\n  `id` int NOT NULL,\n  {$column},\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";

		return [
			'sleep expression'      => [ $t( '`a` int DEFAULT (sleep(5))' ) ],
			'sleep bare'            => [ $t( '`a` int DEFAULT sleep(5)' ) ],
			'load_file'             => [ $t( "`a` longtext DEFAULT (load_file('/etc/passwd'))" ) ],
			'user'                  => [ $t( '`a` varchar(99) DEFAULT (current_user())' ) ],
			'arithmetic'            => [ $t( '`a` int DEFAULT (1 + 2)' ) ],
			'time arithmetic'       => [ $t( '`a` datetime DEFAULT (now() + interval 1 day)' ) ],
			'operator after value'  => [ $t( '`a` int DEFAULT 1 OR sleep(1)' ) ],
			'sequence'              => [ $t( '`a` int DEFAULT nextval(`lwt_seq`)' ) ],
			'stored function'       => [ $t( '`a` int DEFAULT (`lwm`.`f`())' ) ],
			'column reference'      => [ $t( '`a` int DEFAULT (`id`)' ) ],
			'uuid_to_bin bad flag'  => [ $t( '`a` binary(16) DEFAULT (uuid_to_bin(uuid(), 2))' ) ],
			'uuid_to_bin of string' => [ $t( "`a` binary(16) DEFAULT (uuid_to_bin('x'))" ) ],
			'two parentheses'       => [ $t( '`a` int DEFAULT ((sleep(1)))' ) ],
			'on update function'    => [ $t( '`a` timestamp NULL DEFAULT NULL ON UPDATE sleep(1)' ) ],
			'upper hex prefix'      => [ $t( '`a` varbinary(4) DEFAULT 0X1f' ) ],
		];
	}

	#[DataProvider( 'refusedDefaults' )]
	public function test_a_default_outside_the_allow_list_is_refused( string $sql ): void {
		$this->assertFalse( ( new ImportScope( 'wp_', 'lwt_' ) )->isSafeCreate( $sql ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refusedExpressions(): array {
		$t = static fn( string $column ): string => "CREATE TABLE `lwt_x` (\n  `id` int NOT NULL,\n  `a` varchar(99) DEFAULT NULL,\n  {$column},\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";

		return [
			'generated function'  => [ $t( "`b` longtext GENERATED ALWAYS AS (load_file('/etc/passwd')) STORED" ) ],
			'short generated'     => [ $t( '`b` int AS (sleep(1)) VIRTUAL' ) ],
			'generated time'      => [ $t( '`b` datetime GENERATED ALWAYS AS (now()) VIRTUAL' ) ],
			'pure around impure'  => [ $t( '`b` varchar(99) GENERATED ALWAYS AS (concat(`a`,sleep(1))) VIRTUAL' ) ],
			'check json read'     => [ $t( "CHECK (json_valid(load_file('/etc/passwd')))" ) ],
			'check function'      => [ $t( 'CONSTRAINT `c` CHECK ((`a` = user()))' ) ],
			'check stored fn'     => [ $t( 'CHECK (`lwm`.`f`(`a`))' ) ],
			'check backtick call' => [ $t( 'CHECK (`f` (`a`))' ) ],
			'check variable'      => [ $t( 'CHECK (`a` = @x)' ) ],
			'functional key'      => [ $t( 'KEY `k` ((lower(`a`)))' ) ],
			'functional key part' => [ $t( 'KEY `k` (`id`,(sleep(1)))' ) ],
			'after the list'      => [ 'CREATE TABLE `lwt_x` (`a` int DEFAULT 1) , b int DEFAULT (sleep(1)))' ],
			'unclosed list'       => [ 'CREATE TABLE `lwt_x` (`a` int DEFAULT 1' ],
		];
	}

	#[DataProvider( 'refusedExpressions' )]
	public function test_a_generated_column_check_or_key_part_calls_no_impure_function( string $sql ): void {
		$this->assertFalse( ( new ImportScope( 'wp_', 'lwt_' ) )->isSafeCreate( $sql ) );
	}
}

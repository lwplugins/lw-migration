<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\TableSwap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableSwapExtraTest extends TestCase {

	/**
	 * @param array<int, string> $tables Base tables the fake server lists.
	 * @return Connection&object{log: array<int, string>}
	 */
	private static function db( array $tables ): Connection {
		return new class( $tables ) implements Connection {
			/** @var array<int, string> */
			public array $log = [];

			/**
			 * @param array<int, string> $tables Base tables.
			 */
			public function __construct( private array $tables ) {
			}

			public function query( string $sql ): array {
				return array_map( static fn( string $t ): array => [ 'T' => $t ], $this->tables );
			}

			public function stream( string $sql, callable $onRow ): int {
				return 0;
			}

			public function execute( string $sql ): void {
				$this->log[] = $sql;
			}

			public function escape( string $value ): string {
				return $value;
			}

			public function serverVersion(): string {
				return '8.0.36';
			}

			public function variable( string $name ): ?string {
				return '0';
			}

			public function databaseName(): string {
				return 'fake';
			}

			public function host(): string {
				return 'localhost';
			}
		};
	}

	public function test_extra_prefix_tables_swap_to_their_own_names_in_one_rename(): void {
		$db     = self::db( [ 'wp_options', 'wbk_bookings', 'lwtab12_options', 'lwxab12_wbk_bookings', 'lwxab12_civicrm_contact' ] );
		$result = ( new TableSwap( $db, 'lwtab12_', 'wp_', [ 'wbk_', 'civicrm_' ] ) )->swap();

		$this->assertSame( [ 'wp_options', 'wbk_bookings', 'civicrm_contact' ], $result->finals );
		$this->assertSame( [], $result->warnings );
		$this->assertSame(
			'RENAME TABLE `wp_options` TO `lwoab12_options`, `lwtab12_options` TO `wp_options`, '
			. '`wbk_bookings` TO `lwyab12_wbk_bookings`, `lwxab12_wbk_bookings` TO `wbk_bookings`, '
			. '`lwxab12_civicrm_contact` TO `civicrm_contact`',
			$db->log[0]
		);
		$this->assertSame( [ 'DROP TABLE IF EXISTS `lwoab12_options`', 'DROP TABLE IF EXISTS `lwyab12_wbk_bookings`' ], array_slice( $db->log, 1 ) );
	}

	public function test_extra_temp_table_without_an_allowed_prefix_is_skipped_with_a_warning(): void {
		$db     = self::db( [ 'lwxab12_wp_users', 'lwxab12_wbk_a' ] );
		$result = ( new TableSwap( $db, 'lwtab12_', 'wp_', [ 'wbk_' ] ) )->swap();

		$this->assertSame( [ 'wbk_a' ], $result->finals );
		$this->assertCount( 1, $result->warnings );
		$this->assertStringContainsString( 'lwxab12_wp_users', $result->warnings[0] );
		$this->assertSame( 'RENAME TABLE `lwxab12_wbk_a` TO `wbk_a`', $db->log[0] );
	}

	public function test_without_extra_prefixes_every_extra_temp_table_is_skipped(): void {
		$db     = self::db( [ 'lwxab12_wbk_a' ] );
		$result = ( new TableSwap( $db, 'lwtab12_', 'wp_', [] ) )->swap();

		$this->assertSame( [], $result->finals );
		$this->assertCount( 1, $result->warnings );
		$this->assertSame( [], $db->log );
	}

	public function test_cleanup_drops_all_four_temporary_prefixes_of_this_run(): void {
		$db = self::db( [ 'lwtab12_a', 'lwxab12_wbk_b', 'lwoab12_c', 'lwyab12_wbk_d', 'lwtff00_e', 'wbk_f' ] );
		( new TableSwap( $db, 'lwtab12_', 'wp_', [ 'wbk_' ] ) )->cleanup();

		$this->assertSame(
			[
				'DROP TABLE IF EXISTS `lwtab12_a`',
				'DROP TABLE IF EXISTS `lwxab12_wbk_b`',
				'DROP TABLE IF EXISTS `lwoab12_c`',
				'DROP TABLE IF EXISTS `lwyab12_wbk_d`',
			],
			$db->log
		);
	}

	/**
	 * @return array<string, array{0: array<int, string>}>
	 */
	public static function badExtraPrefixes(): array {
		return [
			'empty'         => [ [ '' ] ],
			'no underscore' => [ [ 'wbk' ] ],
			'backtick'      => [ [ 'wb`k_' ] ],
			'wildcard'      => [ [ 'w%_' ] ],
			'temp prefix'   => [ [ 'lwt_' ] ],
		];
	}

	/**
	 * @param array<int, string> $extra Extra prefixes.
	 */
	#[DataProvider( 'badExtraPrefixes' )]
	public function test_invalid_extra_prefixes_are_refused( array $extra ): void {
		$this->expectException( DatabaseException::class );
		new TableSwap( self::db( [] ), 'lwtab12_', 'wp_', $extra );
	}

	public function test_default_extra_prefixes_match_the_import_plan(): void {
		$db     = self::db( [ 'lwxab12_wbk_a', 'lwxab12_civicrm_b' ] );
		$result = ( new TableSwap( $db, 'lwtab12_', 'wp_' ) )->swap();

		$this->assertSame( [ 'wbk_a', 'civicrm_b' ], $result->finals );
		$this->assertSame( [], $result->warnings );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\TableSwap;
use PHPUnit\Framework\TestCase;

final class TableSwapCaseTest extends TestCase {

	public function test_existing_table_is_found_case_insensitively_when_server_lowercases(): void {
		$db = new class() implements Connection {
			/** @var array<int, string> */
			public array $log = [];

			public function query( string $sql ): array {
				return [ [ 'T' => 'wp_options' ], [ 'T' => 'lwtab12_options' ] ];
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
				return '1';
			}

			public function databaseName(): string {
				return 'fake';
			}

			public function host(): string {
				return 'localhost';
			}
		};
		$result = ( new TableSwap( $db, 'lwtab12_', 'WP_' ) )->swap();

		$this->assertSame( [ 'WP_options' ], $result->finals );
		$this->assertSame(
			'RENAME TABLE `WP_options` TO `lwoab12_options`, `lwtab12_options` TO `WP_options`',
			$db->log[0]
		);
	}
}

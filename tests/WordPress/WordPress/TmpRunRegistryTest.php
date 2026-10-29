<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\WordPress;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;

final class TmpRunRegistryTest extends MonkeyTestCase {

	public function test_registers_and_forgets_runs_in_a_private_option_row(): void {
		$db       = FakeWpdb::install();
		$registry = new TmpRunRegistry();

		$this->assertTrue( $registry->register( 'ab12', 100 ) );
		$this->assertTrue( $registry->register( 'cd34', 200 ) );
		$this->assertTrue( $registry->register( 'ab12', 300 ), 'Registering again keeps the first time.' );

		$this->assertSame( [ 'ab12' => 100, 'cd34' => 200 ], $registry->all() );
		$this->assertSame( 'off', $db->rows[ Options::TMP_RUNS ]['autoload'] );
		$this->assertStringStartsWith( 'lw_migration_', Options::TMP_RUNS, 'Excluded from dumps, carried over by the swap.' );

		$this->assertTrue( $registry->forget( 'ab12' ) );
		$this->assertSame( [ 'cd34' => 200 ], $registry->all() );
		$this->assertTrue( $registry->forget( 'cd34' ) );
		$this->assertArrayNotHasKey( Options::TMP_RUNS, $db->rows, 'The empty registry row is removed.' );
		$this->assertTrue( $registry->forget( 'ef56' ), 'Forgetting an unknown run is a no-op.' );
	}

	public function test_a_concurrent_change_is_merged_not_lost(): void {
		$db       = FakeWpdb::install( [ Options::TMP_RUNS => '{"ab12":100}' ] );
		$registry = new TmpRunRegistry();
		$db->beforeWrite = static function () use ( $db ): void {
			$db->rows[ Options::TMP_RUNS ]['value'] = '{"ab12":100,"zz99":150}';
		};

		$this->assertTrue( $registry->register( 'cd34', 200 ) );

		$this->assertSame( [ 'ab12' => 100, 'zz99' => 150, 'cd34' => 200 ], $registry->all() );
	}

	public function test_invalid_ids_and_a_damaged_row(): void {
		$db       = FakeWpdb::install( [ Options::TMP_RUNS => 'not json' ] );
		$registry = new TmpRunRegistry();

		$this->assertSame( [], $registry->all() );
		$this->assertFalse( $registry->register( 'AB12', 1 ) );
		$this->assertFalse( $registry->register( 'ab1', 1 ) );
		$this->assertTrue( $registry->register( 'ab12', 1 ) );
		$this->assertSame( [ 'ab12' => 1 ], $registry->all() );

		$this->assertTrue( $registry->register( '1234', 2 ) );
		$this->assertSame( [ 'ab12', '1234' ], $registry->ids(), 'A digits-only id stays a string.' );

		$db->failWrites = true;
		$this->assertFalse( $registry->register( 'cd34', 1 ), 'A write that never lands is reported.' );
	}
}

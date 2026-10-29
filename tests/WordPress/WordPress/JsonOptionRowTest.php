<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\WordPress;

use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\JsonOptionRow;

final class JsonOptionRowTest extends MonkeyTestCase {

	public function test_creates_the_row_then_swaps_it_and_reads_it_back(): void {
		$db  = FakeWpdb::install();
		$row = new JsonOptionRow( 'lw_migration_x' );

		$this->assertNull( $row->get() );
		$this->assertTrue( $row->change( static fn( $current ): array => [ 'a/b' => 1 ] ) );
		$this->assertTrue( $row->change( static fn( $current ): array => $current + [ 'c' => 2 ] ) );

		$this->assertSame( '{"a/b":1,"c":2}', $db->value( 'lw_migration_x' ) );
		$this->assertSame( 'off', $db->rows['lw_migration_x']['autoload'] );
		$this->assertSame( [ 'a/b' => 1, 'c' => 2 ], $row->get() );
	}

	public function test_a_change_that_throws_or_answers_null_writes_nothing(): void {
		$db  = FakeWpdb::install( [ 'lw_migration_x' => '[1]' ] );
		$row = new JsonOptionRow( 'lw_migration_x' );

		$this->assertTrue( $row->change( static fn( $current ): ?array => null ) );
		try {
			$row->change(
				static function (): array {
					throw new \LengthException( 'no' );
				}
			);
			$this->fail( 'The exception reaches the caller.' );
		} catch ( \LengthException $e ) {
			$this->assertSame( '[1]', $db->value( 'lw_migration_x' ) );
		}
	}

	public function test_a_row_that_is_not_json_reads_null_and_a_failed_write_answers_false(): void {
		$db  = FakeWpdb::install( [ 'lw_migration_x' => 'a:1:{i:0;i:1;}' ] );
		$row = new JsonOptionRow( 'lw_migration_x' );

		$this->assertNull( $row->get() );
		$db->failWrites = true;
		$this->assertFalse( $row->change( static fn( $current ): array => [ 2 ] ) );
		$this->assertSame( 'a:1:{i:0;i:1;}', $db->value( 'lw_migration_x' ) );
	}

	public function test_delete_removes_the_row_and_a_missing_row_counts_as_deleted(): void {
		$db  = FakeWpdb::install( [ 'lw_migration_x' => '[1]' ] );
		$row = new JsonOptionRow( 'lw_migration_x' );
		// Another request changes the row between the read and the delete: the delete tries again.
		$db->beforeWrite = static function () use ( $db ): void {
			$db->rows['lw_migration_x']['value'] = '[2]';
		};

		$this->assertTrue( $row->delete() );
		$this->assertNull( $db->value( 'lw_migration_x' ) );
		$this->assertTrue( $row->delete(), 'Nothing to delete is no failure.' );
	}

	public function test_a_delete_that_cannot_write_answers_false(): void {
		$db             = FakeWpdb::install( [ 'lw_migration_x' => '[1]' ] );
		$db->failWrites = true;

		$this->assertFalse( ( new JsonOptionRow( 'lw_migration_x' ) )->delete() );
		$this->assertSame( '[1]', $db->value( 'lw_migration_x' ) );
	}
}

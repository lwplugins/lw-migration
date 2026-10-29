<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\ImportCursor;
use PHPUnit\Framework\TestCase;

final class ImportCursorTest extends TestCase {

	public function test_a_cursor_without_left_out_tables_keeps_its_saved_shape(): void {
		$this->assertSame( [ 'offset', 'statements', 'warnings', 'warningCount' ], array_keys( ( new ImportCursor( 5, 2 ) )->toArray() ) );
	}

	public function test_left_out_tables_are_listed_once_and_survive_a_save(): void {
		$cursor = new ImportCursor();
		foreach ( [ 'wbk_a', 'wbk_b', 'wbk_a' ] as $table ) {
			$cursor->leaveOut( $table );
		}

		$again = ImportCursor::fromArray( $cursor->toArray() );

		$this->assertSame( [ 'wbk_a', 'wbk_b' ], $again->leftOut );
		$this->assertSame( 0, $again->warningCount, 'A left-out table is reported once by the step, not per statement.' );
	}

	public function test_the_list_of_left_out_tables_is_bounded(): void {
		$cursor = new ImportCursor();
		for ( $i = 0; $i < 600; $i++ ) {
			$cursor->leaveOut( 'wbk_' . $i );
		}

		$this->assertCount( 500, $cursor->leftOut );
	}

	public function test_a_long_list_of_left_out_tables_counts_the_rest(): void {
		$cursor = new ImportCursor();
		for ( $i = 0; $i < 30; $i++ ) {
			$cursor->leaveOut( 'wbk_' . $i );
		}

		$this->assertSame( 'wbk_0, wbk_1, wbk_2, wbk_3, wbk_4, wbk_5, wbk_6, wbk_7, wbk_8, wbk_9, wbk_10, wbk_11, wbk_12, wbk_13, wbk_14, wbk_15, wbk_16, wbk_17, wbk_18, wbk_19', implode( ', ', $cursor->leftOutShown() ) );
		$this->assertSame( 10, $cursor->leftOutMore() );
	}

	public function test_skipped_views_are_listed_once_bounded_and_counted(): void {
		$cursor = new ImportCursor();
		$this->assertArrayNotHasKey( 'skippedViews', $cursor->toArray() );
		for ( $i = 0; $i < 25; $i++ ) {
			$cursor->skipView( 'wp_7_v' . $i );
			$cursor->skipView( 'wp_7_v' . $i );
		}

		$again = ImportCursor::fromArray( $cursor->toArray() );

		$this->assertCount( 25, $again->skippedViews );
		$this->assertCount( 20, $again->skippedViewsShown() );
		$this->assertSame( 5, $again->skippedViewsMore() );
	}
}

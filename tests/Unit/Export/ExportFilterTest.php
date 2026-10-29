<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Export;

use LightweightPlugins\Migration\Export\ExportFilter;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ExportFilterTest extends TestCase {

	public function test_equal_or_below_only(): void {
		$filter = new ExportFilter( [ '/s/wp-content/cache' ] );

		$this->assertTrue( $filter->excludes( '/s/wp-content/cache' ) );
		$this->assertTrue( $filter->excludes( '/s/wp-content/cache/page.html' ) );
		$this->assertFalse( $filter->excludes( '/s/wp-content/cache2/page.html' ) );
		$this->assertFalse( $filter->excludes( '/s/wp-content' ) );
	}

	public function test_repeated_slashes_and_blanks_are_normalized(): void {
		$filter = new ExportFilter( [ ' /s//wp-content///cache/ ', '', '   ' ] );

		$this->assertTrue( $filter->excludes( '/s/wp-content/cache/x' ) );
		$this->assertTrue( $filter->excludes( '/s//wp-content/cache//x' ) );
		$this->assertFalse( $filter->excludes( '/s/wp-content/other' ) );
	}

	public function test_excluding_a_symlink_keeps_the_folder_it_points_to(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/real/target', 0755, true );
			symlink( $dir . '/real/target', $dir . '/real/alias' );
			symlink( $dir . '/real', $dir . '/real-alias' );

			$filter = new ExportFilter( [ $dir . '/real-alias/alias' ] );

			$this->assertTrue( $filter->excludes( $dir . '/real-alias/alias/x' ) );
			$this->assertTrue( $filter->excludes( $dir . '/real/alias/x' ), 'parent folder resolved' );
			$this->assertFalse( $filter->excludes( $dir . '/real/target/x' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_an_excluded_folder_is_also_excluded_by_its_real_path(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/real/cache', 0755, true );
			symlink( $dir . '/real', $dir . '/content' );

			$filter = new ExportFilter( [ $dir . '/content/cache' ] );

			$this->assertTrue( $filter->excludes( $dir . '/real/cache/x' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}
}

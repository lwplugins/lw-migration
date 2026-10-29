<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Export;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ExportFilesTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_remove_deletes_the_work_files_and_the_list_side_file_only(): void {
		$work = [ ExportFiles::ARCHIVE, ExportFiles::LIST, ExportFiles::LIST . FileEnumerator::VISITED_SUFFIX, ExportFiles::DATABASE, ExportFiles::NETWORK_SITES ];
		foreach ( array_merge( $work, [ 'job.json' ] ) as $name ) {
			file_put_contents( $this->dir . '/' . $name, 'x' );
		}

		ExportFiles::remove( $this->dir );
		ExportFiles::remove( $this->dir );

		foreach ( $work as $name ) {
			$this->assertFileDoesNotExist( $this->dir . '/' . $name );
		}
		$this->assertFileExists( $this->dir . '/job.json' );
		$this->assertSame( Format::DATABASE, ExportFiles::DATABASE );
	}

	public function test_remove_in_a_read_only_folder_keeps_the_files_without_a_warning(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Needs POSIX permissions that bind the current user.' );
		}
		file_put_contents( $this->dir . '/' . ExportFiles::ARCHIVE, 'x' );
		chmod( $this->dir, 0555 );
		try {
			ExportFiles::remove( $this->dir );

			$this->assertFileExists( $this->dir . '/' . ExportFiles::ARCHIVE );
		} finally {
			chmod( $this->dir, 0755 );
		}
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\CLI\ExtractCommand;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ExtractCommandTest extends MonkeyTestCase {

	#[RunInSeparateProcess]
	public function test_entry_names_reach_the_terminal_without_control_characters(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		FakeWpdb::install();
		FakeOptions::install();
		$dir     = TempDir::create();
		$archive = $dir . '/a.wpress';
		$writer  = new Writer( $archive );
		$writer->addString( 'package.json', '{}', 1 );
		$writer->addString( "../\x1b]52;c;QUJD\x07ignored.txt", 'evil', 1 );
		$writer->addString( "kept\x1b[2K\x1b[1A.txt", 'new', 1 );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		mkdir( $dir . '/out' );
		file_put_contents( $dir . "/out/kept\x1b[2K\x1b[1A.txt", 'mine' );

		try {
			( new ExtractCommand() )( [ $archive ], [ 'dir' => $dir . '/out' ] );

			$this->assertSame(
				[
					'Warning: Unsafe path in archive: ../\x1b]52;c;QUJD\x07ignored.txt',
					'Warning: Kept existing file kept\x1b[2K\x1b[1A.txt (use --force to overwrite)',
					sprintf( 'Success: Extracted 1 files into %s/out, skipped 2.', $dir ),
				],
				\WP_CLI::$lines
			);
		} finally {
			TempDir::remove( $dir );
		}
	}
}

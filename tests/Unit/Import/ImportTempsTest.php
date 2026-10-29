<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportTemps;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ImportTempsTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/work' );
		mkdir( $this->dir . '/uploads/2026', 0755, true );
		mkdir( $this->dir . '/outside' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function file( string $path, int $mtime ): string {
		file_put_contents( $path, 'x' );
		touch( $path, $mtime );
		return $path;
	}

	public function test_sweeps_only_our_temporary_files_in_journaled_folders(): void {
		$now     = time();
		$uploads = $this->dir . '/uploads';
		ImportTemps::record( $this->dir . '/work', 'uploads', '2026' );
		ImportTemps::record( $this->dir . '/work', 'uploads', '2026' );
		ImportTemps::record( $this->dir . '/work', 'uploads', '' );
		file_put_contents( $this->dir . '/work/' . ImportFiles::EXTRACT_DIRS, "uploads|../outside\nnope|2026\nbroken\n", FILE_APPEND );

		$ours      = $this->file( $uploads . '/2026/' . TempName::name(), $now );
		$top       = $this->file( $uploads . '/' . TempName::name(), $now );
		$stamped   = $this->file( $uploads . '/2026/' . TempName::name(), 1700000000 ); // Entry time set before the rename.
		$named     = $this->file( $uploads . '/2026/x' . TempName::name(), $now );
		$outside   = $this->file( $this->dir . '/outside/' . TempName::name(), $now );
		$link      = $uploads . '/2026/' . TempName::name();
		$linkedTo  = $this->file( $this->dir . '/outside/target.txt', $now );
		symlink( $linkedTo, $link );
		mkdir( $uploads . '/2026/deeper' );
		$deeper = $this->file( $uploads . '/2026/deeper/' . TempName::name(), $now );

		$this->assertSame( 3, ImportTemps::sweep( $this->dir . '/work', [ 'uploads' => $uploads ], $now - 60 ) );
		$this->assertFileDoesNotExist( $ours );
		$this->assertFileDoesNotExist( $top );
		$this->assertFileDoesNotExist( $stamped, 'Created during the job, whatever its modification time says.' );
		foreach ( [ $named, $outside, $linkedTo, $deeper ] as $kept ) {
			$this->assertFileExists( $kept );
		}
		$this->assertTrue( is_link( $link ) );
	}

	public function test_files_created_before_the_job_are_kept(): void {
		$uploads = $this->dir . '/uploads';
		ImportTemps::record( $this->dir . '/work', 'uploads', '' );
		$foreign = $this->file( $uploads . '/' . TempName::name(), time() );

		$this->assertSame( 0, ImportTemps::sweep( $this->dir . '/work', [ 'uploads' => $uploads ], time() + 1 ) );
		$this->assertFileExists( $foreign );
	}

	public function test_without_a_journal_nothing_is_swept(): void {
		$this->assertSame( 0, ImportTemps::sweep( $this->dir . '/work', [ 'uploads' => $this->dir . '/uploads' ], 0 ) );
	}
}

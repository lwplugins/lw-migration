<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\Steps\SourceStep;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SourceFolderTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../../Fixtures/wpress/';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/ext' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/twin.wpress' );
		copy( self::FIXTURES . 'gzip.wpress', $this->dir . '/ext/twin.wpress' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function step(): SourceStep {
		return new SourceStep( new Repository( $this->dir . '/own', $this->dir . '/ext' ), $this->dir . '/jobs' );
	}

	public function test_a_backup_is_looked_up_in_its_folder(): void {
		$store    = new JobStore( $this->dir . '/jobs' );
		$external = ImportJobFixture::make( $store, $this->dir, '', [ 'source' => 'backup', 'location' => 'twin.wpress', 'folder' => 'external' ] );
		$default  = ImportJobFixture::make( $store, $this->dir, '', [ 'source' => 'backup', 'location' => 'twin.wpress' ] );

		$this->assertTrue( $this->step()->run( $external, new NoDeadline() )->isDone() );
		$this->assertSame( realpath( $this->dir . '/ext/twin.wpress' ), $external->state['archive'] );
		$this->assertTrue( $this->step()->run( $default, new NoDeadline() )->isDone() );
		$this->assertSame( realpath( $this->dir . '/own/twin.wpress' ), $default->state['archive'], 'Without a folder our own backup wins (CLI).' );
	}

	public function test_an_upload_source_never_resolves_here(): void {
		$job = ImportJobFixture::make( new JobStore( $this->dir . '/jobs' ), $this->dir, '', [ 'source' => 'upload', 'location' => 'twin.wpress' ] );
		$this->expectException( ImportException::class );
		$this->step()->run( $job, new NoDeadline() );
	}
}

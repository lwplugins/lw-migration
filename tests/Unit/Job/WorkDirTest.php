<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class WorkDirTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_job_folder_and_nested_folder(): void {
		$store = new JobStore( $this->dir );
		$job   = $store->create( 'export', [ 'a' ], [], 1 );

		$this->assertSame( $store->dir( $job->id ), WorkDir::path( $store, $job ) );
		$job->state['workSub'] = 'safety';
		$this->assertDirectoryExists( WorkDir::path( $store, $job ) );
		$this->assertSame( $store->dir( $job->id ) . '/safety', WorkDir::path( $store, $job ) );

		$job->state['workSub'] = '../x';
		$this->expectException( JobException::class );
		WorkDir::path( $store, $job );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Export\ArchiveCodec;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\SiteTree;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ExportPipelineTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		SiteTree::create( $this->dir );
		mkdir( $this->dir . '/backups' );
		Functions\when( 'size_format' )->alias( static fn( $b ) => (string) $b );
		FakeOptions::install();
		FakeWpdb::install(); // Retention revokes the links of a deleted backup.
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $options Export options.
	 * @param array<string, mixed> $secrets Job secrets.
	 */
	private function export( array $options, array $secrets, Retention $retention ): Job {
		$jobs   = new JobStore( $this->dir . '/jobs' );
		$steps  = ExportPipeline::build( $jobs, $this->dir . '/wp-content/lw-migration-0123456789ab', $this->dir . '/backups', $retention, static fn(): FakeConnection => new FakeConnection(), static fn( string $d ): ?int => null, static fn(): int => 0, static fn(): int => 1767225600 );
		$store  = new MemorySecretStore();
		$runner = new Runner( $jobs, $steps, $store );
		$job    = $jobs->create( 'export', ExportPipeline::STEPS, $options + [ 'noDatabase' => true ], 1 );
		$job->state['site'] = SiteFixture::make( $this->dir )->toArray();
		$jobs->save( $job );
		$store->put( $job->id, $secrets );
		$ticks = 0;
		do {
			$job = $runner->tick( $job->id, new CountdownDeadline( 1 ), 2 );
			++$ticks;
		} while ( ! $job->isFinished() && $ticks < 200 );

		return $job;
	}

	public function test_full_export_without_database(): void {
		$job = $this->export( [ 'compression' => 'gzip', 'encrypt' => true ], [ 'password' => 'pw' ], new Retention() );

		$this->assertSame( Job::DONE, $job->status, $job->error );
		$backup = $job->state['backup'];
		$this->assertMatchesRegularExpression( '/^old-test-20260101-000000-\d{3}\.wpress$/', $backup['name'] );
		$this->assertSame( $this->dir . '/backups/' . $backup['name'], $backup['path'] );
		$this->assertFileExists( $backup['path'] );
		$this->assertSame( filesize( $backup['path'] ), $backup['size'] );
		$this->assertSame( [], $job->state['pruned'] );

		$probe          = new Job( 'x', 'export', [], 0, [], $job->options, [], Job::RUNNING );
		$probe->secrets = [ 'password' => 'pw' ];
		$reader         = new Reader( $backup['path'], ArchiveCodec::forJob( $probe ) );
		$reader->validate();
		$names = [];
		foreach ( $reader->entries() as $entry ) {
			$names[] = $entry->name;
			$reader->readContents( $entry ); // Every CRC checks out.
		}
		$this->assertSame( 'package.json', $names[0] );
		$this->assertContains( 'plugins/sample/sample.php', $names );
		$this->assertNotContains( 'plugins/lw-migration/lw-migration.php', $names );
		$this->assertSame( [], preg_grep( '/lw-migration-0123456789ab/', $names ) );
		$first = $reader->firstEntry();
		$this->assertNotNull( $first );
		$this->assertTrue( PackageConfig::fromJson( $reader->readContents( $first ) )->get( 'NoDatabase' ) );
		$reader->close();

		foreach ( [ 'archive.wpress', 'files.list', 'files.list.dirs', 'database.sql' ] as $work ) {
			$this->assertFileDoesNotExist( $this->dir . '/jobs/' . $job->id . '/' . $work, 'Work files are cleaned.' );
		}
	}

	public function test_retention_prunes_older_backups_after_storing(): void {
		file_put_contents( $this->dir . '/backups/old-site-20200101-000000-001.wpress', 'x' );
		touch( $this->dir . '/backups/old-site-20200101-000000-001.wpress', 1000 );
		file_put_contents( $this->dir . '/backups/pre-import-20250101-000000.wpress', 'x' );
		touch( $this->dir . '/backups/pre-import-20250101-000000.wpress', 900 );
		FakeOptions::install(
			[
				Options::LABELS => [
					'old-site-20200101-000000-001.wpress' => 'Before the redesign',
					'other.wpress' => 'Kept',
				],
			]
		);

		$shares = new \LightweightPlugins\Migration\Backups\Shares();
		$shares->create( 'own', 'old-site-20200101-000000-001.wpress', 1, time(), 600, 'x' );
		$kept = $shares->create( 'own', 'pre-import-20250101-000000.wpress', 1, time(), 600, 'x' );

		$job = $this->export( [ 'noMedia' => true ], [], new Retention( 1 ) );

		$this->assertSame( Job::DONE, $job->status, $job->error );
		$this->assertSame( [ $kept['id'] ], array_column( $shares->all(), 'id' ), 'Retention revokes the links of what it prunes.' );
		$this->assertFileDoesNotExist( $this->dir . '/backups/old-site-20200101-000000-001.wpress' );
		$this->assertFileExists( $this->dir . '/backups/pre-import-20250101-000000.wpress' );
		$this->assertFileExists( $job->state['backup']['path'], 'The backup just stored is never pruned.' );
		$this->assertSame( [ 'old-site-20200101-000000-001.wpress' ], $job->state['pruned'] );
		$this->assertSame( [ 'other.wpress' => 'Kept' ], FakeOptions::$values[ Options::LABELS ], 'A pruned backup loses its label.' );
	}
}

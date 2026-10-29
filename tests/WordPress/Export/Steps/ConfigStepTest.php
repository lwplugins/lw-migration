<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\Steps\ConfigStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\MultisiteJson;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ConfigStepTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_a_network_backup_has_multisite_json_second(): void {
		$job     = $this->networkJob( 'sites' );
		$entries = [
			new SiteEntry( 1, 1, 0, 'http://n.test', 'http://n.test', 'n.test', '/', [ 'a/a.php' ], 'tt', 'tt', '', '', '/w/uploads', 'http://n.test/wp-content/uploads/' ),
			new SiteEntry( 3, 1, 0, 'http://n.test/b3', 'http://n.test/b3', 'n.test', '/b3/', [], 'tt', 'tt', false, '', '/w/uploads/sites/3', 'http://n.test/wp-content/uploads/sites/3/' ),
		];
		$this->writeSites( $job, $entries );

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$archive = $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;
		$this->assertSame( filesize( $archive ), $job->state['archiveLength'], 'The length counts both entries.' );
		$read = $this->entries( $job );
		$this->assertSame( [ 'package.json', 'multisite.json' ], array_keys( $read ) );
		$state = $job->state['network'];
		$this->assertSame( MultisiteJson::encode( false, $state['networks'], $entries, LW_MIGRATION_VERSION, $state['plugins'], $state['admins'] ), $read['multisite.json'] );
	}

	public function test_a_whole_network_backup_says_so(): void {
		$job = $this->networkJob( 'network' );
		$this->writeSites( $job, [ new SiteEntry( 1, 1, 0, 'http://n.test', 'http://n.test', 'n.test', '/', [], 'tt', 'tt', '', '', '/w/uploads', 'http://n.test/u/' ) ] );

		$this->step()->run( $job, new NoDeadline() );

		$decoded = json_decode( $this->entries( $job )['multisite.json'], true );
		$this->assertTrue( $decoded['Network'] );
		$this->assertSame( [ 1 ], array_column( $decoded['Sites'], 'BlogID' ) );
	}

	public function test_a_single_site_backup_has_no_multisite_json(): void {
		$job = JobFixture::export( $this->store, $this->dir );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( [ 'package.json' ], array_keys( $this->entries( $job ) ) );
	}

	public function test_a_missing_site_list_fails(): void {
		$job = $this->networkJob( 'network' );
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );

		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'T:The list of the network\'s sites cannot be read. Start the backup again.' );
		$this->step()->run( $job, new NoDeadline() );
	}

	public function test_a_list_too_big_for_an_importer_is_refused(): void {
		$job = $this->networkJob( 'network' );
		$big = new SiteEntry( 1, 1, 0, 'http://n.test', 'http://n.test', 'n.test', '/', [], str_repeat( 'a', ConfigStep::MULTISITE_MAX_BYTES ), 'tt', '', '', '/w', 'http://n.test/u/' );
		$this->writeSites( $job, [ $big ] );

		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'This network has too many sites for one archive: choose sites.' );
		$this->step()->run( $job, new NoDeadline() );
	}

	/**
	 * The entries the step wrote (the archive is closed with an end block first).
	 *
	 * @return array<string, string> Name => contents.
	 */
	private function entries( Job $job ): array {
		$path   = $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;
		$writer = new Writer( $path );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		$reader = new Reader( $path );
		$out    = [];
		foreach ( $reader->entries() as $entry ) {
			$out[ $entry->name ] = $reader->readContents( $entry );
		}
		$reader->close();

		return $out;
	}

	private function step(): ConfigStep {
		return new ConfigStep( $this->store, static fn(): string => '8.0.36', static fn(): int => 1767225600 );
	}

	private function networkJob( string $mode ): Job {
		$job                   = JobFixture::export( $this->store, $this->dir );
		$job->state['network'] = [
			'mode'          => $mode,
			'chosen'        => 'sites' === $mode ? [ 1, 3 ] : [],
			'blogIds'       => [ 1, 2, 3 ],
			'networks'      => [
				[
					'SiteID' => 1,
					'Domain' => 'n.test',
					'Path'   => '/',
				],
			],
			'plugins'       => [ 'net/net.php' ],
			'admins'        => [ 'admin' ],
			'activePlugins' => [ 'net/net.php', 'a/a.php' ],
			'activeThemes'  => [ 'tt' ],
		];

		return $job;
	}

	/**
	 * @param Job                   $job     Export job.
	 * @param array<int, SiteEntry> $entries Sites in the work file.
	 */
	private function writeSites( Job $job, array $entries ): void {
		$lines = '';
		foreach ( $entries as $entry ) {
			$lines .= json_encode( $entry->toArray() ) . "\n";
		}
		file_put_contents( $this->store->dir( $job->id ) . '/' . ExportFiles::NETWORK_SITES, $lines );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import;

use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Import\ImportCleanup;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportTemps;
use LightweightPlugins\Migration\Import\Steps\MuPluginsStep;
use LightweightPlugins\Migration\Import\Steps\NetworkUsersStep;
use LightweightPlugins\Migration\Import\Steps\PostImportStep;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Import\UploadReceiver;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use LightweightPlugins\Migration\Http\RangeDownloader;

final class ImportCleanupTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	private FakeConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->db    = new FakeConnection();
		mkdir( $this->dir . '/backups' );
		mkdir( $this->dir . '/uploads/2026', 0755, true );
		$this->db->rows['SHOW FULL TABLES'] = [
			[ 't' => 'wp_options' ],
			[ 't' => 'lwtab12_options' ],
			[ 't' => 'lwxab12_wbk_y' ],
			[ 't' => 'lwtcd34_other' ],
		];
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function failedJob(): Job {
		$job                     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$job->status             = Job::FAILED;
		$job->state['tmpPrefix'] = 'lwtab12_';

		return $job;
	}

	private function cleanup( Job $job ): void {
		ImportCleanup::afterFailure(
			$job,
			$this->store,
			fn() => $this->db,
			$this->dir . '/backups',
			fn( Job $j ): array => [ 'uploads' => $this->dir . '/uploads' ],
			static fn( string $run ): bool => true
		);
	}

	public function test_failure_before_swap_drops_only_this_jobs_temporary_tables(): void {
		$job                        = $this->failedJob();
		$job->state['archiveOwned'] = true;
		$work                       = $this->store->dir( $job->id );
		file_put_contents( $work . '/' . ImportFiles::DATABASE, 'x' );
		file_put_contents( $work . '/' . ImportFiles::DOWNLOAD, 'x' );

		$this->cleanup( $job );

		$this->assertSame( [ 'DROP TABLE IF EXISTS `lwtab12_options`', 'DROP TABLE IF EXISTS `lwxab12_wbk_y`' ], $this->db->log );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DATABASE );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DOWNLOAD );
	}

	public function test_after_the_swap_nothing_is_dropped_and_the_user_is_told(): void {
		$job                        = $this->failedJob();
		$job->state['swapped']      = true;
		$job->state['safetyBackup'] = [ 'name' => 'pre-import-1.wpress' ];

		$this->cleanup( $job );

		$this->assertSame( [], $this->db->log );
		$warnings = implode( ' ', $job->warnings );
		$this->assertStringContainsString( 'already runs on the imported database', $warnings );
		$this->assertStringNotContainsString( 'post-import fixes', $warnings );
		$this->assertStringContainsString( 'safety backup pre-import-1.wpress', $warnings );
	}

	/**
	 * Final review M3: a sites restore into a network that stops after the
	 * swap still drops its held staging tables (nothing else), and says the
	 * archive's users were not merged.
	 *
	 * @param string               $step   The step that stopped.
	 * @param array<string, mixed> $cursor Its cursor.
	 * @param bool                 $merged Whether the users were merged by then.
	 */
	#[DataProvider( 'stoppedMerges' )]
	public function test_after_the_swap_the_held_staging_tables_are_dropped( string $step, array $cursor, bool $merged ): void {
		$this->db->rows['SHOW FULL TABLES'][] = [ 't' => 'lwtab12_lwmsrc_users' ];
		$job                                  = $this->failedJob();
		$job->state['swapped']                = true;
		$job->state['target']                 = [ 'domain' => 'new.test' ];
		$job->state['kind']                   = ArchiveKind::SITES;
		$job->steps                           = [ SwapStep::KEY, NetworkUsersStep::KEY, PostImportStep::KEY ];
		$job->stepIndex                       = (int) array_search( $step, $job->steps, true );
		$job->cursor                          = $cursor;

		$this->cleanup( $job );

		$this->assertSame( [ 'DROP TABLE IF EXISTS `lwtab12_lwmsrc_users`' ], $this->db->log, 'Only the held staging table; the swapped-in and replaced tables stay.' );
		$notice = 'The users of the archive were not merged into the network, so the restored sites\' authors may point to other users.';
		if ( $merged ) {
			$this->assertStringNotContainsString( $notice, implode( ' ', $job->warnings ) );
		} else {
			$this->assertStringContainsString( $notice, implode( ' ', $job->warnings ) );
		}
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}> */
	public static function stoppedMerges(): array {
		return [
			'in the swap'           => [ SwapStep::KEY, [], false ],
			'in the merge'          => [ NetworkUsersStep::KEY, [ 'phase' => 'remap' ], false ],
			'in the merge\'s drop' => [ NetworkUsersStep::KEY, [ 'phase' => 'drop' ], true ],
			'after the merge'       => [ PostImportStep::KEY, [], true ],
		];
	}

	public function test_a_whole_network_or_single_site_restore_has_no_held_tables_to_drop(): void {
		$this->db->rows['SHOW FULL TABLES'][] = [ 't' => 'lwtab12_lwmsrc_users' ];
		$job                                  = $this->failedJob();
		$job->state['swapped']                = true;

		$this->cleanup( $job );

		$this->assertSame( [], $this->db->log );
		$this->assertStringNotContainsString( 'were not merged', implode( ' ', $job->warnings ) );
	}

	public function test_a_swap_marker_without_the_flag_keeps_every_table(): void {
		$job = $this->failedJob();
		touch( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );

		$this->cleanup( $job );

		$this->assertSame( [], $this->db->log, 'The replaced tables may hold the previous database.' );
		$warnings = implode( ' ', $job->warnings );
		$this->assertStringContainsString( 'may already run on the imported database', $warnings );
		$this->assertStringContainsString( 'No safety backup', $warnings );
		$this->assertFileDoesNotExist( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED, 'The marker goes once the cleanup has read it.' );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>}>
	 */
	public static function swapPhases(): array {
		return [
			'nothing after the rename' => [ [ 'phase' => 'cleanup' ], [ 'The imported views were not created and the replaced tables were not removed.' ], [] ],
			'views created'            => [ [ 'phase' => 'cleanup', 'deferred' => true ], [ 'The replaced tables were not removed.' ], [ 'views' ] ],
			'both done'                => [ [ 'phase' => 'cleanup', 'deferred' => true, 'cleaned' => true ], [], [ 'views', 'replaced tables' ] ],
		];
	}

	/**
	 * @param array<string, mixed> $cursor  Swap step cursor.
	 * @param array<int, string>   $present Expected sentences.
	 * @param array<int, string>   $absent  Words that must not appear.
	 */
	#[DataProvider( 'swapPhases' )]
	public function test_a_stopped_swap_names_only_the_phases_not_reached( array $cursor, array $present, array $absent ): void {
		$job                   = $this->failedJob();
		$job->steps            = [ SwapStep::KEY, PostImportStep::KEY ];
		$job->stepIndex        = 0;
		$job->cursor           = $cursor;
		$job->state['swapped'] = true;

		$this->cleanup( $job );

		$warnings = implode( ' ', $job->warnings );
		foreach ( $present as $sentence ) {
			$this->assertStringContainsString( $sentence, $warnings );
		}
		foreach ( $absent as $word ) {
			$this->assertStringNotContainsString( $word, $warnings );
		}
	}

	public function test_a_failure_before_the_swap_says_which_files_were_already_restored(): void {
		$job                        = $this->failedJob();
		$job->state['filesPlaced']  = true;
		$work                       = $this->store->dir( $job->id );
		ImportTemps::record( $work, 'uploads', '2026' );

		$this->cleanup( $job );

		$this->assertContains( 'Some files from the archive were already put in place on the site; the database was not changed.', $job->warnings );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::EXTRACT_DIRS, 'The journal goes once the sweep has read it.' );
	}

	public function test_no_file_notice_before_any_file_reached_the_site(): void {
		$staged = $this->failedJob();
		ImportTemps::record( $this->store->dir( $staged->id ), 'staging', '' );
		$none = $this->failedJob();

		$this->cleanup( $staged );
		$this->cleanup( $none );

		$this->assertSame( [], $staged->warnings, 'Files in the private staging folder are not on the site.' );
		$this->assertSame( [], $none->warnings );
	}

	public function test_a_journaled_folder_without_a_placed_file_is_not_reported(): void {
		$job = $this->failedJob(); // The first entry failed before it was renamed into place.
		ImportTemps::record( $this->store->dir( $job->id ), 'uploads', '2026' );

		$this->cleanup( $job );

		$this->assertSame( [], $job->warnings );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function stoppedSteps(): array {
		return [
			'swap'        => [ SwapStep::KEY, 'views were not created' ],
			'post_import' => [ PostImportStep::KEY, 'post-import fixes' ],
		];
	}

	#[DataProvider( 'stoppedSteps' )]
	public function test_the_notice_names_what_the_stopped_step_left_undone( string $step, string $expected ): void {
		$job                   = $this->failedJob();
		$job->steps            = [ SwapStep::KEY, PostImportStep::KEY, MuPluginsStep::KEY ];
		$job->stepIndex        = (int) array_search( $step, $job->steps, true );
		$job->state['swapped'] = true;

		$this->cleanup( $job );

		$this->assertStringContainsString( $expected, implode( ' ', $job->warnings ) );
	}

	public function test_the_notice_lists_must_use_plugins_still_staged(): void {
		$job                   = $this->failedJob();
		$job->steps            = [ SwapStep::KEY, PostImportStep::KEY, MuPluginsStep::KEY ];
		$job->stepIndex        = 2;
		$job->state['swapped'] = true;
		$staging               = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging );
		foreach ( range( 10, 20 ) as $n ) {
			file_put_contents( $staging . "/p{$n}.php", 'x' );
		}

		$this->cleanup( $job );

		$warnings = implode( ' ', $job->warnings );
		$this->assertStringContainsString( 'must-use plugins were not restored: p10.php, p11.php', $warnings );
		$this->assertStringContainsString( 'p19.php and 1 more. ', $warnings, 'The list ends its sentence.' );
		$this->assertStringNotContainsString( 'p20.php', $warnings );
		$this->assertDirectoryDoesNotExist( $staging );
	}

	public function test_a_failing_discard_still_removes_the_work_files_and_keeps_the_error(): void {
		$job        = $this->failedJob();
		$job->error = 'Original failure.';
		$work       = $this->store->dir( $job->id );
		ImportTemps::record( $work, 'uploads', '2026' );
		file_put_contents( $work . '/' . ImportFiles::DATABASE, 'x' );
		mkdir( $this->dir . '/wp-content/mu-plugins', 0755, true );
		$orphan = TempName::generate( $this->dir . '/wp-content/mu-plugins' );
		file_put_contents( $orphan, 'half' );
		ImportTemps::record( $work, MuPluginsStep::BASE, '' );

		ImportCleanup::afterFailure(
			$job,
			$this->store,
			fn() => $this->db,
			$this->dir . '/backups',
			static function (): array {
				throw new \RuntimeException( 'No rules.' );
			},
			static fn( string $run ): bool => true
		);

		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DATABASE );
		$this->assertFileDoesNotExist( $orphan, 'The must-use plugins folder is swept without the content bases.' );
		$this->assertSame( 'Original failure.', $job->error );
		$this->assertStringContainsString( 'could not be removed', implode( ' ', $job->warnings ) );
	}

	public function test_finished_imports_and_exports_are_left_alone(): void {
		$done         = $this->failedJob();
		$done->status = Job::DONE;
		$work         = $this->store->dir( $done->id );
		file_put_contents( $work . '/' . ImportFiles::DATABASE, 'x' );
		$this->cleanup( $done );

		$export       = $this->store->create( 'export', [ 'x' ], [], 1 );
		$export->status = Job::FAILED;
		$this->cleanup( $export );

		$this->assertSame( [], $this->db->log );
		$this->assertFileExists( $work . '/' . ImportFiles::DATABASE );
	}

	public function test_temporary_files_outside_the_work_folder_are_discarded(): void {
		$job  = $this->failedJob();
		$work = $this->store->dir( $job->id );
		// The entry being extracted.
		$target  = $this->dir . '/uploads/2026/a.jpg';
		$extract = TempName::generate( dirname( $target ) );
		file_put_contents( $extract, 'part' );
		$cursor           = new ExtractCursor();
		$cursor->tempPath = $extract;
		// An orphan in a journaled folder, and one of someone else outside it.
		ImportTemps::record( $work, 'uploads', '2026' );
		$orphan = TempName::generate( $this->dir . '/uploads/2026' );
		file_put_contents( $orphan, 'orphan' );
		// The safety backup being stored.
		$safety = TempName::name();
		file_put_contents( $this->dir . '/backups/' . $safety, 'db' );
		$job->cursor = [
			'extract' => $cursor->toArray(),
			'target'  => $target,
			'safety'  => [ 'cursor' => [ 'temp' => $safety ] ],
		];

		$this->cleanup( $job );

		$this->assertFileDoesNotExist( $extract );
		$this->assertFileDoesNotExist( $orphan );
		$this->assertFileDoesNotExist( $this->dir . '/backups/' . $safety );
	}

	public function test_bases_are_not_asked_without_a_journal(): void {
		$job = $this->failedJob();

		ImportCleanup::afterFailure(
			$job,
			$this->store,
			fn() => $this->db,
			$this->dir . '/backups',
			static function (): array {
				throw new \LogicException( 'Not needed.' );
			},
			static fn( string $run ): bool => true
		);

		$this->assertCount( 2, $this->db->log );
	}

	public function test_an_upload_always_goes_with_the_work_files(): void {
		$dir = TempDir::create();
		foreach ( [ ImportFiles::UPLOAD, ImportFiles::UPLOAD . UploadReceiver::PART_SUFFIX, ImportFiles::DOWNLOAD ] as $file ) {
			file_put_contents( $dir . '/' . $file, 'x' );
		}

		$this->assertTrue( ImportCleanup::workFiles( $dir, false ) );
		$this->assertFileDoesNotExist( $dir . '/' . ImportFiles::UPLOAD );
		$this->assertFileDoesNotExist( $dir . '/' . ImportFiles::UPLOAD . UploadReceiver::PART_SUFFIX );
		$this->assertFileExists( $dir . '/' . ImportFiles::DOWNLOAD, 'A download is removed only when the job made it.' );
		TempDir::remove( $dir );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function downloadSources(): array {
		return [
			'address'    => [ [ 'source' => 'url', 'location' => 'https://files.test/a.wpress' ], true ],
			'connection' => [ [ 'source' => 'link', 'location' => '0123456789abcdef' ], true ],
			'local file' => [ [], false ],
		];
	}

	/**
	 * @param array<string, mixed> $options Import options.
	 */
	#[DataProvider( 'downloadSources' )]
	public function test_an_unfinished_download_goes_with_a_failed_url_or_link_import( array $options, bool $removed ): void {
		$job = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress', $options );
		$job->status = Job::CANCELLED;
		$dir = $this->store->dir( $job->id );
		foreach ( [ ImportFiles::DOWNLOAD, ImportFiles::DOWNLOAD . RangeDownloader::PART_SUFFIX ] as $file ) {
			file_put_contents( $dir . '/' . $file, 'partial' );
		}
		$this->assertArrayNotHasKey( 'archiveOwned', $job->state, 'The download stopped before it was complete.' );

		$this->cleanup( $job );

		foreach ( [ ImportFiles::DOWNLOAD, ImportFiles::DOWNLOAD . RangeDownloader::PART_SUFFIX ] as $file ) {
			$this->assertSame( ! $removed, is_file( $dir . '/' . $file ), $file );
		}
	}
}

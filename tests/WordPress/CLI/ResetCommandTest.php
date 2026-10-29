<?php
/**
 * Tests for ResetCommand and ResetOutput.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\ResetCommand;
use LightweightPlugins\Migration\CLI\ResetOutput;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Rest\ResetResult;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each test runs in its own process: the WP_CLI stand-in keeps state for the
 * whole process. The refusals come before any job is made (the resets
 * themselves run end to end in ResetE2ETest).
 */
#[RunTestsInSeparateProcesses]
final class ResetCommandTest extends MonkeyTestCase {

	private bool $multisite = false;

	private string $root;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		$this->db = FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		FakeOptions::install();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$this->root = TempDir::create();
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'wp_get_current_user' )->alias( fn() => $this->user() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'wp_check_password' )->alias( static fn( string $password ): bool => 'right' === $password );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		TempDir::remove( WP_CONTENT_DIR . '/lw-migration-0123456789ab' );
		parent::tearDown();
	}

	private function user(): \WP_User {
		$user             = \Mockery::mock( \WP_User::class );
		$user->ID         = 7;
		$user->user_login = 'anna';
		$user->user_pass  = '$wp$2y$10$hash';

		return $user;
	}

	/**
	 * @param array<int, string>   $args  Arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @param string|null          $stdin The line on standard input (null: the process's own STDIN).
	 * @return string|null The error, or null when the command succeeded.
	 */
	private function cli( array $args, array $assoc, ?string $stdin = null ): ?string {
		$site = SiteFixture::make( $this->root, [ 'homeUrl' => 'https://example.com' ] );
		try {
			( new ResetCommand( static fn(): Site => $site, null === $stdin ? null : static fn(): ?string => $stdin ) )( $args, $assoc );
		} catch ( \RuntimeException $e ) {
			return (string) end( \WP_CLI::$errors );
		}

		return null;
	}

	public function test_an_unknown_type_is_refused(): void {
		$this->assertSame( 'Choose one of: plugins, themes, media, database, full.', $this->cli( [ 'everything' ], [] ) );
		$this->assertSame( 'Choose one of: plugins, themes, media, database, full.', $this->cli( [], [] ) );
	}

	public function test_a_user_is_needed(): void {
		Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'ID' => 0, 'user_login' => '' ] );
		$this->assertSame( 'Pass --user=<admin login>: the reset checks that admin\'s password and keeps that user.', $this->cli( [ 'media' ], [] ) );
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( 'Pass --user=<admin login>: the reset checks that admin\'s password and keeps that user.', $this->cli( [ 'media' ], [] ) );
	}

	public function test_a_network_refuses_the_database_with_the_reason(): void {
		$this->multisite = true;
		$this->assertSame( 'On a network only plugins, themes and media can be reset in this version.', $this->cli( [ 'database' ], [ 'yes' => true ] ) );
		$this->assertSame( 'On a network only plugins, themes and media can be reset in this version.', $this->cli( [ 'full' ], [ 'yes' => true ], 'right' ) );
	}

	public function test_without_a_terminal_yes_is_required_and_an_empty_stdin_password_stops(): void {
		$this->assertSame( 'Without a terminal, pass --yes to confirm the reset.', $this->cli( [ 'media' ], [] ) );
		$this->assertSame( 'Give the password of anna on a terminal, or as one line on standard input.', $this->cli( [ 'media' ], [ 'yes' => true ] ) ); // STDIN is empty in the test process.
	}

	public function test_an_empty_password_line_is_refused_without_a_try(): void {
		$this->assertSame( 'Give the password of anna on a terminal, or as one line on standard input.', $this->cli( [ 'media' ], [ 'yes' => true ], '' ) );
		$this->assertNull( $this->db->value( Options::RESET_TRIES ) );
	}

	public function test_a_wrong_password_is_refused_and_counted(): void {
		$this->assertSame( 'This is not your password.', $this->cli( [ 'media' ], [ 'yes' => true ], 'wrong' ) );
		$this->assertNotNull( $this->db->value( Options::RESET_TRIES ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->cli( [ 'media' ], [ 'yes' => true ], 'wrong' );
		}
		$this->assertSame( 'Too many wrong passwords. Try again in 15 minutes.', $this->cli( [ 'media' ], [ 'yes' => true ], 'right' ) );
	}

	public function test_backup_is_on_unless_no_backup_is_passed(): void {
		$this->assertTrue( ResetCommand::backup( [] ) );
		$this->assertTrue( ResetCommand::backup( [ 'backup' => true ] ) );
		$this->assertFalse( ResetCommand::backup( [ 'backup' => false ] ), 'WP-CLI turns --no-backup into backup => false.' );
	}

	public function test_a_reset_of_the_same_kind_left_by_this_admin_is_continued(): void {
		$jobs = new JobStore( $this->root . '/jobs' );
		$lock = new SiteLock();
		$site = SiteFixture::make( $this->root );
		$job  = ResetJobs::create( $jobs, $lock, $site, 'plugins', false, [ 'id' => 7, 'login' => 'anna' ], time() );
		$this->assertSame( $job->id, ResetCommand::resumable( $jobs, $lock, 'plugins', 7, time() )?->id );
		$this->assertNull( ResetCommand::resumable( $jobs, $lock, 'media', 7, time() ), 'Another kind is not continued.' );
		$this->assertNull( ResetCommand::resumable( $jobs, $lock, 'plugins', 8, time() ), 'Another admin\'s reset is not continued.' );

		$lock->release( $job->id );
		$lock->acquire( 'aaaaaaaaaaaaaaaa', time() );
		$this->assertNull( ResetCommand::resumable( $jobs, $lock, 'plugins', 7, time() ), 'Another job holds the site.' );
	}

	public function test_a_finished_reset_is_never_continued(): void {
		$jobs = new JobStore( $this->root . '/jobs' );
		$lock = new SiteLock();
		$job  = ResetJobs::create( $jobs, $lock, SiteFixture::make( $this->root ), 'themes', false, [ 'id' => 7, 'login' => 'anna' ], time() );
		$job->status = Job::DONE;
		$jobs->save( $job );
		$this->assertNull( ResetCommand::resumable( $jobs, $lock, 'themes', 7, time() ) );
	}

	public function test_the_summary_names_what_goes_and_what_stays(): void {
		$summary = [
			'network'     => false,
			'phrase'      => 'example.com',
			'backupReady' => true,
			'dropIns'     => [ 'object-cache.php' ],
			'muPlugins'   => 1,
			'keptPlugins' => [
				[
					'plugin' => 'cache/cache.php',
					'by'     => 'object-cache.php',
				],
			],
			'types'       => [
				'plugins'  => [ 'count' => 12, 'names' => array_map( static fn( int $i ): string => 'p' . $i . '/p' . $i . '.php', range( 1, 10 ) ), 'more' => 2 ],
				'themes'   => [ 'target' => [ 'slug' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five', 'source' => 'installed' ], 'count' => 3, 'names' => [ 'a', 'b', 'c' ], 'more' => 0 ],
				'media'    => [ 'attachments' => 145, 'folder' => '[uploads]' ],
				'database' => [
					'user'          => 'anna',
					'tables'        => [ 'replaced' => 12, 'retired' => 7, 'kept' => 2, 'retiredNames' => [ 'wp_a', 'wp_b' ], 'more' => 5 ],
					'prefixWarning' => true,
					'connections'   => 2,
					'schedules'     => 1,
					'otherUsers'    => 4,
				],
			],
		];
		$full = implode( "\n", ResetOutput::summary( 'full', $summary ) );
		$this->assertStringContainsString( '12 plugins will be deleted: p1/p1.php, p2/p2.php', $full );
		$this->assertStringContainsString( 'p10/p10.php and 2 more.', $full );
		$this->assertStringContainsString( 'cache/cache.php stays: object-cache.php loads it directly.', $full );
		$this->assertStringContainsString( 'The theme Twenty Twenty-Five will be active; 3 themes will be deleted.', $full );
		$this->assertStringContainsString( 'Every media item (145) and every file in the uploads folder will be deleted.', $full );
		$this->assertStringContainsString( 'The database becomes a fresh install; you (anna) stay, every other user (4), all content and 7 other tables go.', $full );
		$this->assertStringContainsString( 'Tables that go: wp_a, wp_b and 5 more.', $full );
		$this->assertStringContainsString( 'application passwords', $full );
		$this->assertStringContainsString( 'LW Migration stays active with its settings and transfer links.', $full );
		$this->assertStringContainsString( '2 connections and 1 schedule stay; connections made by other admins stop working.', $full );
		$this->assertStringContainsString( 'does not end in an underscore', $full );
		$this->assertStringContainsString( 'Drop-ins stay: object-cache.php.', $full );

		$media = implode( "\n", ResetOutput::summary( 'media', $summary ) );
		$this->assertStringNotContainsString( 'plugins will be deleted', $media );
		$this->assertStringNotContainsString( 'Drop-ins', $media, 'The drop-ins matter to a plugins reset only.' );
		$this->assertStringContainsString( 'Every media item (145)', $media );
	}

	public function test_the_result_names_the_backup_and_the_drop_ins(): void {
		$job                              = new Job( '0123456789abcdef', ResetJobs::TYPE, [ 'reset_backup', 'reset_plugins' ], 0, [], [ 'type' => 'plugins', 'backup' => true ] );
		$job->state['backup']             = [ 'name' => 'pre-reset-20261011-120000.wpress', 'size' => 1024 ];
		$job->state['removed']['plugins'] = [ 'count' => 2, 'names' => [ 'a/a.php', 'b/b.php' ] ];
		$job->state['dropIns']            = [ 'object-cache.php' ];
		$lines                            = implode( "\n", ResetOutput::result( ResetResult::of( $job ) ) );
		$this->assertStringContainsString( 'Backup before the reset: pre-reset-20261011-120000.wpress', $lines );
		$this->assertStringContainsString( '2 plugins deleted: a/a.php, b/b.php', $lines );
		$this->assertStringContainsString( 'object-cache.php', $lines );
		$this->assertStringContainsString( 'If one of these belonged to a deleted plugin, remove it by hand.', $lines );
	}

	public function test_another_job_holding_the_site_is_refused_before_the_password_is_asked_for(): void {
		( new SiteLock() )->acquire( 'aaaaaaaaaaaaaaaa', time() );
		$this->assertSame( 'Another job is running on this site, so nothing was reset and no password was asked for. Run the reset again once it has finished.', $this->cli( [ 'media' ], [ 'yes' => true ], 'wrong' ) );
		$this->assertNull( $this->db->value( Options::RESET_TRIES ), 'No try was used.' );
	}

	public function test_only_an_unfinished_reset_of_the_same_kind_by_this_admin_is_not_busy(): void {
		$jobs = new JobStore( $this->root . '/jobs' );
		$lock = new SiteLock();
		$this->assertFalse( ResetCommand::busy( $jobs, $lock, 'plugins', 7, time() ), 'A free site.' );
		ResetJobs::create( $jobs, $lock, SiteFixture::make( $this->root ), 'plugins', false, [ 'id' => 7, 'login' => 'anna' ], time() );
		$this->assertFalse( ResetCommand::busy( $jobs, $lock, 'plugins', 7, time() ), 'Continued after the password.' );
		$this->assertTrue( ResetCommand::busy( $jobs, $lock, 'media', 7, time() ) );
		$this->assertTrue( ResetCommand::busy( $jobs, $lock, 'plugins', 8, time() ) );
	}

	public function test_the_resume_text_names_uninstall_routines_only_where_they_run(): void {
		$this->assertStringContainsString( 'uninstall routine', ResetCommand::resumeText( 'plugins' ) );
		$this->assertStringContainsString( 'uninstall routine', ResetCommand::resumeText( 'full' ) );
		$this->assertSame( 'Your earlier reset (media) did not finish. It continues now.', ResetCommand::resumeText( 'media' ) );
		$this->assertStringNotContainsString( 'plugin', ResetCommand::resumeText( 'themes' ) );
	}

	public function test_the_database_result_counts_one_retired_table_in_the_singular(): void {
		$job                               = new Job( '0123456789abcdef', ResetJobs::TYPE, [ 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ], 0, [], [ 'type' => 'database', 'backup' => false ] );
		$job->state['removed']['database'] = [ 'replaced' => 12, 'retired' => 1, 'kept' => 0, 'views' => [] ];
		$this->assertContains( 'Database replaced: 12 core tables fresh, 1 other table removed.', ResetOutput::result( ResetResult::of( $job ) ) );
	}

	public function test_the_result_counts_media_in_the_singular_and_names_leftovers(): void {
		$job                              = new Job( '0123456789abcdef', ResetJobs::TYPE, [ 'reset_plugins', 'reset_media' ], 0, [], [ 'type' => 'full', 'backup' => false ] );
		$job->state['removed']['media']   = [ 'attachments' => 1, 'files' => 1, 'failed' => 0, 'kept' => 0 ];
		$job->state['removed']['plugins'] = [ 'count' => 2, 'names' => [ 'a/a.php', 'b/b.php' ], 'leftovers' => 1 ];
		$lines                            = ResetOutput::result( ResetResult::of( $job ) );
		$this->assertContains( 'Media removed: 1 item and 1 file.', $lines );
		$this->assertContains( '2 plugins deleted: a/a.php, b/b.php (1 of them kept files that could not be removed; see the warnings)', $lines );
	}
}

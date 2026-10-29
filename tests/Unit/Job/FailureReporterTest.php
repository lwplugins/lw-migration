<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\FailureReporter;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class FailureReporterTest extends TestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function log( Job $job ): string {
		return (string) file_get_contents( $this->store->dir( $job->id ) . '/job.log' );
	}

	public function test_paths_are_redacted_only_at_a_path_boundary(): void {
		$job = $this->store->create( 'export', [ 's' ], [], 1 );

		( new FailureReporter( $this->store, [ '/srv/site/' ] ) )->fail( $job, new \RuntimeException( 'A /srv/site/wp-content/a.txt B /srv/site-old/x C /backup/srv/site/y D /srv/site' ), 2 );

		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( 'A wp-content/a.txt B /srv/site-old/x C /backup/srv/site/y D ', $job->error );
	}

	public function test_the_random_segment_of_a_backups_folder_is_hidden_also_where_no_path_names_it(): void {
		$job = $this->store->create( 'export', [ 's' ], [], 1 );

		( new FailureReporter( $this->store, [ '/srv/site/' ] ) )->fail( $job, new \RuntimeException( 'Cannot write /srv/site/lw-migration-backups-0123456789ab/x, LW-Migration-Backups-ABCDEF012345 or lw-migration-backups-0123456789abc' ), 2 );

		$this->assertSame( 'Cannot write lw-migration-backups-…/x, lw-migration-backups-… or lw-migration-backups-…c', $job->error );
		$this->assertStringNotContainsString( '0123456789ab', $this->log( $job ) );
	}

	public function test_paths_inside_typographic_quotes_or_after_a_pipe_are_redacted(): void {
		$paths = [ '/srv/site' ];
		$cases = [
			'„/srv/site/wp-content/a.txt”'  => '„wp-content/a.txt”',
			'«/srv/site/wp-content/a.txt»'  => '«wp-content/a.txt»',
			'“/srv/site/wp-content/a.txt”'  => '“wp-content/a.txt”',
			'‘/srv/site/wp-content/a.txt’'  => '‘wp-content/a.txt’',
			'a|/srv/site/wp-content/a.txt|' => 'a|wp-content/a.txt|',
			'„/srv/site”'                   => '„”',
		];
		foreach ( $cases as $message => $expected ) {
			$this->assertSame( $expected, FailureReporter::redactWith( $message, $paths ), $message );
		}
		$this->assertSame( '/srv/site-old/x', FailureReporter::redactWith( '/srv/site-old/x', $paths ), 'Still only at a path boundary.' );
	}

	public function test_secret_values_are_masked_in_error_and_log(): void {
		$job          = $this->store->create( 'import', [ 's' ], [], 1 );
		$job->secrets = [
			'password' => 'hunter2-secret',
			'empty'    => '',
		];

		( new FailureReporter( $this->store, [] ) )->fail( $job, new \RuntimeException( 'Wrong key hunter2-secret' ), 2 );

		$this->assertSame( 'Wrong key ***', $job->error );
		$this->assertStringNotContainsString( 'hunter2-secret', $this->log( $job ) );
		$this->assertStringContainsString( 'Wrong key ***', $this->log( $job ) );
	}

	public function test_pending_warnings_are_logged_before_the_failure_line(): void {
		$job = $this->store->create( 'export', [ 's' ], [], 1 );
		$job->addWarning( 'w1' );

		( new FailureReporter( $this->store, [] ) )->fail( $job, new \RuntimeException( 'boom' ), 2 );

		$lines = explode( "\n", trim( $this->log( $job ) ) );
		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( 'WARNING w1', $lines[0] );
		$this->assertStringContainsString( 'FAILED in step s: RuntimeException: boom', $lines[1] );
		$this->assertSame( [], $job->newWarnings );
	}

	public function test_failure_line_is_written_when_the_log_is_full(): void {
		$job = $this->store->create( 'export', [ 's' ], [], 1 );
		file_put_contents( $this->store->dir( $job->id ) . '/job.log', str_repeat( 'x', 1048576 ) );
		$job->addWarning( 'dropped' );

		( new FailureReporter( $this->store, [] ) )->fail( $job, new \RuntimeException( 'boom' ), 2 );

		$this->assertStringNotContainsString( 'dropped', $this->log( $job ) );
		$this->assertStringContainsString( 'FAILED in step s', $this->log( $job ) );
	}

	public function test_log_lines_and_exception_locations_are_redacted_like_the_error(): void {
		$job = $this->store->create( 'export', [ 's' ], [], 1 );
		$job->addWarning( 'Skipped ' . __DIR__ . '/a.txt' );

		( new FailureReporter( $this->store, [ dirname( __DIR__, 3 ) ] ) )->fail( $job, new \RuntimeException( 'Cannot open ' . __FILE__ ), 2 );

		$log = $this->log( $job );
		$this->assertStringNotContainsString( dirname( __DIR__, 3 ), $log );
		$this->assertStringContainsString( 'WARNING Skipped tests/Unit/Job/a.txt', $log );
		$this->assertStringContainsString( 'Cannot open tests/Unit/Job/FailureReporterTest.php at tests/Unit/Job/FailureReporterTest.php:', $log );
	}
}

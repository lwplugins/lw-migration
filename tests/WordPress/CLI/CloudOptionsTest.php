<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\CloudCommand;
use LightweightPlugins\Migration\CLI\ExportCommand;
use LightweightPlugins\Migration\CLI\UploadCommand;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The refusals of `export --to`, `--keep-local` and `cloud delete` that happen before any work starts.
 */
final class CloudOptionsTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		\WP_CLI::$errors = [];
		\WP_CLI::$lines  = [];
		FakeOptions::install();
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
	}

	private function error( callable $run ): string {
		try {
			$run();
			$this->fail( 'The command should have stopped.' );
		} catch ( \RuntimeException $e ) {
			return (string) end( \WP_CLI::$errors );
		}
	}

	public function test_keep_local_without_to_is_refused(): void {
		$error = $this->error( static fn() => ( new ExportCommand() )( [], [ 'keep-local' => false ] ) );

		$this->assertStringContainsString( '--keep-local', $error );
	}

	public function test_an_unknown_destination_is_refused_before_the_export_starts(): void {
		$error = $this->error( static fn() => ( new ExportCommand() )( [], [ 'to' => '0a1b2c3d' ] ) );

		$this->assertStringContainsString( '0a1b2c3d', $error );
		$this->assertStringContainsString( 'destination list', $error );
	}

	public function test_a_malformed_destination_id_is_refused(): void {
		$this->assertNotSame( '', $this->error( static fn() => ( new ExportCommand() )( [], [ 'to' => 'not-an-id' ] ) ) );
	}

	public function test_a_cloud_delete_of_a_non_backup_name_is_refused_before_asking(): void {
		$error = $this->error( static fn() => ( new CloudCommand() )->delete( [ '0a1b2c3d', '../../etc/passwd' ], [ 'yes' => true ] ) );

		$this->assertStringContainsString( '.wpress', $error );
	}

	public function test_a_restore_without_a_terminal_needs_yes_before_anything_else(): void {
		$error = $this->error( static fn() => ( new CloudCommand( static fn(): bool => false ) )->restore( [ '0a1b2c3d', 'a.wpress' ], [] ) );

		$this->assertStringContainsString( '--yes', $error );
	}

	public function test_a_cloud_delete_checks_the_destination_before_asking(): void {
		$error = $this->error( static fn() => ( new CloudCommand( static fn(): bool => false ) )->delete( [ '0a1b2c3d', 'a.wpress' ], [] ) );

		$this->assertSame( 'This destination does not exist (any more).', $error );
	}

	public function test_an_upload_ends_with_an_error_when_not_every_upload_succeeded(): void {
		$job                 = new Job( '0123456789abcdef', 'cloud', [] );
		$job->state['cloud'] = CloudPlan::upload( [ 'aaaa1111' ] )->toArray();
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::FAILED, 'One', 's3', 'Access denied.' );

		$error = $this->error( static fn() => UploadCommand::conclude( $job ) );

		$this->assertSame( 'Not every upload succeeded.', $error );
		$this->assertContains( 'Warning: Upload to One failed: Access denied.', \WP_CLI::$lines );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Job\JobBusyException;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;

final class ErrorMapMessagesTest extends MonkeyTestCase {

	private const PATHS = [ '/srv/www' ];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => 'site-time(' . $time . ')' );
	}

	public function test_a_busy_job_gets_our_translated_message_never_the_exception_text(): void {
		$error = ErrorMap::of( new JobBusyException( 'The job is busy; try again. /srv/www/x' ), self::PATHS, 'Oops.' );

		$this->assertSame(
			[
				'code'    => 'lw_migration_job_busy',
				'message' => 'T:This job is being worked on by another request; try again in a moment.',
				'status'  => 409,
				'data'    => [],
			],
			$error
		);
		$this->assertSame( ErrorMap::JOB_BUSY, $error['code'] );
		$this->assertInstanceOf( JobException::class, new JobBusyException( 'x' ), 'The CLI still catches it as a JobException.' );
	}

	public function test_a_busy_site_gets_the_busy_text_built_from_the_lock_expiry(): void {
		$busy = new SiteBusyException( 1234 );
		$this->assertSame( 1234, $busy->expiresAt );
		$this->assertInstanceOf( JobException::class, $busy, 'The CLI still catches it as a JobException.' );

		$error = ErrorMap::of( $busy, self::PATHS, 'Oops.' );
		$this->assertSame( [ 'lw_migration_site_busy', 409, [] ], [ $error['code'], $error['status'], $error['data'] ] );
		$this->assertSame( ErrorMap::SITE_BUSY, $error['code'] );
		$this->assertSame( 'T:Another backup or restore is running on this site. Wait until it finishes; if it has stopped, its lock expires at site-time(1234).', $error['message'] );
		$this->assertSame( $busy->getMessage(), $error['message'], 'The same text as the CLI refusal.' );

		$unknown = ErrorMap::of( new SiteBusyException( null ), self::PATHS, 'Oops.' );
		$this->assertSame( 'T:Another backup or restore is running on this site. Wait until it finishes.', $unknown['message'] );
	}

	public function test_other_invalid_input_gets_the_generic_bad_request_text(): void {
		$others = [
			new \InvalidArgumentException( 'A secret box key has 32 bytes.' ),
			new ExportException( 'Cannot open the file list.' ),
			new ImportException( 'Unknown post-import task: /srv/www/x' ),
		];
		foreach ( $others as $e ) {
			$this->assertSame(
				[
					'code'    => 'lw_migration_invalid',
					'message' => 'T:The request is not valid.',
					'status'  => 400,
					'data'    => [],
				],
				ErrorMap::of( $e, self::PATHS, 'Oops.' ),
				get_class( $e )
			);
		}
	}

	public function test_a_storage_failure_names_the_folder_to_fix_without_its_path(): void {
		$error = ErrorMap::of( new StorageException( 'Cannot create /srv/www/wp-content/lw-migration-0123456789ab' ), self::PATHS, 'Oops.' );

		$this->assertSame(
			[
				'code'    => 'lw_migration_storage',
				'message' => 'T:The plugin\'s folders or the backups folder cannot be created or written. Check their permissions.',
				'status'  => 500,
				'data'    => [],
			],
			$error
		);
	}
}

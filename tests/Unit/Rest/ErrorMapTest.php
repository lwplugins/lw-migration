<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Rest;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\RestException;
use PHPUnit\Framework\TestCase;

final class ErrorMapTest extends TestCase {

	private const PATHS = [ '/srv/www', '/srv/www/wp-content/' ];

	public function test_known_failures_keep_their_message_without_server_paths(): void {
		$this->assertSame(
			[
				'code'    => 'lw_migration_upload_offset',
				'message' => 'Continue.',
				'status'  => 409,
				'data'    => [ 'received' => 5 ],
			],
			ErrorMap::of( new RestException( 'lw_migration_upload_offset', 'Continue.', 409, [ 'received' => 5 ] ), self::PATHS, 'Oops.' )
		);

		$invalid = RestException::invalid( 'Give a backup name. /srv/www/wp-content/x https://u:p4ss@files.test/a/b.wpress?t=SECRET' );
		$this->assertSame( [ ErrorMap::INVALID, 400 ], [ $invalid->errorCode, $invalid->status ] );
		$this->assertSame(
			[
				'code'    => 'lw_migration_invalid',
				'message' => 'Give a backup name. x https://files.test/…/b.wpress',
				'status'  => 400,
				'data'    => [],
			],
			ErrorMap::of( $invalid, self::PATHS, 'Oops.' ),
			'Our validation keeps its text, without paths and with addresses in display form.'
		);
	}

	public function test_internal_job_failures_get_the_fixed_message(): void {
		$internal = [
			new JobException( 'Cannot create job directory: /srv/www/wp-content/lw-migration-0123456789ab/jobs/1' ),
			new JobException( 'Job not found: 0123456789abcdef' ),
		];
		foreach ( $internal as $e ) {
			$this->assertSame(
				[
					'code'    => 'lw_migration_error',
					'message' => 'Something went wrong.',
					'status'  => 500,
					'data'    => [],
				],
				ErrorMap::of( $e, self::PATHS, 'Something went wrong.' ),
				$e->getMessage()
			);
		}
	}

	public function test_anything_else_is_a_generic_server_error(): void {
		$this->assertSame(
			[
				'code'    => 'lw_migration_error',
				'message' => 'Something went wrong.',
				'status'  => 500,
				'data'    => [],
			],
			ErrorMap::of( new \RuntimeException( 'mysqli failed in /srv/www/wp-includes/class-wpdb.php' ), self::PATHS, 'Something went wrong.' )
		);
	}

	public function test_long_messages_are_clipped(): void {
		$error = ErrorMap::of( new RestException( 'lw_migration_x', str_repeat( 'á', 3000 ), 400 ), self::PATHS, 'Oops.' );
		$this->assertLessThanOrEqual( 1024, strlen( $error['message'] ) );
		$this->assertSame( 1, preg_match( '//u', $error['message'] ) );
	}
}

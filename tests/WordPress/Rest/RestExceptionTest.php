<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class RestExceptionTest extends MonkeyTestCase {

	public function test_the_generic_field_refusal_is_marked_so_the_browser_shows_its_own_hint(): void {
		$e = RestException::invalidField( 'excludeTables' );

		$this->assertSame( [ 400, ErrorMap::INVALID ], [ $e->status, $e->errorCode ] );
		$this->assertSame( [ 'field' => 'excludeTables', 'generic' => true ], $e->data );
	}

	public function test_a_specific_refusal_is_not_marked(): void {
		$this->assertSame( [ 'field' => 'url' ], RestException::invalid( 'Only http and https addresses can be imported.', 'url' )->data );
		$this->assertSame( [], RestException::invalid( 'Excluded paths must name a folder or file inside wp-content: ..' )->data );
	}

	public function test_the_mark_reaches_the_rest_error(): void {
		$error = ErrorMap::of( RestException::invalidField( 'password' ), [], 'Fallback.' );

		$this->assertTrue( $error['data']['generic'] );
		$this->assertSame( 'password', $error['data']['field'] );
	}

	public function test_a_destination_failure_carries_its_reason_and_whether_it_may_pass(): void {
		$permanent = RestException::destinationFailed( new PermanentFailure( 'Access denied.', PermanentFailure::DENIED, 403 ) );
		$transient = RestException::destinationFailed( new TransientFailure( 'Try later.', TransientFailure::NETWORK ) );

		$this->assertSame( [ 422, 'lw_migration_destination_failed', 'Access denied.', [ 'reason' => PermanentFailure::DENIED, 'transient' => false ] ], [ $permanent->status, $permanent->errorCode, $permanent->getMessage(), $permanent->data ] );
		$this->assertSame( [ 'reason' => TransientFailure::NETWORK, 'transient' => true ], $transient->data );
	}

	public function test_a_destination_failure_carries_only_its_known_facts_and_they_never_replace_the_reason(): void {
		$e = RestException::destinationFailed(
			new PermanentFailure(
				'Shows another key.',
				PermanentFailure::HOST_KEY_CHANGED,
				0,
				null,
				[
					'hostKey'  => 'SHA256:new',
					'expected' => 'SHA256:old',
					'reason'   => 'planted',
					'server'   => 'a reply the server sent',
				]
			)
		);

		$this->assertSame(
			[
				'hostKey'   => 'SHA256:new',
				'expected'  => 'SHA256:old',
				'reason'    => PermanentFailure::HOST_KEY_CHANGED,
				'transient' => false,
			],
			$e->data
		);
	}
}

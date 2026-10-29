<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use PHPUnit\Framework\TestCase;

final class CloudFailureTest extends TestCase {

	public function test_a_failure_carries_facts_for_programs_and_none_by_default(): void {
		$changed = new PermanentFailure( 'Changed.', PermanentFailure::HOST_KEY_CHANGED, 0, null, [ 'hostKey' => 'SHA256:new', 'expected' => 'SHA256:old' ] );

		$this->assertSame( [ 'hostKey' => 'SHA256:new', 'expected' => 'SHA256:old' ], $changed->data );
		$this->assertSame( 'host_key_changed', $changed->reason );
		$this->assertSame( [], ( new TransientFailure( 'Busy.', TransientFailure::BUSY ) )->data );
		$this->assertSame( [ 'host_key_unknown', 'cut_off' ], [ PermanentFailure::HOST_KEY_UNKNOWN, PermanentFailure::CUT_OFF ] );
	}
}

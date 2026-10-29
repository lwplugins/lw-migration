<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\ScanPosition;
use PHPUnit\Framework\TestCase;

final class ScanPositionTest extends TestCase {

	public function test_entries_up_to_the_position_are_passed_and_its_folders_entered(): void {
		$resume = ScanPosition::names( 'b/c/d.jpg' );
		$this->assertNull( ScanPosition::resumeAt( 'a', $resume ), 'Before it: finished.' );
		$this->assertSame( [ 'c', 'd.jpg' ], ScanPosition::resumeAt( 'b', $resume ), 'On its path: entered with the rest.' );
		$this->assertSame( [], ScanPosition::resumeAt( 'c', $resume ), 'After it: a plain entry.' );
		$last = [ 'x.jpg' ];
		$this->assertNull( ScanPosition::resumeAt( 'x.jpg', $last ), 'The position itself was finished.' );
	}

	public function test_the_position_is_the_trail_and_the_last_finished_name(): void {
		$scan = new ScanPosition();
		$this->assertSame( '', $scan->last() );
		$scan->enter( '2026' );
		$scan->enter( '10' );
		$scan->finished( 'a.jpg' );
		$scan->leave();
		$this->assertSame( '2026/10/a.jpg', $scan->last() );
		$scan->finished( '10' );
		$this->assertSame( '2026/10', $scan->last() );
		$this->assertSame( [ 'B', 'a', 'b' ], ScanPosition::order( [ 'b', 'a', 'B' ] ), 'Byte order.' );
	}
}

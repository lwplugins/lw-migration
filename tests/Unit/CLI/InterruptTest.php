<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\CLI;

use LightweightPlugins\Migration\CLI\Interrupt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InterruptTest extends TestCase {

	public function test_request_is_remembered(): void {
		$interrupt = new Interrupt();
		$this->assertFalse( $interrupt->requested() );
		$interrupt->request();
		$this->assertTrue( $interrupt->requested() );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function signals(): array {
		return [
			'SIGINT'  => [ 2 ],
			'SIGTERM' => [ 15 ],
			'SIGHUP'  => [ 1 ],
		];
	}

	#[DataProvider( 'signals' )]
	public function test_signal_is_caught_while_trapped_and_the_old_handlers_restored( int $signal ): void {
		if ( ! function_exists( 'pcntl_signal_get_handler' ) || ! function_exists( 'posix_kill' ) ) {
			$this->markTestSkipped( 'pcntl and posix are needed to send a real SIGINT.' );
		}
		$before    = array_map( 'pcntl_signal_get_handler', [ SIGINT, SIGTERM, SIGHUP ] );
		$interrupt = new Interrupt();
		$interrupt->trap();
		$this->assertNotSame( $before, array_map( 'pcntl_signal_get_handler', [ SIGINT, SIGTERM, SIGHUP ] ), 'All three are trapped.' );
		posix_kill( getmypid(), $signal );
		pcntl_signal_dispatch();
		$this->assertSame( $before, array_map( 'pcntl_signal_get_handler', [ SIGINT, SIGTERM, SIGHUP ] ), 'The first signal puts the old handlers back.' );
		$interrupt->restore();

		$this->assertTrue( $interrupt->requested() );
		$this->assertSame( $before, array_map( 'pcntl_signal_get_handler', [ SIGINT, SIGTERM, SIGHUP ] ) );
	}
}

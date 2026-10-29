<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Rest;

use LightweightPlugins\Migration\Rest\QuietOutput;
use PHPUnit\Framework\TestCase;

final class QuietOutputTest extends TestCase {

	public function test_stray_output_is_thrown_away_and_the_result_kept(): void {
		$level = ob_get_level();
		$this->expectOutputString( '' );

		$result = QuietOutput::run(
			static function (): int {
				echo 'Warning: fopen(/srv/www/a): Failed to open stream';
				ob_start(); // A buffer the work left open is closed too.
				echo 'more';
				return 42;
			}
		);

		$this->assertSame( 42, $result );
		$this->assertSame( $level, ob_get_level() );
	}

	public function test_output_before_an_exception_is_thrown_away_too(): void {
		$level = ob_get_level();
		$this->expectOutputString( '' );

		try {
			QuietOutput::run(
				static function (): void {
					echo 'Notice: /srv/www/wp-config.php';
					throw new \RuntimeException( 'stop' );
				}
			);
			$this->fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'stop', $e->getMessage() );
		}
		$this->assertSame( $level, ob_get_level() );
	}
}

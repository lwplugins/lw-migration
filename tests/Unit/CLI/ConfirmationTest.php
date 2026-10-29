<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\CLI;

use LightweightPlugins\Migration\CLI\Confirmation;
use PHPUnit\Framework\TestCase;

final class ConfirmationTest extends TestCase {

	public function test_yes_flag_skips_the_prompt(): void {
		$ask = function (): ?string {
			$this->fail( 'Asked although --yes was given.' );
		};
		$this->assertSame( Confirmation::CONFIRMED, Confirmation::decide( [ 'yes' => true ], false, $ask ) );
	}

	public function test_without_a_terminal_yes_is_required(): void {
		$ask = function (): ?string {
			$this->fail( 'Asked without a terminal.' );
		};
		$this->assertSame( Confirmation::NEEDS_YES, Confirmation::decide( [], false, $ask ) );
	}

	public function test_interactive_answers(): void {
		$this->assertSame( Confirmation::CONFIRMED, Confirmation::decide( [], true, static fn(): ?string => ' Y ' ) );
		$this->assertSame( Confirmation::DECLINED, Confirmation::decide( [], true, static fn(): ?string => 'n' ) );
		$this->assertSame( Confirmation::DECLINED, Confirmation::decide( [], true, static fn(): ?string => 'yes please' ) );
		$this->assertSame( Confirmation::DECLINED, Confirmation::decide( [], true, static fn(): ?string => null ) );
	}

	public function test_requires_yes_only_without_yes_and_without_a_terminal(): void {
		$this->assertTrue( Confirmation::requiresYes( [], false ) );
		$this->assertFalse( Confirmation::requiresYes( [ 'yes' => true ], false ) );
		$this->assertFalse( Confirmation::requiresYes( [], true ) );
	}
}

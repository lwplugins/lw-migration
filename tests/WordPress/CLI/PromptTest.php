<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\Prompt;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class PromptTest extends MonkeyTestCase {

	public function test_line_returns_the_visible_answer(): void {
		Functions\expect( 'cli\prompt' )->once()->with( 'Continue? [y/n]', false, ' ', false )->andReturn( 'y' );

		$this->assertSame( 'y', Prompt::line( 'Continue? [y/n]' ) );
	}

	public function test_end_of_input_ends_the_line_and_returns_null(): void {
		Functions\when( 'cli\prompt' )->alias(
			static function (): string {
				throw new \Exception( 'Caught ^D during input' );
			}
		);
		Functions\expect( 'cli\line' )->twice();

		$this->assertNull( Prompt::line( 'Continue? [y/n]' ) );
		$this->assertNull( Prompt::hidden( 'Archive password' ) );
	}

	/**
	 * P-I2: an optional secret (a key without passphrase) can be left empty with Enter.
	 */
	public function test_an_optional_hidden_prompt_takes_an_empty_answer(): void {
		Functions\expect( 'cli\prompt' )->once()->with( 'Passphrase', \Mockery::mustBe( '' ), ': ', true )->andReturn( '' );

		$this->assertSame( '', Prompt::hidden( 'Passphrase', true ) );
	}

	public function test_a_required_hidden_prompt_asks_until_answered(): void {
		Functions\expect( 'cli\prompt' )->once()->with( 'Password', \Mockery::mustBe( false ), ': ', true )->andReturn( 'pw' );

		$this->assertSame( 'pw', Prompt::hidden( 'Password' ) );
	}
}

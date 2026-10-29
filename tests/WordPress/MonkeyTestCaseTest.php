<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress;

use LightweightPlugins\Migration\Tests\Fixtures\Monkey\MisplacedMonkeyCase;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use PHPUnit\Framework\AssertionFailedError;

final class MonkeyTestCaseTest extends MonkeyTestCase {

	public function test_translation_functions_return_their_input_and_escaping_ones_escape(): void {
		$this->assertSame( 'Hello', __( 'Hello', 'lw-migration' ) );
		$this->assertSame( 'a&lt;b', esc_html__( 'a<b', 'lw-migration' ) );
	}

	public function test_wordpress_constants_exist(): void {
		$this->assertSame( 86400, DAY_IN_SECONDS );
		$this->assertStringEndsWith( 'lwm-unit-wp-content', WP_CONTENT_DIR );
		$this->assertStringEndsWith( 'lw-migration.php', LW_MIGRATION_FILE );
	}

	public function test_fake_options(): void {
		FakeOptions::install( [ 'a' => 1 ] );
		$this->assertSame( 1, get_option( 'a' ) );
		$this->assertFalse( add_option( 'a', 2 ) );
		$this->assertSame( 1, get_option( 'a' ), 'A refused add_option keeps the original value.' );
		$this->assertTrue( add_option( 'b', 3 ) );
		update_option( 'a', 4 );
		$this->assertSame( 4, get_option( 'a' ) );
		$this->assertTrue( delete_option( 'b' ) );
		$this->assertSame( 'none', get_option( 'b', 'none' ) );
	}

	public function test_fake_update_option_is_a_no_op_for_an_unchanged_value(): void {
		FakeOptions::install( [ 'a' => 'x' ] );
		$this->assertFalse( update_option( 'a', 'x' ) );
		$this->assertFalse( update_option( 'missing', false ), 'Storing false in a missing option writes nothing, like WordPress.' );
		$this->assertArrayNotHasKey( 'missing', FakeOptions::$values );
		$this->assertTrue( update_option( 'a', 'y' ) );
		$this->assertSame( 'y', get_option( 'a' ) );
	}

	public function test_fake_options_start_empty_in_every_test(): void {
		FakeOptions::$values['leftover'] = 1;
		$this->tearDown();
		$this->assertSame( [], FakeOptions::$values );
		$this->setUp();
	}

	public function test_a_brain_monkey_test_outside_the_wordpress_suite_fails(): void {
		self::assertPlacedUnderWordPressSuite( self::class );
		$this->expectException( AssertionFailedError::class );
		$this->expectExceptionMessage( 'is not under tests/WordPress/' );
		( new MisplacedMonkeyCase( 'checkPlacement' ) )->checkPlacement();
	}
}

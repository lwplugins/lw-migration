<?php
/**
 * Base test case wiring Brain Monkey setup/teardown.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * WordPress functions are stubbed per test; translation functions return their input
 * and escaping ones escape it like WordPress.
 *
 * Brain Monkey loads Patchwork, whose file stream wrapper stays active for the rest of
 * the process (it shifts touch() times and instruments every class loaded later). So
 * these tests live only under tests/WordPress/ and run in their own PHPUnit process
 * (suite "wordpress"), never next to the pure unit tests.
 */
abstract class MonkeyTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/** Whether Monkey\setUp() ran, so tearDown() never calls into an unloaded Patchwork. */
	private bool $monkeyStarted = false;

	protected function setUp(): void {
		parent::setUp();
		self::assertPlacedUnderWordPressSuite( static::class );
		Monkey\setUp();
		$this->monkeyStarted = true;
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		FakeOptions::reset();
		FakeWpdb::reset();
		AddressGuard::useResolver( null ); // SafeWeb::install() sets one.
		if ( $this->monkeyStarted ) {
			Monkey\tearDown();
		}
		parent::tearDown();
	}

	/**
	 * Fails before Patchwork is loaded when a Brain Monkey test sits outside tests/WordPress/.
	 *
	 * @param class-string $testClass Concrete test class.
	 */
	protected static function assertPlacedUnderWordPressSuite( string $testClass ): void {
		$file  = (string) realpath( (string) ( new \ReflectionClass( $testClass ) )->getFileName() );
		$suite = (string) realpath( __DIR__ ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $file, $suite ) ) {
			self::fail( sprintf( '%s uses Brain Monkey but is not under tests/WordPress/ (%s): it would load Patchwork into another suite.', $testClass, $file ) );
		}
	}
}

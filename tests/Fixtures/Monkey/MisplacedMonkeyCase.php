<?php
/**
 * A Brain Monkey test case outside tests/WordPress/, for the placement guard test.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Fixtures\Monkey;

use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Never collected by PHPUnit (file name does not end in Test.php).
 */
final class MisplacedMonkeyCase extends MonkeyTestCase {

	public function checkPlacement(): void {
		self::assertPlacedUnderWordPressSuite( self::class );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The unit suite runs without Brain Monkey: Patchwork's stream wrapper would change
 * file times and instrument the classes under test. Brain Monkey tests belong in
 * tests/WordPress/ (own PHPUnit process).
 */
final class SuiteIsolationTest extends TestCase {

	private const BRAIN_MONKEY = '/Brain\\\\Monkey|Patchwork|MonkeyTestCase|FakeOptions|FakeWpdb/';

	private const MONKEY_GROUP = "/#\\[Group\\(\\s*'monkey'\\s*\\)\\]/";

	public function test_no_unit_test_uses_brain_monkey_or_its_fakes(): void {
		$offenders = [];
		foreach ( self::phpFiles( __DIR__ ) as $path => $code ) {
			if ( __FILE__ !== $path && 1 === preg_match( self::BRAIN_MONKEY, $code ) ) {
				$offenders[] = $path;
			}
		}

		$this->assertSame( [], $offenders, 'Move these to tests/WordPress/.' );
	}

	public function test_integration_tests_use_brain_monkey_only_in_the_monkey_group(): void {
		$offenders = [];
		foreach ( self::phpFiles( dirname( __DIR__ ) . '/Integration' ) as $path => $code ) {
			if ( 1 === preg_match( self::BRAIN_MONKEY, $code ) && 1 !== preg_match( self::MONKEY_GROUP, $code ) ) {
				$offenders[] = $path;
			}
		}

		$this->assertSame( [], $offenders, "Add #[Group( 'monkey' )]: bin/test-db.sh runs that group in its own process." );
	}

	// Only sees what ran before it in this process; the static scans above are the real guard.
	public function test_patchwork_is_not_loaded_in_this_process(): void {
		$this->assertFalse( function_exists( 'Patchwork\redefine' ) );
	}

	/**
	 * @param string $dir Folder to scan recursively.
	 * @return array<string, string> Path => source of every PHP file, sorted by path.
	 */
	private static function phpFiles( string $dir ): array {
		$found = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$found[ $file->getPathname() ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		ksort( $found );

		return $found;
	}
}

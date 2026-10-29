<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\PullCommand;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each test runs in its own process: the WP_CLI stand-in keeps state for the
 * whole process. The refusals come before any job is made (the pull itself
 * runs end to end in PushPullE2ETest).
 */
#[RunTestsInSeparateProcesses]
final class PullCommandTest extends MonkeyTestCase {

	private const LINK = '0123456789abcdef';

	private bool $multisite = false;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
	}

	/**
	 * @param array<int, string>   $args  Arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @return string|null The error, or null when the command succeeded.
	 */
	private function cli( array $args, array $assoc ): ?string {
		try {
			( new PullCommand() )( $args, $assoc );
		} catch ( \RuntimeException $e ) {
			return (string) end( \WP_CLI::$errors );
		}

		return null;
	}

	/**
	 * @param array<int, string> $allow Rights.
	 */
	private function link( array $allow ): void {
		( new Links() )->add(
			[
				'id'         => self::LINK,
				'direction'  => Links::OUT,
				'address'    => 'http://b.example',
				'name'       => 'Site B',
				'allow'      => $allow,
				'createdBy'  => [ 'id' => 1, 'login' => 'admin' ],
				'pairedFrom' => '',
			],
			random_bytes( 32 ),
			time(),
			true
		);
	}

	public function test_without_a_terminal_yes_is_needed(): void {
		$this->assertSame( 'Pass --yes to import without a prompt.', $this->cli( [ self::LINK ], [] ) );
	}

	public function test_a_network_has_no_connections(): void {
		$this->multisite = true;

		$this->assertSame( 'Connections work between single sites only.', $this->cli( [ self::LINK ], [ 'yes' => true ] ) );
	}

	public function test_a_link_must_exist_and_allow_the_pull(): void {
		$this->assertSame( 'This connection was removed or no longer works on this site. Pair the two sites again.', $this->cli( [ self::LINK ], [ 'yes' => true ] ) );
		$this->assertSame( 'This connection was removed or no longer works on this site. Pair the two sites again.', $this->cli( [ '../etc' ], [ 'yes' => true ] ) );

		$this->link( [ 'push' ] );
		$this->assertSame( 'This connection does not allow copying that site here.', $this->cli( [ self::LINK ], [ 'yes' => true ] ) );
	}
}

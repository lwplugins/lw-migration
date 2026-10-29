<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\PushCommand;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each test runs in its own process: the WP_CLI stand-in keeps state for the
 * whole process. The refusals come before any job is made; the relayed
 * confirmation is answered by PushCommand::answers().
 */
#[RunTestsInSeparateProcesses]
final class PushCommandTest extends MonkeyTestCase {

	private const LINK = '0123456789abcdef';

	private bool $multisite = false;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'size_format' )->alias( static fn( $bytes ) => $bytes . ' B' );
	}

	/**
	 * @param array<int, string>   $args  Arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @return string|null The error, or null when the command succeeded.
	 */
	private function cli( array $args, array $assoc ): ?string {
		try {
			( new PushCommand() )( $args, $assoc );
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
		$this->assertSame( 'Pass --yes to push without a prompt.', $this->cli( [ self::LINK ], [] ) );
	}

	public function test_a_network_has_no_connections(): void {
		$this->multisite = true;

		$this->assertSame( 'Connections work between single sites only.', $this->cli( [ self::LINK ], [ 'yes' => true ] ) );
	}

	public function test_a_link_must_exist_and_allow_the_push(): void {
		$this->assertSame( 'This connection was removed or no longer works on this site. Pair the two sites again.', $this->cli( [ self::LINK ], [ 'yes' => true ] ) );

		$this->link( [ 'pull' ] );
		$this->assertSame( 'This connection does not allow sending this site there.', $this->cli( [ self::LINK ], [ 'yes' => true ] ) );
	}

	public function test_the_relayed_summary_is_shown_under_the_other_sites_name_and_confirmed_by_yes(): void {
		$job         = new Job( 'aaaaaaaaaaaaaaaa', 'push', [ 'link_import' ], 0, [], [], [], Job::NEEDS_INPUT );
		$job->input  = [
			'kind' => 'confirm',
			'data' => [
				'from'         => "http://a.example\e[2J\r\nFAKE: all good",
				'to'           => 'http://b.example',
				'wordpress'    => [ 'archive' => "7.1.2\e[31m\r", 'site' => '7.1.1' . str_repeat( 'x', 2000 ) ],
				'php'          => 'not a pair', // Whatever the other site sends, only the job view's fields are shown.
				'overwrites'   => [ 'database' => true ],
				'safetyBackup' => true,
				'secret'       => 'never shown',
			],
		];
		$answer = PushCommand::answers( [ 'yes' => true ], false, [ 'id' => self::LINK, 'name' => 'Site B', 'address' => 'http://b.example' ] );

		$this->assertSame( [ 'confirm' => true ], $answer( $job ) );

		$this->assertSame( 'On Site B (http://b.example):', \WP_CLI::$lines[0] );
		$this->assertSame( 'Address: http://a.example[2JFAKE: all good -> http://b.example', \WP_CLI::$lines[1], 'No control characters reach the terminal.' );
		$this->assertStringStartsWith( 'WordPress: 7.1.2[31m in the archive, 7.1.1xxx', \WP_CLI::$lines[2], 'No control characters reach the terminal.' );
		$this->assertLessThan( 600, strlen( \WP_CLI::$lines[2] ), 'Each text of the other site is clipped.' );
		$this->assertContains( 'Will be overwritten: database', \WP_CLI::$lines );
		$this->assertContains( 'A backup of the current database is taken first.', \WP_CLI::$lines );
		$this->assertStringNotContainsString( 'never shown', implode( "\n", \WP_CLI::$lines ) );
	}

	public function test_the_other_site_never_gets_a_password_from_here(): void {
		$job        = new Job( 'aaaaaaaaaaaaaaaa', 'push', [ 'link_import' ], 0, [], [], [], Job::NEEDS_INPUT );
		$job->input = [
			'kind' => 'password',
			'data' => [],
		];
		$answer     = PushCommand::answers( [ 'yes' => true ], false, [ 'id' => self::LINK, 'name' => 'Site B', 'address' => 'http://b.example' ] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The other site asked for a password; a push is never encrypted.' );
		$answer( $job );
	}
}

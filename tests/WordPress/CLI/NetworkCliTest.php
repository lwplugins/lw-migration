<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\CLI\BackupCommand;
use LightweightPlugins\Migration\CLI\BackupDirCommand;
use LightweightPlugins\Migration\CLI\CloudCommand;
use LightweightPlugins\Migration\CLI\DestinationCommand;
use LightweightPlugins\Migration\CLI\Commands;
use LightweightPlugins\Migration\CLI\LinkCommand;
use LightweightPlugins\Migration\CLI\NetworkCli;
use LightweightPlugins\Migration\CLI\PullCommand;
use LightweightPlugins\Migration\CLI\PushCommand;
use LightweightPlugins\Migration\CLI\ResetCommand;
use LightweightPlugins\Migration\CLI\ShareCommand;
use LightweightPlugins\Migration\CLI\UploadCommand;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class NetworkCliTest extends MonkeyTestCase {

	#[RunInSeparateProcess]
	public function test_the_guard_stops_outside_the_main_site(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( false );

		try {
			NetworkCli::guard();
			$this->fail( 'A subsite must be stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( [ MainSite::cliMessage() ], \WP_CLI::$errors );
		}
	}

	#[RunInSeparateProcess]
	public function test_the_guard_lets_single_sites_and_the_main_site_pass(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		Functions\when( 'is_multisite' )->justReturn( false );
		NetworkCli::guard();

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( true );
		NetworkCli::guard();

		$this->assertSame( [], \WP_CLI::$errors );
	}

	#[RunInSeparateProcess]
	public function test_every_command_but_the_container_is_guarded(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';

		Commands::register();

		$this->assertSame( 'lw-migration', \WP_CLI::$commands[0][0] );
		$this->assertSame( [], \WP_CLI::$commands[0][2], 'The container prints help anywhere.' );
		$this->assertCount( 18, \WP_CLI::$commands );
		$named = array_map( static fn( array $command ): array => [ $command[0], $command[1] ], \WP_CLI::$commands );
		$this->assertContains( [ 'lw-migration backup-dir', BackupDirCommand::class ], $named );
		$this->assertSame( [ [ 'lw-migration backup', BackupCommand::class ], [ 'lw-migration backup share', ShareCommand::class ] ], array_slice( $named, 9, 2 ), 'The group is registered before its subcommand.' );
		$this->assertSame( [ 'lw-migration link', LinkCommand::class ], $named[11] );
		$this->assertSame( [ 'lw-migration push', PushCommand::class ], $named[12] );
		$this->assertSame( [ 'lw-migration pull', PullCommand::class ], $named[13] );
		$this->assertSame( [ 'lw-migration reset', ResetCommand::class ], $named[14] );
		$this->assertSame( [ [ 'lw-migration destination', DestinationCommand::class ], [ 'lw-migration upload', UploadCommand::class ], [ 'lw-migration cloud', CloudCommand::class ] ], array_slice( $named, 15, 3 ) );
		foreach ( array_slice( \WP_CLI::$commands, 1 ) as [ $name, , $args ] ) {
			$this->assertSame( [ 'before_invoke' => [ NetworkCli::class, 'guard' ] ], $args, $name );
		}
	}

	public function test_site_urls_are_read_from_the_flag_in_order(): void {
		$this->assertSame( [], NetworkCli::siteUrls( [] ) );
		$this->assertSame( [ 'https://shop.test/', 'http://n.test/b5' ], NetworkCli::siteUrls( [ 'site-url' => ' https://shop.test/ , http://n.test/b5' ] ) );
		foreach ( [ true, '', 'https://a.test,,https://b.test', ' , ' ] as $value ) {
			try {
				NetworkCli::siteUrls( [ 'site-url' => $value ] );
				$this->fail( 'Refused: ' . var_export( $value, true ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Give the new addresses: --site-url=<url>[,<url>...]', $e->getMessage() );
			}
		}
	}
}

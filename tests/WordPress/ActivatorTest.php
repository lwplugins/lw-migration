<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Activator;
use LightweightPlugins\Migration\Maintenance\Cleanup;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class ActivatorTest extends MonkeyTestCase {

	protected function tearDown(): void {
		TempDir::remove( WP_CONTENT_DIR );
		parent::tearDown();
	}

	public function test_activation_creates_the_protected_storage(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$db = FakeWpdb::install();
		FakeOptions::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Cleanup::HOOK );
		Activator::activate();
		$root = WP_CONTENT_DIR . '/' . $db->value( Options::STORAGE_DIR );
		$this->assertFileExists( $root . '/backups/.htaccess' );
		$this->assertFileExists( $root . '/jobs/index.php' );
	}

	public function test_activating_twice_keeps_the_same_folder(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$db = FakeWpdb::install();
		FakeOptions::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( 1 );
		Activator::activate();
		$name = $db->value( Options::STORAGE_DIR );
		Activator::activate();

		$this->assertSame( $name, $db->value( Options::STORAGE_DIR ) );
		$this->assertSame( [ $name ], array_map( 'basename', (array) glob( WP_CONTENT_DIR . '/lw-migration-*' ) ) );
		$this->assertFileExists( WP_CONTENT_DIR . '/' . $name . '/backups/.htaccess' );
	}

	public function test_activation_plans_the_events_of_enabled_schedules_and_never_creates_the_default(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		FakeWpdb::install();
		FakeOptions::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\expect( 'wp_schedule_single_event' )->never();
		Activator::activate();
		$this->assertArrayNotHasKey( Options::SCHEDULES, FakeOptions::$values );

		FakeOptions::$values[ Options::SCHEDULES ] = [
			[
				'id'      => 'aaaaaaaa',
				'enabled' => true,
			],
			[ 'id' => 'bbbbbbbb' ],
		];
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), Events::HOOK, [ 'aaaaaaaa' ] );
		Activator::activate();
	}

	public function test_activation_on_a_subsite_prepares_nothing(): void {
		$this->network( false );
		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_schedule_event' )->never();
		Functions\expect( 'get_option' )->never();

		Activator::activate();

		$this->assertSame( [], is_dir( WP_CONTENT_DIR ) ? array_values( array_diff( (array) scandir( WP_CONTENT_DIR ), [ '.', '..' ] ) ) : [], 'No storage folder.' );
	}

	public function test_network_activation_prepares_the_main_site(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$db = FakeWpdb::install();
		FakeOptions::install();
		$this->network( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Cleanup::HOOK );

		Activator::activate();

		$this->assertFileExists( WP_CONTENT_DIR . '/' . $db->value( Options::STORAGE_DIR ) . '/backups/.htaccess' );
	}

	public function test_deactivation_on_a_subsite_only_clears_a_stray_cleanup_event(): void {
		$this->network( false );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Cleanup::HOOK );
		Functions\expect( 'wp_unschedule_hook' )->never();
		Functions\expect( 'delete_option' )->never();

		Activator::deactivate();
	}

	public function test_deactivation_removes_the_daily_cleanup_and_every_schedule_event(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Cleanup::HOOK );
		Functions\expect( 'wp_unschedule_hook' )->once()->with( Events::HOOK );
		Functions\expect( 'wp_unschedule_hook' )->once()->with( Events::CONTINUE_HOOK );
		Functions\expect( 'delete_option' )->once()->with( Options::SCHEDULE_QUEUE ); // No run of before the deactivation starts after it.
		Activator::deactivate();
	}

	private function network( bool $mainSite ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
	}
}

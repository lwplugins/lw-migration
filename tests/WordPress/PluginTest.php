<?php
/**
 * Tests for Plugin.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Admin\Hub\Hub;
use LightweightPlugins\Migration\Admin\NoticeManager;
use LightweightPlugins\Migration\Plugin;
use LightweightPlugins\Migration\Rest\RouteMap;
use LightweightPlugins\Migration\Schedules\Events;

final class PluginTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		// Hub::init() runs once per process; Brain Monkey forgets the hooks after every test.
		Hub::reset();
	}

	public function test_rest_routes_are_registered_on_rest_api_init(): void {
		Plugin::boot();

		$this->assertNotFalse( has_action( 'rest_api_init', [ RouteMap::class, 'register' ] ) );
	}

	public function test_the_hub_offers_itself_on_every_request(): void {
		Plugin::boot();

		$this->assertNotFalse( has_filter( Hub::CANDIDATES_FILTER, [ Hub::class, 'add_candidate' ] ) );
	}

	public function test_the_admin_page_and_the_notice_isolation_are_wired_on_admin_requests(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Plugin::boot();

		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts' ) );
		$this->assertNotFalse( has_filter( 'admin_body_class' ) );
		$this->assertNotFalse( has_action( 'in_admin_header', [ NoticeManager::class, 'isolate' ] ) );
	}

	public function test_no_admin_screen_hooks_outside_the_admin(): void {
		Plugin::boot();

		$this->assertFalse( has_action( 'admin_enqueue_scripts' ) );
		$this->assertFalse( has_action( 'in_admin_header' ) );
	}

	public function test_schedule_events_are_hooked_on_every_request(): void {
		Plugin::boot();

		$this->assertNotFalse( has_action( Events::HOOK, [ Events::class, 'fire' ] ) );
		$this->assertNotFalse( has_action( Events::CONTINUE_HOOK, [ Events::class, 'continueWork' ] ) );
		$this->assertNotFalse( has_action( 'admin_init', [ Events::class, 'healAll' ] ) );
		$this->assertNotFalse( has_action( 'init', [ Events::class, 'healOnCron' ] ) );
		$this->assertNotFalse( has_action( 'update_option_timezone_string', [ Events::class, 'retime' ] ) );
		$this->assertNotFalse( has_action( 'update_option_gmt_offset', [ Events::class, 'retime' ] ) );
	}
}

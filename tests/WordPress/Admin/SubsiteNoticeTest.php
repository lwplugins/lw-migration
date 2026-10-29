<?php
/**
 * Tests for Admin\SubsiteNotice.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Admin;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Admin\SubsiteNotice;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SubsiteNoticeTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'plugin_basename' )->justReturn( 'lw-migration/lw-migration.php' );
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'activate_plugins' === $cap );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'plugins' ] );
	}

	private function rendered(): string {
		ob_start();
		SubsiteNotice::render();

		return (string) ob_get_clean();
	}

	public function test_a_subsite_activation_gets_one_line_on_the_plugins_screen(): void {
		Functions\expect( 'is_plugin_active_for_network' )->once()->with( 'lw-migration/lw-migration.php' )->andReturn( false );

		$html = $this->rendered();

		$this->assertStringContainsString( 'class="notice notice-warning lw-notice"', $html );
		$this->assertStringContainsString( 'LW Migration does nothing on this site: on a network it works only when it is network-activated, from Network Admin.', $html );
		$this->assertSame( 1, substr_count( $html, '<p>' ) );
	}

	public function test_nothing_when_the_plugin_is_network_active(): void {
		Functions\when( 'is_plugin_active_for_network' )->justReturn( true );

		$this->assertSame( '', $this->rendered() );
	}

	public function test_nothing_on_other_screens_or_for_users_who_cannot_activate_plugins(): void {
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'dashboard' ] );
		$this->assertSame( '', $this->rendered() );

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'plugins' ] );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( '', $this->rendered() );
	}
}

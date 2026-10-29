<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\PlatformNotice;

final class PlatformNoticeTest extends MonkeyTestCase {

	public function test_registers_on_admin_notices(): void {
		PlatformNotice::register();
		$this->assertNotFalse( has_action( 'admin_notices', [ PlatformNotice::class, 'render' ] ) );
	}

	public function test_renders_only_for_users_who_manage_plugins(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		PlatformNotice::render();
		$this->assertSame( '', ob_get_clean() );

		Functions\when( 'current_user_can' )->justReturn( true );
		ob_start();
		PlatformNotice::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '64-bit', $html );
	}
}

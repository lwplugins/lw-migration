<?php
/**
 * Admin notice when PHP is 32-bit.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin does not start on 32-bit PHP; this says so on the admin screens.
 */
final class PlatformNotice {

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'LW Migration is not running: it needs 64-bit PHP. Ask your host to switch the site to a 64-bit PHP build.', 'lw-migration' )
		);
	}
}

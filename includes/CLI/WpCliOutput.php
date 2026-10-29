<?php
/**
 * Output through WP-CLI.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Lines go to STDOUT, warnings to STDERR.
 */
final class WpCliOutput implements Output {

	public function line( string $text ): void {
		Console::log( $text );
	}

	public function warning( string $text ): void {
		Console::warning( $text );
	}
}

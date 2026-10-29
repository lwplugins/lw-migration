<?php
/**
 * Where the CLI driver writes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI in production, a buffer in tests.
 */
interface Output {

	public function line( string $text ): void;

	public function warning( string $text ): void;
}

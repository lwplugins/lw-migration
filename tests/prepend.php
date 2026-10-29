<?php
/**
 * Defines ABSPATH before Composer's autoloader runs (see lw-scan for the reason).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

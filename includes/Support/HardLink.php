<?php
/**
 * A hard link, where the host allows one.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Some hosts list link() in disable_functions. In PHP 8 a disabled function
 * does not exist, so a bare call is a fatal error instead of a failure the
 * caller's fallback (a copy, an 'x' mode write) could take over from.
 */
final class HardLink {

	/**
	 * @param string $from Existing file.
	 * @param string $to   New name; never replaced.
	 * @return bool False when the link was not made (name taken, no hard links, or link() disabled).
	 */
	public static function make( string $from, string $to ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- link() warns across file systems or where links are unsupported; the caller decides.
		return function_exists( 'link' ) && @link( $from, $to );
	}
}

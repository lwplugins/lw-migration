<?php
/**
 * A deadline that is never reached (WP-CLI, tests).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a resumable operation run to completion in one call.
 */
final class NoDeadline implements Deadline {

	public function reached(): bool {
		return false;
	}
}

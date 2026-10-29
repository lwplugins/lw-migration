<?php
/**
 * An extraction reached its write limit.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by WriteBudget::spend() before the bytes that would pass the limit are written.
 */
final class WriteLimitException extends ArchiveException {
}

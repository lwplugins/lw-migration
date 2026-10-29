<?php
/**
 * A file changed while it was being archived.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Its size or modification time differs from the first open; the entry written so far is stale.
 */
final class SourceChangedException extends SourceReadException {
}

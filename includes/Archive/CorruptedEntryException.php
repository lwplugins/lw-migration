<?php
/**
 * Corrupted entry exception.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * An entry's data cannot be decoded or fails its checksum (also: wrong password).
 */
class CorruptedEntryException extends ArchiveException {
}

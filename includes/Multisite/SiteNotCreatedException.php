<?php
/**
 * WordPress refused a new site before its row existed.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

use LightweightPlugins\Migration\Import\ImportException;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing was created, so the recorded intent must not survive: a site
 * another request makes at that address later would look like ours to the
 * undo (review I1).
 */
class SiteNotCreatedException extends ImportException {
}

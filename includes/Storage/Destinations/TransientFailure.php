<?php
/**
 * A passing failure: try again later.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2: network trouble, 408/429/5xx, a timeout; STALE: settings that
 * changed while they were in use.
 */
final class TransientFailure extends CloudFailure {

	public const NETWORK = 'network';

	public const BUSY = 'busy';

	public const SERVER = 'server';

	/** The caller held settings from before an edit that moved the destination (Destinations::open()); nothing is recorded on the profile. */
	public const STALE = 'stale';
}

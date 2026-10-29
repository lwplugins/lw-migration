<?php
/**
 * Where the providers send the admin back.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E1: the REST callback route of this site (path-only with pretty
 * permalinks, ?rest_route= with plain ones). It changes when the site moves
 * or permalinks are switched; Profile::$connection keeps the one each
 * connect used (plan E16).
 */
final class RedirectUri {

	public const ROUTE = '/oauth/callback';

	public static function current(): string {
		return rest_url( Routes::NAMESPACE . self::ROUTE );
	}
}

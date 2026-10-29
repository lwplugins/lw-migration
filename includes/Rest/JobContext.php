<?php
/**
 * What a job started over REST needs to know about the site and the user.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\DiskSpace;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Moved unchanged out of JobsApi::fromServices() so the cloud routes start
 * their jobs with the same site snapshot and admin (and JobsApi shrinks).
 */
final class JobContext {

	/**
	 * @param Services $services WordPress wiring.
	 * @return \Closure fn(): array{site: \LightweightPlugins\Migration\WordPress\Site, admin: array{id: int, login: string}|null, permalinks: bool, free: int|null}; prepares the storage folder.
	 */
	public static function closure( Services $services ): \Closure {
		return static function () use ( $services ): array {
			$services->storage()->ensure();
			$user = wp_get_current_user();

			return [
				'site'       => SiteReader::read(),
				'admin'      => $user->ID > 0 ? [
					'id'    => (int) $user->ID,
					'login' => (string) $user->user_login,
				] : null,
				'permalinks' => '' !== (string) get_option( 'permalink_structure' ),
				'free'       => DiskSpace::free( $services->storage()->jobsDir() ),
			];
		};
	}
}

<?php
/**
 * Which lw-migration/v1 route goes to which callback, behind which guard.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Multisite\MainSite;

defined( 'ABSPATH' ) || exit;

/**
 * The routing table, registered on rest_api_init; the callbacks of the
 * routes below live in Routes. Every route is guarded by Permission::check,
 * except the download link: it carries a download token instead of the REST
 * nonce and has its own check (Permission::download). There is no public
 * route, and none is listed in the REST index. On a network the routes exist
 * on the main site only (the backups and the jobs are network-wide; a subsite
 * admin reaches nothing). The schedule routes are registered by
 * ScheduleRoutes (same rules; the loopback route is the second exception:
 * Permission::loopback), the transfer links by ShareRoutes (the third:
 * Permission::share), the connections by LinkRoutes, the site list by
 * NetworkRoutes. The signed routes another site calls without a login
 * (`/link/pair`, `/link/hello`, `DELETE /link`, and its push, pull and job
 * routes under `/link/`) are registered by LinkPeerRoutes and guarded by
 * Link\Guard (single sites only). A job such a site drives here is never
 * ticked, answered or fed through this site's job routes (ruling I5).
 */
final class RouteMap {

	public static function register(): void {
		if ( ! MainSite::isContext() ) {
			return;
		}
		$routes = [
			'/jobs'                   => [ [ 'POST', 'createJob' ] ],
			'/jobs/active'            => [ [ 'GET', 'activeJob' ] ],
			Routes::JOB               => [ [ 'GET', 'getJob' ], [ 'DELETE', 'cancelJob' ] ],
			Routes::JOB . '/tick'     => [ [ 'POST', 'tickJob' ] ],
			Routes::JOB . '/input'    => [ [ 'POST', 'answerJob' ] ],
			Routes::JOB . '/upload'   => [ [ 'POST', 'uploadChunk' ] ],
			'/backups'                => [ [ 'GET', 'listBackups' ], [ 'DELETE', 'deleteBackup' ] ],
			'/backups/label'          => [ [ 'POST', 'labelBackup' ] ],
			'/backups/entries'        => [ [ 'GET', 'backupEntries' ] ],
			'/backups/contents'       => [ [ 'GET', 'backupContents' ] ],
			'/backups/download-token' => [ [ 'POST', 'downloadToken' ] ],
			Routes::DOWNLOAD          => [ [ 'GET', 'downloadBackup' ] ],
			'/settings'               => [ [ 'GET', 'getSettings' ], [ 'POST', 'saveSettings' ] ],
			'/backup-dir'             => [ [ 'GET', 'getBackupDir' ], [ 'POST', 'moveBackups' ] ],
			'/overview'               => [ [ 'GET', 'getOverview' ] ],
			'/export/estimate'        => [ [ 'GET', 'getEstimate' ] ],
		];
		foreach ( $routes as $route => $endpoints ) {
			$guard = Routes::DOWNLOAD === $route ? 'download' : 'check';
			register_rest_route(
				Routes::NAMESPACE,
				$route,
				array_map(
					static fn( array $endpoint ): array => [
						'methods'             => $endpoint[0],
						'callback'            => [ Routes::class, $endpoint[1] ],
						'permission_callback' => [ Permission::class, $guard ],
						'show_in_index'       => false,
					],
					$endpoints
				)
			);
		}
		ScheduleRoutes::register();
		ShareRoutes::register();
		LinkRoutes::register();
		LinkPeerRoutes::register();
		NetworkRoutes::register();
		ResetRoutes::register();
		DestinationRoutes::register();
	}
}

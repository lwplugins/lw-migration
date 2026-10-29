<?php
/**
 * The sites of the main network, a page at a time.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The shared page loop of the plugins, themes and media resets on a network:
 * every site of the main network by ascending id, resumable from the job's
 * cursor. Pages go by the last id done, never by an offset, so a site added
 * or removed between two requests never makes the loop skip another.
 */
final class NetworkSites {

	public const PAGE = 100;

	/**
	 * @param int $after The last site id done (0 for the first page).
	 * @return array<int, int> Site ids of the main network above $after, ascending.
	 */
	public static function page( int $after ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d", get_main_network_id(), $after, self::PAGE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb; ids above the last one, never cached (sites change between requests).

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Calls $perSite for every site after cursor[$key].last, one at a time,
	 * until the deadline; last moves on after each site whose work finished
	 * ($perSite returning false = not yet: the same site comes again next
	 * time, its own progress kept by the caller).
	 *
	 * @param Job           $job      Job (cursor[$key] = {last, done}).
	 * @param string        $key      Cursor key of this loop.
	 * @param \Closure      $perSite  fn( int $siteId ): ?bool; false = that site's work is not finished.
	 * @param Deadline      $deadline Time budget.
	 * @param \Closure|null $page     fn( int $after ): array<int, int>; null = page().
	 * @return bool True once a page came back empty (every site done).
	 */
	public static function advance( Job $job, string $key, \Closure $perSite, Deadline $deadline, ?\Closure $page = null ): bool {
		$at   = is_array( $job->cursor[ $key ] ?? null ) ? $job->cursor[ $key ] : [];
		$last = (int) ( $at['last'] ?? 0 );
		if ( ! empty( $at['done'] ) ) {
			return true;
		}
		while ( true ) {
			$ids = null === $page ? self::page( $last ) : array_map( 'intval', (array) $page( $last ) );
			if ( [] === $ids ) {
				$job->cursor[ $key ] = [
					'last' => $last,
					'done' => true,
				];
				return true;
			}
			foreach ( $ids as $id ) {
				if ( false === $perSite( $id ) ) {
					return false;
				}
				$last                = $id;
				$job->cursor[ $key ] = [
					'last' => $last,
					'done' => false,
				];
				if ( $deadline->reached() ) {
					return false;
				}
			}
		}
	}
}

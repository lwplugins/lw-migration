<?php
/**
 * The unfinished upload a job keeps, bound to the connection that started it.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * M7c final review I1. A started upload (cursor.session) remembers the id of
 * the profile's OAuth connection it was started under (cursor.connection;
 * empty for a type without one). Once the profile holds another connection —
 * a reconnect, perhaps to another account — the stored session reads as gone:
 * neither a part nor an abort is sent to the old account's upload address,
 * and the upload starts over under the new connection.
 */
final class UploadCursor {

	/**
	 * The stored session, unless it is damaged or belongs to another connection.
	 *
	 * @param Job     $job     Job.
	 * @param Profile $profile The destination's profile now.
	 */
	public static function session( Job $job, Profile $profile ): ?UploadSession {
		$session = UploadSession::fromArray( (array) ( $job->cursor['session'] ?? [] ) );
		$bound   = $job->cursor['connection'] ?? '';
		if ( null === $session || ! is_string( $bound ) || self::connection( $profile ) !== $bound ) {
			return null;
		}

		return $session;
	}

	/**
	 * Keeps a session just started, bound to the profile's connection.
	 *
	 * @param Job           $job     Job.
	 * @param Profile       $profile The profile the upload was started with.
	 * @param UploadSession $session The new session.
	 */
	public static function start( Job $job, Profile $profile, UploadSession $session ): void {
		$job->cursor['session']    = $session->toArray();
		$job->cursor['connection'] = self::connection( $profile );
	}

	private static function connection( Profile $profile ): string {
		return (string) ( $profile->connection['id'] ?? '' );
	}
}

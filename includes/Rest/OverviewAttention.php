<?php
/**
 * What the overview asks the admin to look at.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\FolderRefusedException;

defined( 'ABSPATH' ) || exit;

/**
 * Only from state that is already recorded: a destination that needs a new
 * sign-in, a fingerprint to confirm (OverviewDestinations::fingerprint()),
 * a destination whose last check or upload failed, a backups folder that
 * cannot be used (BackupDirApi's problem), less free space in the backups
 * folder than the newest backup took (the next one is expected to be about
 * as big), and a schedule whose last run failed or never started. Each item
 * names the section that fixes it; the texts are short and translated.
 */
final class OverviewAttention {

	/**
	 * @param array<int, Profile>                             $profiles  Stored destinations.
	 * @param DestinationRegistry                             $registry  Types.
	 * @param string|null                                     $problem   The backups folder's problem (FolderRefusedException reason), null when fine.
	 * @param int|null                                        $free      Free bytes in the backups folder (null = unknown).
	 * @param int|null                                        $expected  Size of the newest backup (null = none).
	 * @param array<int, Schedule>                            $schedules Stored schedules.
	 * @param array<string, array<int, array<string, mixed>>> $logs      Log entries per schedule id, newest first.
	 * @return array<int, array{kind: string, destination: string|null, text: string, action: string}>
	 */
	public static function of( array $profiles, DestinationRegistry $registry, ?string $problem, ?int $free, ?int $expected, array $schedules, array $logs ): array {
		$items = [];
		foreach ( $profiles as $profile ) {
			$item = self::destination( $profile, $registry );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		if ( null !== $problem ) {
			$items[] = self::item(
				'folder',
				null,
				FolderRefusedException::UNPROTECTED === $problem ? __( 'The backups folder lost its protection. Choose it again in Settings.', 'lw-migration' ) : __( 'The backups folder cannot be used right now. Check it in Settings.', 'lw-migration' ),
				'settings'
			);
		}
		if ( null !== $free && null !== $expected && $free < $expected ) {
			/* translators: 1: free space, e.g. 1.2 GB, 2: size of the newest backup */
			$items[] = self::item( 'space', null, sprintf( __( 'Only %1$s is free for backups, less than the newest backup (%2$s). The next one may not fit.', 'lw-migration' ), (string) size_format( $free, 1 ), (string) size_format( $expected, 1 ) ), 'backups' );
		}
		foreach ( $schedules as $schedule ) {
			$last = $logs[ $schedule->id ][0] ?? null;
			if ( null !== $last && in_array( $last['status'] ?? null, [ Job::FAILED, RunRecorder::SKIPPED ], true ) ) {
				/* translators: %s: name of the schedule */
				$items[] = self::item( 'failedRun', null, sprintf( __( 'The last run of the schedule “%s” failed. Its log says why.', 'lw-migration' ), Repository::shownTitle( $schedule ) ), 'schedules' );
			}
		}

		return $items;
	}

	/**
	 * @param Profile             $profile  Destination.
	 * @param DestinationRegistry $registry Types.
	 * @return array{kind: string, destination: string|null, text: string, action: string}|null
	 */
	private static function destination( Profile $profile, DestinationRegistry $registry ): ?array {
		$type = $registry->get( $profile->type );
		if ( Profile::REAUTH === $profile->status ) {
			/* translators: %s: name of the destination */
			return self::item( 'reauth', $profile->id, sprintf( __( '%s needs a new sign-in: the connection expired, so uploads there are skipped.', 'lw-migration' ), $profile->name ), 'destinations' );
		}
		$fingerprint = OverviewDestinations::fingerprint( $profile, $type );
		if ( 'hostKey' === $fingerprint ) {
			/* translators: %s: name of the destination */
			return self::item( 'hostKey', $profile->id, sprintf( __( '%s shows a host key that waits for your confirmation: compare its fingerprint with the server.', 'lw-migration' ), $profile->name ), 'destinations' );
		}
		if ( 'certificate' === $fingerprint ) {
			/* translators: %s: name of the destination */
			return self::item( 'certificate', $profile->id, sprintf( __( '%s shows a certificate that waits for your confirmation: compare its fingerprint with the server.', 'lw-migration' ), $profile->name ), 'destinations' );
		}
		if ( Profile::ERROR === $profile->status ) {
			/* translators: %s: name of the destination */
			return self::item( 'error', $profile->id, sprintf( __( '%s failed at its last check or upload. Open it to see why.', 'lw-migration' ), $profile->name ), 'destinations' );
		}

		return null;
	}

	/**
	 * @param string      $kind        What it is about.
	 * @param string|null $destination Destination id, or null.
	 * @param string      $text        Translated sentence.
	 * @param string      $action      The section that fixes it.
	 * @return array{kind: string, destination: string|null, text: string, action: string}
	 */
	private static function item( string $kind, ?string $destination, string $text, string $action ): array {
		return [
			'kind'        => $kind,
			'destination' => $destination,
			'text'        => $text,
			'action'      => $action,
		];
	}
}

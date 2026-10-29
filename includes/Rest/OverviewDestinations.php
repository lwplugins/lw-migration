<?php
/**
 * The destinations as the overview shows them.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Destinations\ConfirmedFingerprint;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * The fields DestinationView shows without a connection (id, name, type,
 * where, state, last upload), never a setting or a secret, and how many of
 * this site's backups the upload record (CloudCopies) has there. The state
 * is the one the last check or upload recorded on the profile, nothing is
 * contacted: `pending` for a destination never checked, and for one whose
 * server shows a fingerprint (an SFTP host key, an FTPS certificate) that
 * waits for the admin's confirmation (plan D31, issue #5). That wait is not
 * stored apart from the failure: it is the recorded failure of a type with
 * a confirmed fingerprint whose text carries a fingerprint of that type
 * (only the HOST_KEY_* and CERT_* failures print one), so the language of
 * the text does not matter.
 */
final class OverviewDestinations {

	public const OK = 'ok';

	public const REAUTH = 'reauth';

	public const ERROR = 'error';

	public const PENDING = 'pending';

	/**
	 * @param array<int, Profile>                                          $profiles Stored profiles.
	 * @param DestinationRegistry                                          $registry Types.
	 * @param array<string, array<string, array{status: string, at: int}>> $copies   CloudCopies::all().
	 * @return array<int, array{id: string, name: string, type: string, where: string, status: string, lastUpload: int|null, count: int, preset: string|null, mode: string|null}>
	 */
	public static function rows( array $profiles, DestinationRegistry $registry, array $copies ): array {
		$counts = [];
		foreach ( $copies as $where ) {
			foreach ( $where as $id => $copy ) {
				if ( CloudCopies::UP === $copy['status'] ) {
					$counts[ (string) $id ] = ( $counts[ (string) $id ] ?? 0 ) + 1;
				}
			}
		}
		$rows = [];
		foreach ( $profiles as $profile ) {
			$type   = $registry->get( $profile->type );
			$rows[] = [
				'id'         => $profile->id,
				'name'       => $profile->name,
				'type'       => $profile->type,
				'where'      => null === $type ? '' : $type->describe( $profile ),
				'status'     => self::status( $profile, $type ),
				'lastUpload' => $profile->lastUploadAt > 0 ? $profile->lastUploadAt : null,
				'count'      => $counts[ $profile->id ] ?? 0,
				'preset'     => self::setting( $profile, 's3', 'preset' ),
				'mode'       => self::setting( $profile, 'webdav', 'mode' ),
			];
		}

		return $rows;
	}

	/**
	 * One non-secret setting, for the row's icon and type name: the S3
	 * provider or the WebDAV server mode. Nothing else leaves the profile.
	 *
	 * @param Profile $profile Profile.
	 * @param string  $type    The only type that has it.
	 * @param string  $key     Setting key.
	 * @return string|null Value, null for another type or when not set.
	 */
	private static function setting( Profile $profile, string $type, string $key ): ?string {
		$value = $profile->settings[ $key ] ?? null;
		return $type === $profile->type && is_string( $value ) ? $value : null;
	}

	/**
	 * @param Profile              $profile Profile.
	 * @param DestinationType|null $type    Its type.
	 * @return string OK, REAUTH, ERROR or PENDING.
	 */
	public static function status( Profile $profile, ?DestinationType $type ): string {
		if ( Profile::ERROR === $profile->status ) {
			return null === self::fingerprint( $profile, $type ) ? self::ERROR : self::PENDING;
		}

		return [
			Profile::OK     => self::OK,
			Profile::REAUTH => self::REAUTH,
		][ $profile->status ] ?? self::PENDING;
	}

	/**
	 * Which fingerprint waits for the admin's confirmation.
	 *
	 * @param Profile              $profile Profile.
	 * @param DestinationType|null $type    Its type.
	 * @return string|null 'hostKey' (SFTP), 'certificate' (FTPS), or null.
	 */
	public static function fingerprint( Profile $profile, ?DestinationType $type ): ?string {
		if ( Profile::ERROR !== $profile->status || ! $type instanceof ConfirmedFingerprint ) {
			return null;
		}
		preg_match_all( '#[A-Za-z0-9+/=:]{32,}#', $profile->message, $found );
		foreach ( $found[0] as $token ) {
			if ( $type->isFingerprint( rtrim( $token, ':' ) ) ) {
				return 'hostKey' === $type->fingerprintField() ? 'hostKey' : 'certificate';
			}
		}

		return null;
	}
}

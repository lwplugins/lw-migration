<?php
/**
 * Stores the server fingerprint an admin confirmed.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Storage\Destinations\AuthorityBound;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\ConfirmedFingerprint;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\CertificatePin;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D31 and issue #5: the SFTP host key (body {hostKey}) or the FTPS
 * certificate (body {certFingerprint}) is stored only when the server shows
 * exactly that fingerprint on a fresh connection now, then the connection
 * is tested again. The profile is read again after the connection, so an
 * edit or a delete made meanwhile is kept, and a fingerprint checked against
 * one server is never stored for another. When the server shows yet another
 * fingerprint, nothing is stored (409 lw_migration_host_key_differs, the
 * code of both kinds).
 */
final class DestinationTrust {

	/**
	 * @param Destinations    $destinations Profiles, secrets, types.
	 * @param DestinationsApi $api          Lookups, the test and its recorded failure.
	 */
	public function __construct( private Destinations $destinations, private DestinationsApi $api ) {
	}

	/**
	 * @param string               $id   Profile id.
	 * @param array<string, mixed> $body {hostKey} or {certFingerprint}: the type's fingerprint field.
	 * @return array<string, mixed> The view after a passed test.
	 * @throws RestException 400 for a malformed fingerprint or a type without one, 404, 409 when the server shows another one (data: the field, hostKeyType for a host key), 422 when the connection fails otherwise.
	 */
	public function trust( string $id, array $body ): array {
		$profile = $this->api->find( $id );
		$type    = $this->destinations->registry()->get( $profile->type );
		$field   = $type instanceof ConfirmedFingerprint ? $type->fingerprintField() : 'hostKey';
		foreach ( array_keys( $body ) as $key ) {
			if ( $field !== $key ) {
				throw RestException::invalidField( (string) $key );
			}
		}
		if ( ! $type instanceof ConfirmedFingerprint ) {
			throw RestException::invalid( __( 'This destination has no host key to confirm.', 'lw-migration' ), 'hostKey' );
		}
		$wanted = is_string( $body[ $field ] ?? null ) ? trim( $body[ $field ] ) : '';
		if ( 'certFingerprint' === $field ) {
			$wanted = CertificatePin::normalize( $wanted ) ?? $wanted; // Lower-case hex, as typed or printed by openssl, is the same certificate.
		}
		if ( ! $type->isFingerprint( $wanted ) ) {
			throw RestException::invalid( 'hostKey' === $field ? __( 'This is not a SHA256 host key fingerprint.', 'lw-migration' ) : __( 'This is not a SHA-256 certificate fingerprint.', 'lw-migration' ), $field );
		}
		try {
			$this->destinations->open( $profile )->test();
			$this->destinations->profiles()->mark( $id, Profile::OK );
			return $this->api->view( $this->api->find( $id ) ); // The stored fingerprint already is the one the server shows.
		} catch ( PermanentFailure $e ) {
			if ( ! in_array( $e->reason, PermanentFailure::UNCONFIRMED, true ) || ! isset( $e->data[ $field ] ) ) {
				throw $this->api->failed( $profile, $e );
			}
			if ( $e->data[ $field ] !== $wanted ) {
				throw $this->differs( $field, $e->data );
			}
			$keyType = (string) ( $e->data['hostKeyType'] ?? '' );
		} catch ( CloudFailure $e ) {
			throw $this->api->failed( $profile, $e );
		}
		$this->store( $profile, $field, $wanted, $keyType );

		return $this->api->test( $id );
	}

	/**
	 * @param string               $field The fingerprint field.
	 * @param array<string, mixed> $data  The failure's data.
	 */
	private function differs( string $field, array $data ): RestException {
		$shown = [ $field => (string) ( $data[ $field ] ?? '' ) ];
		if ( 'hostKey' === $field ) {
			return new RestException( 'lw_migration_host_key_differs', __( 'The server now shows another host key than the one you confirmed, so nothing was stored. Check the connection again.', 'lw-migration' ), 409, $shown + [ 'hostKeyType' => (string) ( $data['hostKeyType'] ?? '' ) ] );
		}

		return new RestException( 'lw_migration_host_key_differs', __( 'The server now shows another certificate than the one you confirmed, so nothing was stored. Check the connection again.', 'lw-migration' ), 409, $shown );
	}

	/**
	 * Writes the confirmed fingerprint into the profile as it is now (P-I3:
	 * an edit made during the connection is kept, a deleted profile stays
	 * deleted); the state starts over until the test that follows.
	 *
	 * @param Profile $probed  The profile as it was when the fingerprint was checked.
	 * @param string  $field   The fingerprint field.
	 * @param string  $value   The confirmed fingerprint.
	 * @param string  $keyType A host key's type ('' = unknown or none), P-M8.
	 * @throws RestException 404 when the profile is gone, 409 when its server changed meanwhile or it cannot be written.
	 */
	private function store( Profile $probed, string $field, string $value, string $keyType ): void {
		$fresh = $this->api->find( $probed->id );
		$type  = $this->destinations->registry()->get( $fresh->type );
		$same  = $type instanceof AuthorityBound ? $type->authority( $probed->settings ) === $type->authority( $fresh->settings ) : ( $fresh->settings['host'] ?? null ) === ( $probed->settings['host'] ?? null ) && ( $fresh->settings['port'] ?? null ) === ( $probed->settings['port'] ?? null );
		if ( ! $same ) {
			throw new RestException( 'lw_migration_destination_changed', 'hostKey' === $field ? __( 'The address of this destination changed while its host key was checked, so nothing was stored. Check the connection again.', 'lw-migration' ) : __( 'The address of this destination changed while its certificate was checked, so nothing was stored. Check the connection again.', 'lw-migration' ), 409 );
		}
		$fresh->settings[ $field ] = $value;
		if ( 'hostKey' === $field ) {
			$fresh->settings['hostKeyType'] = $keyType;
			if ( '' === $keyType ) {
				unset( $fresh->settings['hostKeyType'] );
			}
		}
		try {
			$exists = $this->destinations->profiles()->replace( $fresh, true );
		} catch ( \RuntimeException $e ) {
			throw new RestException( 'lw_migration_destination_storage', __( 'The destination could not be saved. Try again.', 'lw-migration' ), 409 );
		}
		if ( ! $exists ) {
			throw RestException::destinationMissing();
		}
	}
}

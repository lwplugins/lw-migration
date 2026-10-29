<?php
/**
 * Opens a stored destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that joins a profile, its sealed secrets and its type. A
 * profile whose secrets do not open becomes `reauth` here (spec 21.3: "nem
 * csendes hiba"); a type that is unknown or unavailable on this server is
 * INVALID. The opened secrets go straight into the type's client and are
 * never returned. Secrets sealed for another server than the one the given
 * profile names (SecretBinding) never open: the caller holds settings from
 * before an edit (or reads in the middle of one) and gets a passing STALE
 * failure, nothing recorded; the next attempt reads the profile again.
 * Secrets that do not open at all mark the profile `reauth`, but only while
 * it still names the server the caller used.
 */
final class Destinations {

	public function __construct( private ProfileRepository $profiles, private SecretVault $vault, private DestinationRegistry $registry ) {
	}

	public static function fromWordPress(): self {
		return new self( new ProfileRepository(), new SecretVault(), DestinationRegistry::fromWordPress() );
	}

	public function profiles(): ProfileRepository {
		return $this->profiles;
	}

	public function vault(): SecretVault {
		return $this->vault;
	}

	public function registry(): DestinationRegistry {
		return $this->registry;
	}

	public function find( string $id ): ?Profile {
		return $this->profiles->find( $id );
	}

	/**
	 * The server the profile's secrets are sealed for (SecretBinding).
	 *
	 * @param Profile $profile Profile.
	 * @return string|null Null for an unknown type or a type without a server of its own.
	 */
	public function binding( Profile $profile ): ?string {
		$type = $this->registry->get( $profile->type );

		return null === $type ? null : SecretBinding::of( $type, $profile->settings );
	}

	/**
	 * Only credentials that no longer open change the profile (to `reauth`);
	 * any other refusal leaves its state alone, and a caller that records the
	 * failure marks the profile `error`.
	 *
	 * @param Profile $profile Profile.
	 * @throws PermanentFailure INVALID for an unknown or unavailable type or a server without sodium, REAUTH when the secrets do not open (a TransientFailure STALE instead when they were stored for another server).
	 */
	public function open( Profile $profile ): DestinationInterface {
		$type = $this->registry->get( $profile->type );
		if ( null === $type ) {
			throw new PermanentFailure( __( 'This destination\'s type is not known to this version of LW Migration.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$reason = $type->unavailable();
		if ( null !== $reason ) {
			throw new PermanentFailure( $reason, PermanentFailure::INVALID );
		}
		if ( ! $this->vault->available() && [] !== $this->vault->fields( $profile->id ) ) {
			// The server's fault, not the stored credentials': open() does not touch the profile (never `reauth`).
			throw new PermanentFailure( __( 'This server cannot read the stored credentials of this destination: PHP lacks the encryption support (the sodium functions) they need.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$binding = SecretBinding::of( $type, $profile->settings );
		$secrets = $this->vault->open( $profile->id, $binding );
		if ( null === $secrets ) {
			throw $this->unopened( $type, $profile, $binding );
		}

		return $type->make( $profile, $secrets );
	}

	/**
	 * @param DestinationType $type    Type.
	 * @param Profile         $profile The profile the caller holds.
	 * @param string|null     $binding Its SecretBinding.
	 */
	private function unopened( DestinationType $type, Profile $profile, ?string $binding ): CloudFailure {
		if ( null !== $binding && $this->vault->boundElsewhere( $profile->id, $binding ) ) {
			return new TransientFailure( __( 'The settings of this destination were changed while it was in use. Try again.', 'lw-migration' ), TransientFailure::STALE );
		}
		$message = __( 'The stored credentials of this destination can no longer be read (the site\'s security keys changed, or the site was moved). Enter them again.', 'lw-migration' );
		$this->profiles->mark( $profile->id, Profile::REAUTH, $message, null, static fn( Profile $stored ): bool => SecretBinding::of( $type, $stored->settings ) === $binding );

		return new PermanentFailure( $message, PermanentFailure::REAUTH );
	}

	/**
	 * Deletes the profile and its secrets.
	 *
	 * @param string $id Profile id.
	 * @return bool Whether a profile was deleted.
	 * @throws \RuntimeException When the secrets cannot be removed (the profile is kept then).
	 */
	public function forget( string $id ): bool {
		$this->vault->forget( $id );

		return $this->profiles->delete( $id );
	}
}

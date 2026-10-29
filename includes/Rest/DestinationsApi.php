<?php
/**
 * The destinations of the admin screens.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\GrantWithdrawal;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.3/21.6. Saving stores the secrets first (a server without sodium
 * refuses before anything is written), sealed for the server the new
 * settings name (SecretBinding), then the profile; a refused profile (the
 * 21st) leaves its fresh secrets behind only under an id nothing names, and
 * they are removed at once; a refused edit that moves the destination
 * removes the secrets it stored for the new server (the stored profile
 * still names the old one, and they never open for it). The connection
 * test writes, reads back and deletes a small file (ProbeFile) and records
 * the result on the profile (ok / error / reauth); new settings or secrets
 * turn the state back to `new`. Deleting a profile keeps the schedules that
 * name it (their run skips it with a warning, spec 21.3) and answers how
 * many do; when the record of its uploads cannot be cleared, the answer
 * says so (spec 13). An id that is not 8 hex digits is never looked up
 * (404). An SFTP host key or an FTPS certificate fingerprint is stored only
 * by trust() (DestinationTrust), when the server shows exactly that one now
 * (plan D31, issue #5), or set by the admin with the settings; a new server
 * forgets the old one. Before the
 * tokens of an OAuth profile are forgotten (a delete, or an edit of its
 * Client ID, mode or grant settings), its grant is withdrawn at the
 * provider, best effort (GrantWithdrawal, issue #6): what could not be
 * withdrawn is in the delete's warnings, or on the edited profile's state
 * line, with the page where the account owner removes the app's access.
 */
final class DestinationsApi {

	/**
	 * @param Destinations $destinations Profiles, secrets, types.
	 * @param \Closure     $schedules    fn(): array<int, Schedule> — the stored schedules.
	 */
	public function __construct( private Destinations $destinations, private \Closure $schedules ) {
	}

	public static function fromServices( Services $services ): self {
		return new self( Destinations::fromWordPress(), static fn(): array => $services->schedules()->existing() );
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, types: array<int, array<string, mixed>>}
	 */
	public function all(): array {
		$schedules = ( $this->schedules )();

		return [
			'items' => array_map( fn( Profile $p ): array => $this->viewWith( $p, $schedules ), $this->destinations->profiles()->all() ),
			'types' => $this->destinations->registry()->schema(),
		];
	}

	/**
	 * @param array<string, mixed> $body Request.
	 * @return array<string, mixed> The new view.
	 * @throws RestException On a refused request.
	 */
	public function create( #[\SensitiveParameter] array $body ): array {
		$input   = DestinationInput::apply( null, $this->destinations->registry(), $body );
		$profile = $input['profile'];
		while ( null !== $this->destinations->find( $profile->id ) ) {
			$profile->id = Profile::newId(); // A clash would replace that profile and its secrets.
		}

		return $this->save( $profile, $input['secrets'], true, false );
	}

	/**
	 * @param string               $id   Profile id.
	 * @param array<string, mixed> $body Request.
	 * @return array<string, mixed>
	 * @throws RestException When it does not exist or the request is refused.
	 */
	public function update( string $id, #[\SensitiveParameter] array $body ): array {
		$current = $this->find( $id );
		$input   = $this->input( $current, $body );
		self::forgetHostKey( $current, $input['profile'] );
		$notice   = null;
		$withdraw = null;
		if ( [] !== array_intersect( $input['forget'], TokenKeeper::FIELDS ) ) {
			$held     = $this->destinations->vault()->open( $id ); // The old app's secret too: the edit may replace it.
			$withdraw = function () use ( $current, $held, &$notice ): void {
				$notice = ( new GrantWithdrawal( $this->destinations ) )->withdraw( $current, $held );
			};
		}
		$view = $this->save( $input['profile'], $input['secrets'], false, [] !== $input['secrets'] || $current->settings !== $input['profile']->settings, $input['forget'], $withdraw );
		if ( null === $notice ) {
			return $view;
		}
		$this->destinations->profiles()->mark( $id, Profile::NEW, $notice );

		return $this->view( $this->find( $id ) );
	}

	/**
	 * DestinationInput::apply() over the stored secret fields. Secrets that
	 * open only for another server than the edited settings name (left by a
	 * refused move whose cleanup failed too) do not count as stored: the
	 * edit asks for them again, and the ones it does not resend are dropped.
	 *
	 * @param Profile              $current Stored profile.
	 * @param array<string, mixed> $body    Request.
	 * @return array{profile: Profile, secrets: array<string, string>, forget: array<int, string>}
	 * @throws RestException On a refused request.
	 */
	private function input( Profile $current, #[\SensitiveParameter] array $body ): array {
		$registry = $this->destinations->registry();
		$stored   = $this->destinations->vault()->fields( $current->id );
		$input    = DestinationInput::apply( $current, $registry, $body, $stored );
		$binding  = $this->destinations->binding( $input['profile'] );
		if ( [] === $stored || null === $binding || ! $this->destinations->vault()->boundElsewhere( $current->id, $binding ) ) {
			return $input;
		}
		$input           = DestinationInput::apply( $current, $registry, $body );
		$input['forget'] = array_values( array_unique( array_merge( $input['forget'], array_diff( $stored, array_keys( $input['secrets'] ) ) ) ) );

		return $input;
	}

	/**
	 * @param string $id Profile id.
	 * @return array<string, mixed> {deleted: true, usedBy: int, warnings?: array<int, string>}
	 * @throws RestException When it does not exist or cannot be removed.
	 */
	public function delete( string $id ): array {
		$notice = ( new GrantWithdrawal( $this->destinations ) )->withdraw( $this->find( $id ) );
		$used   = self::usedBy( ( $this->schedules )(), $id );
		try {
			$gone = $this->destinations->forget( $id );
		} catch ( \RuntimeException $e ) {
			throw new RestException( 'lw_migration_destination_storage', $e->getMessage(), 409 );
		}
		if ( ! $gone && null !== $this->destinations->find( $id ) ) {
			throw new RestException( 'lw_migration_destination_storage', __( 'The destination could not be removed. Try again.', 'lw-migration' ), 409 );
		}
		$answer = [
			'deleted' => true,
			'usedBy'  => $used,
		];
		if ( ! CloudCopies::forgetDestination( $id ) ) {
			$answer['warnings'] = [ __( 'The destination was removed, but the record of the backups uploaded to it could not be cleared, so the backups list may still show them there.', 'lw-migration' ) ];
		}
		if ( null !== $notice ) {
			$answer['warnings'][] = $notice;
		}

		return $answer;
	}

	/**
	 * @param string $id Profile id.
	 * @return array<string, mixed> The view after a passed test.
	 * @throws RestException 404, or 422 lw_migration_destination_failed.
	 */
	public function test( string $id ): array {
		$profile = $this->find( $id );
		try {
			$this->destinations->open( $profile )->test();
		} catch ( CloudFailure $e ) {
			throw $this->failed( $profile, $e );
		}
		$this->destinations->profiles()->mark( $id, Profile::OK );

		return $this->view( $this->find( $id ) );
	}

	/**
	 * Stores the server fingerprint the user confirmed (an SFTP host key, an
	 * FTPS certificate) and tests again: DestinationTrust.
	 *
	 * @param string               $id   Profile id.
	 * @param array<string, mixed> $body {hostKey} or {certFingerprint}.
	 * @return array<string, mixed> The view after a passed test.
	 * @throws RestException 400, 404, 409 or 422 (DestinationTrust::trust()).
	 */
	public function trust( string $id, array $body ): array {
		return ( new DestinationTrust( $this->destinations, $this ) )->trust( $id, $body );
	}

	/**
	 * @param string $id Profile id (checked against Profile::ID_PATTERN before any lookup).
	 * @throws RestException 404 lw_migration_destination_missing.
	 */
	public function find( string $id ): Profile {
		$profile = 1 === preg_match( Profile::ID_PATTERN, $id ) ? $this->destinations->find( $id ) : null;
		if ( null === $profile ) {
			throw RestException::destinationMissing();
		}

		return $profile;
	}

	/**
	 * @param Profile $profile Profile.
	 * @return array<string, mixed>
	 */
	public function view( Profile $profile ): array {
		return $this->viewWith( $profile, ( $this->schedules )() );
	}

	/**
	 * @param array<int, Schedule> $schedules Stored schedules.
	 * @param string               $id        Profile id.
	 */
	public static function usedBy( array $schedules, string $id ): int {
		return count( array_filter( $schedules, static fn( Schedule $s ): bool => in_array( $id, array_column( $s->destinations, 'id' ), true ) ) );
	}

	/**
	 * @param Profile              $profile   Profile.
	 * @param array<int, Schedule> $schedules Stored schedules.
	 * @return array<string, mixed>
	 */
	private function viewWith( Profile $profile, array $schedules ): array {
		return DestinationView::of( $profile, $this->destinations->registry()->get( $profile->type ), $this->destinations->vault()->fields( $profile->id ), self::usedBy( $schedules, $profile->id ) );
	}

	/**
	 * Records a failed connection on the profile and turns it into the REST
	 * answer. A credentials refusal marks `reauth` only while the profile
	 * holds the connection the test used (a reconnect meanwhile stays new);
	 * a test of settings an edit replaced meanwhile (STALE) records nothing.
	 *
	 * @param Profile      $profile The profile the test opened.
	 * @param CloudFailure $e       Why.
	 */
	public function failed( Profile $profile, CloudFailure $e ): RestException {
		if ( $e instanceof TransientFailure && TransientFailure::STALE === $e->reason ) {
			return RestException::destinationFailed( $e );
		}
		if ( PermanentFailure::REAUTH === $e->reason ) {
			$this->destinations->profiles()->revoked( $profile->id, $e->getMessage(), $profile->connection, false );
		} else {
			$this->destinations->profiles()->mark( $profile->id, Profile::ERROR, $e->getMessage() );
		}

		return RestException::destinationFailed( $e );
	}

	/**
	 * P-M14: another host or port is another server, so the key confirmed for
	 * the old one is dropped unless the edit sends a different one; a key
	 * that changed drops the stored key type, the same key keeps it.
	 *
	 * @param Profile $current Stored profile.
	 * @param Profile $edited  The edit (changed in place).
	 */
	private static function forgetHostKey( Profile $current, Profile $edited ): void {
		$old = $current->settings['hostKey'] ?? null;
		if ( null === $old ) {
			return;
		}
		$moved = ( $current->settings['host'] ?? null ) !== ( $edited->settings['host'] ?? null ) || ( $current->settings['port'] ?? null ) !== ( $edited->settings['port'] ?? null );
		if ( $moved && ( $edited->settings['hostKey'] ?? null ) === $old ) {
			unset( $edited->settings['hostKey'] );
		}
		if ( ( $edited->settings['hostKey'] ?? null ) !== $old ) {
			unset( $edited->settings['hostKeyType'] );
		} elseif ( isset( $current->settings['hostKeyType'] ) ) {
			$edited->settings['hostKeyType'] = $current->settings['hostKeyType']; // Not a form field: kept with the same key (P-M8).
		}
	}

	/**
	 * @param Profile               $profile Profile to store.
	 * @param array<string, string> $secrets Secrets to seal.
	 * @param bool                  $new     A new profile.
	 * @param bool                  $changed Its settings or secrets changed (the state starts over).
	 * @param array<int, string>    $forget  Vault fields to remove once the profile is stored with its OAuth connection cleared (M7c: the Client ID changed).
	 * @param \Closure|null         $release fn(): void — withdraws the old grant once the profile is stored, before its tokens are dropped (#6).
	 * @return array<string, mixed>
	 * @throws RestException 409 when it cannot be stored, 404 when an edited profile was deleted meanwhile.
	 */
	private function save( Profile $profile, #[\SensitiveParameter] array $secrets, bool $new, bool $changed, array $forget = [], ?\Closure $release = null ): array {
		// Secrets of a server the edit moves away from go first and must really be gone (#3): a profile on the new server never holds them. The OAuth tokens wait for the stored profile below.
		$moved  = array_values( array_diff( $forget, TokenKeeper::FIELDS ) );
		$forget = array_values( array_intersect( $forget, TokenKeeper::FIELDS ) );
		if ( ! $this->destinations->vault()->drop( $profile->id, $moved ) ) {
			throw new RestException( 'lw_migration_destination_storage', __( 'The destination could not be saved. Try again.', 'lw-migration' ), 409 );
		}
		$binding = $this->destinations->binding( $profile );
		try {
			$this->destinations->vault()->put( $profile->id, $secrets, $binding );
		} catch ( \RuntimeException $e ) {
			throw new RestException( 'lw_migration_destination_storage', $e->getMessage(), 409 );
		}
		try {
			$exists = $new ? $this->insert( $profile ) : $this->destinations->profiles()->replace( $profile, $changed, [] !== $forget );
		} catch ( \LengthException | \RuntimeException $e ) {
			$this->undoSecrets( $profile, $secrets, $binding, $new );
			if ( $e instanceof \LengthException ) {
				/* translators: %d: maximum number of destinations */
				throw new RestException( 'lw_migration_destinations_full', sprintf( __( 'This site already has %d destinations. Remove one first.', 'lw-migration' ), ProfileRepository::MAX ), 409 );
			}
			throw new RestException( 'lw_migration_destination_storage', __( 'The destination could not be saved. Try again.', 'lw-migration' ), 409 );
		}
		if ( ! $exists ) {
			if ( [] !== $secrets ) {
				$this->forgetSecrets( $profile->id ); // Deleted meanwhile: the secrets just stored must not outlive it.
			}
			throw RestException::destinationMissing();
		}
		if ( null !== $release ) {
			$release();
		}
		$this->destinations->vault()->drop( $profile->id, $forget ); // After the profile: a refused edit keeps the old connection; tokens left by a failed drop are bound to the old Client ID and never used.

		return $this->view( $profile );
	}

	/**
	 * @param Profile $profile A new profile.
	 * @return true
	 * @throws \LengthException|\RuntimeException When it cannot be stored.
	 */
	private function insert( Profile $profile ): bool {
		$this->destinations->profiles()->save( $profile );

		return true;
	}

	/**
	 * After a refused profile write: a new profile's secrets go (no profile
	 * names their id), and so do the ones an edit just stored for a server
	 * the stored profile does not name. Best effort: those never open for
	 * the stored profile anyway (SecretBinding).
	 *
	 * @param Profile               $profile The profile that was not stored.
	 * @param array<string, string> $secrets The secrets just stored.
	 * @param string|null           $binding The server they were stored for.
	 * @param bool                  $new     A new profile.
	 */
	private function undoSecrets( Profile $profile, #[\SensitiveParameter] array $secrets, ?string $binding, bool $new ): void {
		if ( $new ) {
			$this->forgetSecrets( $profile->id );
			return;
		}
		$stored = $this->destinations->find( $profile->id );
		if ( [] !== $secrets && null !== $stored && $this->destinations->binding( $stored ) !== $binding ) {
			$this->destinations->vault()->drop( $profile->id, array_keys( $secrets ) );
		}
	}

	/**
	 * The secrets of a profile that was not stored: no profile names their id.
	 *
	 * @param string $id Profile id.
	 */
	private function forgetSecrets( string $id ): void {
		try {
			$this->destinations->vault()->forget( $id );
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Unreachable sealed values under an id nothing names; the refusal is answered anyway.
		}
	}
}

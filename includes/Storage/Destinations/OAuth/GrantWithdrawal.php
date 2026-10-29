<?php
/**
 * Which grant to withdraw before a connection's tokens are forgotten.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #6: before a delete or an edit forgets an OAuth profile's tokens
 * (withdraw()), and after a reconnect stored a new grant over an old one
 * (replaced()), the old grant is withdrawn at the provider (GrantRevoker),
 * best effort: the local forget never waits for it, and what could not be
 * withdrawn comes back as a notice (or, after a reconnect, on the
 * profile's state line). Google withdraws the whole grant of an account to
 * an app, and removing an app's access at Microsoft does the same, so for
 * those two nothing is withdrawn (and no notice given) while another
 * connection on this site — another profile, or the reconnect's own new
 * one — uses the same app (the LW app, or the same Client ID) with the
 * same account (a connection whose account the provider did not name
 * cannot be told apart: the notice is given instead); Dropbox withdraws
 * only the one token. A reconnect that got
 * the same refresh token back withdraws nothing. Tokens that no longer
 * open are not sent anywhere: the notice is given instead.
 */
final class GrantWithdrawal {

	/**
	 * Clock for GrantRevoker (null: time()).
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $now;

	/**
	 * @param Destinations  $destinations Profiles, vault, types.
	 * @param \Closure|null $now          Clock (tests).
	 */
	public function __construct( private Destinations $destinations, ?\Closure $now = null ) {
		$this->now = $now;
	}

	/**
	 * Before the tokens of a stored profile are forgotten.
	 *
	 * @param Profile                    $profile The profile as stored (the settings and connection the tokens belong to).
	 * @param array<string, string>|null $secrets Its secrets as read before an edit replaced any (null: read them now).
	 * @return string|null The notice when the access could not be withdrawn.
	 */
	public function withdraw( Profile $profile, #[\SensitiveParameter] ?array $secrets = null ): ?string {
		$type = $this->destinations->registry()->get( $profile->type );
		if ( ! $type instanceof OAuthDestinationType ) {
			return null;
		}
		$secrets = $secrets ?? $this->destinations->vault()->open( $profile->id );
		if ( null === $secrets ) {
			return in_array( TokenKeeper::REFRESH, $this->destinations->vault()->fields( $profile->id ), true ) ? GrantRevoker::notice( $type->provider( $profile->settings ) ) : null;
		}
		$others = array_filter( $this->destinations->profiles()->all(), static fn( Profile $p ): bool => $p->id !== $profile->id );
		$shared = self::shared( $type, $profile, $others );
		if ( null === $shared ) {
			return GrantRevoker::notice( $type->provider( $profile->settings ) );
		}

		return $shared ? null : $this->revoker( $type )->revoke( $type, $profile->settings, $secrets );
	}

	/**
	 * After a reconnect stored a new grant: the one it replaced. A notice
	 * goes on the profile's state line (the card shows it).
	 *
	 * @param Profile               $before  The profile as the connect read it (the old connection).
	 * @param array<string, string> $secrets Its secrets before the connect (the old tokens).
	 */
	public function replaced( Profile $before, #[\SensitiveParameter] array $secrets ): void {
		$type = $this->destinations->registry()->get( $before->type );
		$now  = $this->destinations->find( $before->id );
		$old  = (string) ( $secrets[ TokenKeeper::REFRESH ] ?? '' );
		if ( ! $type instanceof OAuthDestinationType || null === $now || '' === $old ) {
			return;
		}
		$current = (string) ( ( $this->destinations->vault()->open( $before->id ) ?? [] )[ TokenKeeper::REFRESH ] ?? '' );
		if ( hash_equals( $current, $old ) ) {
			return; // The provider gave the same grant back.
		}
		$others = array_map( static fn( Profile $p ): Profile => $p->id === $before->id ? $now : $p, $this->destinations->profiles()->all() );
		$shared = self::shared( $type, $before, $others );
		if ( true === $shared ) {
			return;
		}
		$notice = null === $shared ? GrantRevoker::notice( $type->provider( $before->settings ) ) : $this->revoker( $type )->revoke( $type, $before->settings, $secrets );
		if ( null !== $notice ) {
			$this->destinations->profiles()->mark( $before->id, Profile::NEW, $notice );
		}
	}

	/**
	 * Whether a grant-wide withdrawal (Google, Microsoft) would also cut
	 * another connection of this site to the same account through the same
	 * app. The provider's own name as the account label means the provider
	 * named no account at connect (Google Drive's fallback), so such a
	 * connection beside another one on the same app is unknown.
	 *
	 * @param OAuthDestinationType $type    Type.
	 * @param Profile              $profile The profile whose grant goes.
	 * @param array<int, Profile>  $others  The connections that stay.
	 * @return bool|null Null when it cannot be told (nothing is withdrawn, the notice is given).
	 */
	private static function shared( OAuthDestinationType $type, Profile $profile, array $others ): ?bool {
		$account  = (string) ( $profile->connection[ OAuthDestinationType::ACCOUNT ] ?? '' );
		$provider = $type->provider( $profile->settings );
		if ( Provider::DROPBOX === $provider->key || '' === $account ) {
			return false;
		}
		$unknown = false;
		foreach ( $others as $other ) {
			if ( $other->type !== $profile->type || self::app( $other->settings ) !== self::app( $profile->settings ) ) {
				continue;
			}
			$theirs = (string) ( $other->connection[ OAuthDestinationType::ACCOUNT ] ?? '' );
			if ( $theirs === $account && $account !== $provider->label ) {
				return true;
			}
			$unknown = $unknown || ( '' !== $theirs && in_array( $provider->label, [ $account, $theirs ], true ) );
		}

		return $unknown ? null : false;
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function app( array $settings ): string {
		return OAuthMode::ownApp( $settings ) ? 'own:' . (string) ( $settings[ OAuthDestinationType::CLIENT_ID ] ?? '' ) : 'lw';
	}

	private function revoker( OAuthDestinationType $type ): GrantRevoker {
		return new GrantRevoker( $type->transport(), $this->now );
	}
}

<?php
/**
 * An OAuth destination type for tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\Profile;

/**
 * Google's rules and endpoints, a FakeDestination as the client; connect()
 * runs $onConnect with its OAuthHttp first (when set), then answers
 * $connected (or throws $connectFailure).
 */
final class FakeOAuthType extends OAuthDestinationType {

	/** @var array<string, int|string> */
	public array $connected = [ 'account' => 'admin@example.com' ];

	public ?\Throwable $connectFailure = null;

	/** fn( OAuthHttp $http ): void — what the account check does with its tokens. */
	public ?\Closure $onConnect = null;

	public ?OAuthHttp $made = null;

	/** What identify() answers (the account at the provider, #12). */
	public string $identity = 'google:fake-account';

	public ?\Throwable $identifyFailure = null;

	/** @var array<int, string> Access tokens identify() was asked with. */
	public array $identified = [];

	public function key(): string {
		return 'fakeoauth';
	}

	public function label(): string {
		return 'Fake Drive';
	}

	public function provider( array $settings ): Provider {
		return Provider::google();
	}

	/**
	 * A test-only grant setting (`grant`): profiles saved straight into the
	 * repository may carry one; without it the type has no grant settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public function grantMark( array $settings ): string {
		return is_string( $settings['grant'] ?? null ) ? $settings['grant'] : '';
	}

	public function steps(): array {
		return [ 'Create an app.', 'Paste the address.' ];
	}

	public function consoleUrl(): string {
		return 'https://console.example';
	}

	public function connect( OAuthHttp $http, TokenSet $tokens, Profile $profile ): array {
		if ( null !== $this->onConnect ) {
			( $this->onConnect )( $http );
		}
		if ( null !== $this->connectFailure ) {
			throw $this->connectFailure;
		}
		return $this->connected;
	}

	public function identify( array $settings, string $accessToken ): string {
		$this->identified[] = $accessToken;
		if ( null !== $this->identifyFailure ) {
			throw $this->identifyFailure;
		}
		return $this->identity;
	}

	protected function client( Profile $profile, OAuthHttp $http ): DestinationInterface {
		$this->made = $http;
		return new FakeDestination();
	}
}

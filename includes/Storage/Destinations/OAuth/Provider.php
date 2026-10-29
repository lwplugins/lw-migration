<?php
/**
 * One OAuth provider: where to send the admin, where to get tokens, what it accepts.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Data only (plan E4, E13, spec 21.10); no WordPress function. The hosts are
 * fixed by this class, never typed by an admin (the tenant is URL-encoded
 * into one path segment). Endpoints, scopes and the redirect rules come from
 * each provider's documentation, read 2026-10-12/13:
 * Google — https only (localhost exempt), no bare IP host, a public suffix
 * (developers.google.com/identity/protocols/oauth2/web-server, "Validation
 * rules"); Dropbox — "All redirect URIs must be HTTPS except for localhost
 * URIs" (docs.dropboxapi.com/dropbox-api/docs/get-started/authorization.md;
 * its rules on a query string and a bare IP host are UNVERIFIED and left
 * open); Microsoft — https (http only for localhost; [::1] is not supported
 * and http://127.0.0.1 only through the app manifest, so neither is
 * exempt), no query string for apps that sign in personal accounts, at most
 * 256 characters, none of ! $ ' ( ) , ; and no internationalized domain
 * name (learn.microsoft.com/en-us/entra/identity-platform/reply-url). PKCE
 * with a Google web client is UNVERIFIED (plan E4; the manual E2E decides).
 */
final class Provider {

	public const GOOGLE = 'google';

	public const DROPBOX = 'dropbox';

	public const MICROSOFT = 'microsoft';

	/** A Microsoft tenant: a name Microsoft defines, a tenant GUID, or a domain (labels of 1-63 [a-z0-9-], lower case, no dash at either end). */
	public const TENANT_PATTERN = '/^(common|organizations|consumers|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+)\z/';

	private const DEFAULT_RULES = [
		'ip'     => true,
		'query'  => true,
		'suffix' => false,
		'length' => 0,
		'chars'  => false,
		'idn'    => true,
		'loopIp' => true,
	];

	/**
	 * Effective redirect rules.
	 *
	 * @var array<string, bool|int>
	 */
	private array $rules;

	/**
	 * @param string                  $key               GOOGLE, DROPBOX or MICROSOFT.
	 * @param string                  $label             Product name (never translated).
	 * @param string                  $authorizeEndpoint Authorization page.
	 * @param string                  $tokenEndpoint     Token endpoint.
	 * @param array<int, string>      $scopes            Scopes.
	 * @param array<string, string>   $authorizeExtra    More authorize parameters.
	 * @param bool                    $scopeOnToken      Token requests repeat the scope (Microsoft).
	 * @param bool                    $pkce              Send code_challenge / code_verifier.
	 * @param array<string, bool|int> $rules             Redirect rules over DEFAULT_RULES: ip (bare IP host allowed), query, suffix (host must look public), length (0 = none), chars (refuses ! $ ' ( ) , ;), idn (internationalized host allowed), loopIp (127.0.0.1 and [::1] are exempt from https like localhost).
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $authorizeEndpoint,
		public string $tokenEndpoint,
		public array $scopes,
		public array $authorizeExtra = [],
		public bool $scopeOnToken = false,
		public bool $pkce = true,
		array $rules = []
	) {
		$this->rules = array_merge( self::DEFAULT_RULES, $rules );
	}

	public static function google(): self {
		return new self(
			self::GOOGLE,
			'Google Drive',
			'https://accounts.google.com/o/oauth2/v2/auth',
			'https://oauth2.googleapis.com/token',
			[ 'https://www.googleapis.com/auth/drive.file' ],
			[
				'access_type' => 'offline',
				'prompt'      => 'consent',
			],
			false,
			true,
			[
				'ip'     => false,
				'suffix' => true,
			]
		);
	}

	public static function dropbox(): self {
		return new self(
			self::DROPBOX,
			'Dropbox',
			'https://www.dropbox.com/oauth2/authorize',
			'https://api.dropboxapi.com/oauth2/token',
			[ 'files.metadata.read', 'files.content.read', 'files.content.write', 'account_info.read' ],
			[ 'token_access_type' => 'offline' ]
		);
	}

	/**
	 * Only `preferred_username` of the id_token is read for the account label
	 * (IdToken::label()), and Microsoft sends it with the `profile` scope
	 * (learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference,
	 * read 2026-10-14), so the `email` scope is not asked for. The tenant is
	 * compared lower-case and must match TENANT_PATTERN (no empty, `.` or `..`
	 * segment can reach the endpoint path).
	 *
	 * @param string $tenant common, organizations, consumers, a tenant GUID or domain.
	 * @param string $access appfolder (Files.ReadWrite.AppFolder) or files (Files.ReadWrite).
	 * @throws \InvalidArgumentException For a tenant outside TENANT_PATTERN (a caller's bug: the type checks the setting first).
	 */
	public static function microsoft( string $tenant = 'common', string $access = 'appfolder' ): self {
		$tenant = strtolower( $tenant );
		if ( 1 !== preg_match( self::TENANT_PATTERN, $tenant ) ) {
			throw new \InvalidArgumentException( 'Not a Microsoft tenant.' );
		}
		$base  = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/';
		$files = 'files' === $access ? 'https://graph.microsoft.com/Files.ReadWrite' : 'https://graph.microsoft.com/Files.ReadWrite.AppFolder';

		return new self(
			self::MICROSOFT,
			'OneDrive',
			$base . 'authorize',
			$base . 'token',
			[ 'offline_access', 'openid', 'profile', $files ],
			[ 'response_mode' => 'query' ],
			true,
			true,
			[
				'query'  => ! in_array( $tenant, [ 'common', 'consumers' ], true ),
				'length' => 256,
				'chars'  => true,
				'idn'    => false,
				'loopIp' => false,
			]
		);
	}

	public function scope(): string {
		return implode( ' ', $this->scopes );
	}

	/**
	 * @param string $name A rule key.
	 * @return bool|int False for an unknown key.
	 */
	public function rule( string $name ) {
		return $this->rules[ $name ] ?? false;
	}

	/**
	 * @param string $clientId    Client ID.
	 * @param string $redirectUri Redirect address (exactly as registered).
	 * @param string $state       OAuthState::issue().
	 * @param string $challenge   Pkce::challenge() ('' when $pkce is off).
	 */
	public function authorizeUrl( string $clientId, string $redirectUri, #[\SensitiveParameter] string $state, string $challenge ): string {
		$query = [
			'client_id'     => $clientId,
			'redirect_uri'  => $redirectUri,
			'response_type' => 'code',
			'scope'         => $this->scope(),
			'state'         => $state,
		];
		if ( $this->pkce ) {
			$query['code_challenge']        = $challenge;
			$query['code_challenge_method'] = Pkce::METHOD;
		}

		return $this->authorizeEndpoint . '?' . http_build_query( array_merge( $query, $this->authorizeExtra ), '', '&', PHP_QUERY_RFC3986 );
	}
}

<?php
/**
 * The `onedrive` destination type.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OneDrive;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\IdToken;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthDestinationType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5, plan E13/E14: OneDrive through the Microsoft identity platform
 * v2. Tenant `common` signs in work and personal accounts; access
 * `appfolder` asks Files.ReadWrite.AppFolder, `files` Files.ReadWrite (the
 * documented other way to an app folder, for work accounts where the
 * app-folder permission is refused). Connecting touches
 * /drive/special/approot (which creates the folder) and names the account
 * from the id_token. The tenant is stored lower-case and must match
 * Provider::TENANT_PATTERN; a stored value that does not (an older or
 * damaged profile) reads as `common`, so a list never fails on it. A tenant
 * or permission change forgets the connection and its tokens (sameGrant()):
 * they were issued by another authority or for another consent; a connect
 * in flight across such a change is refused (grantMark() is bound into its
 * state and connection). Through the LW app the tenant is common and the
 * permission appfolder (nothing is stored; the fields show only with the
 * own app).
 */
final class OneDriveType extends OAuthDestinationType {

	public const KEY = 'onedrive';

	public const TENANT = 'tenant';

	public const ACCESS = 'access';

	private const ACCESSES = [ 'appfolder', 'files' ];

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return GraphErrors::LABEL;
	}

	public function provider( array $settings ): Provider {
		return Provider::microsoft( self::tenant( $settings ), self::access( $settings ) );
	}

	public function grantMark( array $settings ): string {
		return self::tenant( $settings ) . ' ' . self::access( $settings );
	}

	public function consoleUrl(): string {
		return 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app';
	}

	public function steps(): array {
		return [
			__( 'In the Microsoft Entra admin center register an app: supported accounts "Any Entra ID Tenant + Personal Microsoft accounts" (or your organization only, then enter its tenant ID below), platform Web, redirect URI the address below.', 'lw-migration' ),
			__( 'Under Certificates & secrets create a client secret and copy its Value (it is shown once and it expires: renew it in time).', 'lw-migration' ),
			__( 'Under API permissions add the Microsoft Graph delegated permissions Files.ReadWrite.AppFolder, offline_access, openid and profile (for a work account that refuses the app folder permission: Files.ReadWrite, and choose the whole OneDrive permission below).', 'lw-migration' ),
			__( 'Copy the Application (client) ID and the secret Value here, save, then choose Connect.', 'lw-migration' ),
		];
	}

	protected function extraFields(): array {
		return [
			new Field( self::TENANT, Field::TEXT, __( 'Tenant', 'lw-migration' ), false, __( 'common for work and personal accounts; organizations, consumers, or your tenant ID or domain for a single-organization app.', 'lw-migration' ), [], 'common', [ OAuthMode::OWN_APP => [ true ] ] ),
			new Field(
				self::ACCESS,
				Field::SELECT,
				__( 'Permission', 'lw-migration' ),
				false,
				__( 'The app folder permission is enough and recommended.', 'lw-migration' ),
				[
					'appfolder' => __( 'App folder only (Files.ReadWrite.AppFolder)', 'lw-migration' ),
					'files'     => __( 'Whole OneDrive permission (Files.ReadWrite), still writing to the app folder', 'lw-migration' ),
				],
				'appfolder',
				[ OAuthMode::OWN_APP => [ true ] ]
			),
		];
	}

	protected function extraSettings( array $raw ): array {
		$sent   = $raw[ self::TENANT ] ?? null;
		$tenant = strtolower( trim( is_string( $sent ) && '' !== trim( $sent ) ? $sent : 'common' ) );
		if ( ( null !== $sent && ! is_string( $sent ) ) || 1 !== preg_match( Provider::TENANT_PATTERN, $tenant ) ) {
			throw new FieldException( self::TENANT, __( 'Enter common, organizations, consumers, a tenant ID or a verified domain.', 'lw-migration' ) );
		}
		$access = $raw[ self::ACCESS ] ?? null;
		$access = is_string( $access ) && '' !== $access ? $access : 'appfolder';
		if ( ! in_array( $access, self::ACCESSES, true ) ) {
			throw new FieldException( self::ACCESS, __( 'Choose one of the listed permissions.', 'lw-migration' ) );
		}

		return [
			self::TENANT => $tenant,
			self::ACCESS => $access,
		];
	}

	public function connect( OAuthHttp $http, TokenSet $tokens, Profile $profile ): array {
		$root = $http->request( 'GET', OneDriveDestination::DRIVE . '/special/approot' );
		if ( 200 !== $root['status'] ) {
			$failure = GraphErrors::classify( $root['status'], $root['body'], $root['headers']['retry-after'] ?? '', $http->now() );
			throw $failure;
		}
		$label = IdToken::label( $tokens->idToken );

		// A Client-ID/grant change clears the connection (DestinationInput) and so re-pins to the current site: accepted.
		return [
			'account' => '' === $label ? __( 'Microsoft account', 'lw-migration' ) : $label,
			'folder'  => SiteFolder::of( $profile->connection ), // Pinned at the first connect, kept by a reconnect.
		];
	}

	protected function client( Profile $profile, OAuthHttp $http ): DestinationInterface {
		return new OneDriveDestination( $http, SiteFolder::of( $profile->connection ) );
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function tenant( array $settings ): string {
		$tenant = strtolower( trim( is_string( $settings[ self::TENANT ] ?? null ) ? $settings[ self::TENANT ] : '' ) );

		return 1 === preg_match( Provider::TENANT_PATTERN, $tenant ) ? $tenant : 'common';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function access( array $settings ): string {
		$access = $settings[ self::ACCESS ] ?? null;

		return is_string( $access ) && in_array( $access, self::ACCESSES, true ) ? $access : 'appfolder';
	}
}

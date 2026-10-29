<?php
/**
 * Who may use the REST API.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\DownloadToken;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Schedules\Loopback;
use LightweightPlugins\Migration\Storage\StorageDir;

defined( 'ABSPATH' ) || exit;

/**
 * A logged-in user with the capability (filter `lw_migration_capability`,
 * default manage_options; manage_network_options on a multisite network). On a
 * network every check also needs the main site's context (MainSite): Routes
 * registers the routes only there, and a request that still reaches one from
 * another site is refused with MainSite::restError() (403). The REST cookie
 * nonce is checked by WordPress before this runs: without it the request is
 * anonymous and fails here.
 *
 * The download link is the one exception: it never carries the REST nonce
 * (it would land in access logs and the browser history), so WordPress runs
 * it as user 0. Its check authenticates the login cookie itself, asks for the
 * same capability, and accepts only a download token issued to that user in
 * that login session; the backup is the one the token names.
 *
 * The loopback route of the schedules is the second exception:
 * Permission::loopback (a one-time token in the JSON body, no login). The
 * transfer link is the third: Permission::share (a token in the query, no
 * login either; after the loopback the second route that needs none).
 * The connection routes (Rest\LinkPeerRoutes) are the fourth exception
 * (the third route without a login): a signature of a paired site
 * (Link\Guard). The OAuth callback (Rest\OAuthRoutes) is the fifth: the
 * provider sends the browser back without the REST nonce;
 * Permission::oauthCallback authenticates the login cookie like the
 * download link, and the single-use state (OAuthState, bound to that user
 * and login session) is its CSRF protection.
 */
final class Permission {

	public const DEFAULT_CAPABILITY = 'manage_options';

	/** The default on a multisite network: backups are network-wide, a subsite admin must not reach them. */
	public const NETWORK_CAPABILITY = 'manage_network_options';

	public static function capability(): string {
		$default    = is_multisite() ? self::NETWORK_CAPABILITY : self::DEFAULT_CAPABILITY;
		$capability = apply_filters( 'lw_migration_capability', $default );

		return is_string( $capability ) && '' !== $capability ? $capability : $default;
	}

	/**
	 * The user with this id and this login, when it may use the plugin: the
	 * admin a connection acts for (ruling I3).
	 *
	 * @param array<string, mixed> $owner {id, login}.
	 * @return array{id: int, login: string}|null
	 */
	public static function owner( array $owner ): ?array {
		$user = get_userdata( (int) ( $owner['id'] ?? 0 ) );
		if ( ! is_object( $user ) || ! hash_equals( (string) $user->user_login, (string) ( $owner['login'] ?? '' ) ) || ! user_can( $user, self::capability() ) ) {
			return null;
		}

		return [
			'id'    => (int) $user->ID,
			'login' => (string) $user->user_login,
		];
	}

	/**
	 * Permission callback of every route but the download link and the loopback
	 * (core answers a WP_Error with its status).
	 *
	 * @return bool|\WP_Error
	 */
	public static function check() {
		if ( ! is_user_logged_in() || ! current_user_can( self::capability() ) ) {
			return false;
		}

		return MainSite::isContext() ? true : MainSite::restError();
	}

	/**
	 * Permission callback of the download route.
	 *
	 * @param \WP_REST_Request $request Request (?token=).
	 */
	public static function download( \WP_REST_Request $request ): bool {
		$token = $request->get_param( 'token' );

		return is_string( $token ) && null !== self::downloadTarget( $token );
	}

	/**
	 * Permission callback of the OAuth callback route (Rest\OAuthRoutes):
	 * a valid login cookie of a user with the capability, on the main site.
	 * Anything else is core's 401 and the route never runs.
	 */
	public static function oauthCallback(): bool {
		return null !== self::cookieUser();
	}

	/**
	 * Permission callback of the loopback route: no login and no REST nonce
	 * (the request comes from the server itself), but a one-time token bound
	 * to the job, used up here (Schedules\Loopback). Only the JSON body is
	 * read (never the query string: a token must not reach an access log).
	 * Every refusal is the same false, so core answers the same 401. Core's
	 * Allow header calls this again on rest_post_dispatch, also for a request
	 * it never dispatched: that pass is refused without using the token.
	 *
	 * @param \WP_REST_Request $request Request ({job, token} JSON body).
	 */
	public static function loopback( \WP_REST_Request $request ): bool {
		return ! doing_filter( 'rest_post_dispatch' ) && self::loopbackOpens( $request->get_json_params() );
	}

	/**
	 * A malformed body is refused before the token row is read.
	 *
	 * @param mixed $body Decoded JSON body.
	 */
	public static function loopbackOpens( $body ): bool {
		if ( ! MainSite::isContext() ) {
			return false; // The token row lives in the main site's options.
		}
		$job   = is_array( $body ) ? ( $body['job'] ?? null ) : null;
		$token = is_array( $body ) ? ( $body['token'] ?? null ) : null;

		return is_string( $job ) && is_string( $token ) && ( new Loopback() )->redeem( $job, $token, time() );
	}

	/**
	 * The backup a download token opens for the user of the login cookie.
	 *
	 * @param string $token Token from the link.
	 * @return array{folder: string, name: string}|null Null outside the main site of a network, without a valid login cookie, the capability or a valid token.
	 */
	public static function downloadTarget( string $token ): ?array {
		$userId = self::cookieUser();

		return null === $userId ? null : ( new DownloadToken() )->validate( $token, $userId );
	}

	/**
	 * The user of a valid logged_in cookie who has the capability, in the
	 * main site's context only. For the routes that cannot carry the REST
	 * nonce, where WordPress runs the request as user 0.
	 *
	 * @return int|null Null outside the main site of a network, without a valid login cookie or without the capability.
	 */
	public static function cookieUser(): ?int {
		if ( ! MainSite::isContext() ) {
			return null;
		}
		$userId = wp_validate_auth_cookie( '', 'logged_in' );

		return is_int( $userId ) && $userId > 0 && user_can( $userId, self::capability() ) ? $userId : null;
	}

	/**
	 * Guard of the transfer-link download, the second route without login
	 * (after the loopback, Schedules\Loopback): a link is the credential. True
	 * only for a live link of a backup that exists; every refusal is the same
	 * constant 404, whether the asker is logged in or not, so a probe learns
	 * nothing (no link, a wrong one, an expired or revoked one, a deleted backup,
	 * another site of the network all look alike).
	 *
	 * @param \WP_REST_Request $request Request (?share=).
	 * @return true|\WP_Error
	 */
	public static function share( \WP_REST_Request $request ) {
		$token = $request->get_param( 'share' );

		return is_string( $token ) && null !== self::shareTarget( $token ) ? true : self::shareRefusal();
	}

	/**
	 * Read only (an anonymous request writes nothing here): the storage folder
	 * is the one already stored, never named or repaired on the way.
	 *
	 * @param string $token Token from the link.
	 * @return array{id: string, backup: Backup}|null Null outside the main site, for a link that does not open, or a backup that is gone.
	 */
	public static function shareTarget( string $token ): ?array {
		if ( ! MainSite::isContext() ) {
			return null;
		}
		$row     = ( new Shares() )->open( $token, time() );
		$storage = null === $row ? null : StorageDir::stored();
		$backup  = null === $row || null === $storage ? null : Repository::fromWordPress( $storage )->findIn( $row['name'], $row['folder'] );

		// A link is bound to the file it was made for; a replaced file (or a link without a stored fingerprint) is another file.
		return null === $row || null === $backup || ! Shares::matches( $row['identity'], Fingerprint::of( $backup->path ) ) ? null : [
			'id'     => $row['id'],
			'backup' => $backup,
		];
	}

	/**
	 * The one answer to every refused transfer link. Not translated on
	 * purpose: the same bytes for everyone, whatever the locale.
	 */
	public static function shareRefusal(): \WP_Error {
		return new \WP_Error( 'lw_migration_share_unavailable', 'This link is not available.', [ 'status' => 404 ] );
	}
}

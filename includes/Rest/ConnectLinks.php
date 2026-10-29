<?php
/**
 * The command line's connect link: a one-time link to the plugin's page.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Admin\AdminPage;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ConnectTicket;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #12 (ruling R12). link() runs start()'s checks (OAuthApi::ready())
 * and answers the plugin's page with a ConnectTicket bound to the
 * command line's --user; follow() is what that page does with it: it uses
 * the ticket up for the logged-in administrator and answers the authorize
 * address of a state issued in that browser's login session, or the page's
 * own address with a fixed code (EXPIRED for a used, expired, unknown or
 * another administrator's ticket; FAILED when the connect cannot start now).
 */
final class ConnectLinks {

	/** The query parameter of the plugin's page that carries the ticket. */
	public const PARAM = 'lwm-connect';

	/**
	 * @param OAuthApi      $api     Connects.
	 * @param ConnectTicket $tickets Tickets.
	 * @param \Closure      $pageUrl fn(): string — the plugin's admin page.
	 */
	public function __construct( private OAuthApi $api, private ConnectTicket $tickets, private \Closure $pageUrl ) {
	}

	public static function fromWordPress(): self {
		return new self( OAuthApi::fromWordPress(), new ConnectTicket(), static fn(): string => AdminPage::pageUrl() );
	}

	/**
	 * @param string $id     Profile id.
	 * @param int    $userId The administrator the link is for.
	 * @return string The plugin's page with the one-time ticket.
	 * @throws RestException When the profile cannot connect now, or the ticket cannot be stored.
	 */
	public function link( string $id, int $userId ): string {
		$this->api->ready( $id );
		try {
			$ticket = $this->tickets->issue( $userId, $id );
		} catch ( \RuntimeException $e ) {
			throw new RestException( 'lw_migration_oauth_unavailable', $e->getMessage(), 409 );
		}

		return add_query_arg( self::PARAM, $ticket, (string) ( $this->pageUrl )() );
	}

	/**
	 * @param string $ticket The ticket from the page's address.
	 * @param int    $userId The logged-in administrator.
	 * @return string Where to send the browser.
	 */
	public function follow( #[\SensitiveParameter] string $ticket, int $userId ): string {
		$page    = (string) ( $this->pageUrl )();
		$profile = $this->tickets->redeem( $ticket, $userId );
		if ( null === $profile ) {
			return OAuthApi::address( $page, OAuthApi::EXPIRED );
		}
		try {
			return $this->api->start( $profile, $userId )['url'];
		} catch ( RestException $e ) {
			return OAuthApi::address( $page, OAuthApi::FAILED, $profile );
		}
	}
}

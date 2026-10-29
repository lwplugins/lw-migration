<?php
/**
 * A request to a connected site that did not do what it should.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Http\AddressRedactor;

defined( 'ABSPATH' ) || exit;

/**
 * The message is for the user (translated, without secrets, the other
 * site's address in display form). A passing failure (no answer, a server
 * error, a busy job or replay cache) is worth another try on the next tick;
 * every other one is final. The named constructors are the texts of a
 * pairing that fails.
 */
final class LinkException extends \RuntimeException {

	public const REFUSED = 'refused';

	public const CLOCK = 'clock';

	public const UNREACHABLE = 'unreachable';

	public const NOT_AVAILABLE = 'not_available';

	public const REMOTE = 'remote';

	public const PROTOCOL = 'protocol';

	/** A 2xx answer without this connection's signature for the request: forged or changed on its way. */
	public const UNSIGNED = 'unsigned';

	public const GONE = 'gone';

	/** An address a connection cannot use: neither http nor https, or not fit for a pairing code. */
	public const SCHEME = 'scheme';

	public const CODE = 'code';

	private const PASSING_CODES = [ 'lw_migration_job_busy', 'lw_migration_link_busy' ];

	/**
	 * @param string               $reason     One of the constants.
	 * @param string               $message    Message for the user.
	 * @param int                  $status     HTTP status of the other site's answer (0 = none).
	 * @param array<string, mixed> $data       The answer's error data (e.g. received bytes).
	 * @param string               $remoteCode The answer's error code.
	 */
	public function __construct( public string $reason, string $message, public int $status = 0, public array $data = [], public string $remoteCode = '' ) {
		parent::__construct( $message );
	}

	public function isPassing(): bool {
		return self::UNREACHABLE === $this->reason
			|| ( self::REMOTE === $this->reason && ( $this->status >= 500 || in_array( $this->remoteCode, self::PASSING_CODES, true ) ) );
	}

	public static function notACode(): self {
		return new self( self::CODE, __( 'This is not a pairing code. Copy the whole code from the other site\'s Connections page.', 'lw-migration' ) );
	}

	public static function ownCode(): self {
		return new self( self::CODE, __( 'This code was made on this site. Enter it on the other site.', 'lw-migration' ) );
	}

	/**
	 * The answer to a pairing request does not fit the code (changed, forged, or another version).
	 */
	public static function noMatch(): self {
		return new self( self::PROTOCOL, __( 'The other site\'s answer does not match this code. Make a new code there and try again.', 'lw-migration' ) );
	}

	/**
	 * A failed pairing request in the words of the code.
	 *
	 * @param LinkException $e What the request answered.
	 */
	public static function pairing( LinkException $e ): self {
		if ( self::REFUSED === $e->reason ) {
			return new self( self::CODE, __( 'The other site did not accept this code: it was used, it expired, or a newer code replaced it. Make a new code there.', 'lw-migration' ), $e->status );
		}
		if ( self::REMOTE === $e->reason && 'lw_migration_link_code_used' === $e->remoteCode ) {
			return new self( self::CODE, __( 'The code was used but the other site could not save the connection. Make a new code there.', 'lw-migration' ), $e->status, $e->data, $e->remoteCode );
		}

		return $e;
	}

	/**
	 * This site cannot store the new link.
	 *
	 * @param int $code The exception code of Links::add() (FULL, or another failure; TAKEN is ownCode()).
	 */
	public static function notStored( int $code ): self {
		if ( Links::FULL === $code ) {
			return new self( self::CODE, __( 'This site already has 20 connections. Remove one first.', 'lw-migration' ) );
		}

		return new self( self::CODE, __( 'This site could not save the connection. Make a new code on the other site and try again.', 'lw-migration' ) );
	}

	/**
	 * This site's address does not fit a pairing code.
	 *
	 * @param string $address This site's address.
	 */
	public static function unfitAddress( string $address ): self {
		/* translators: %s: this site's address */
		return new self( self::SCHEME, sprintf( __( 'This site\'s address (%s) cannot be given to another site: it needs a plain http or https address with a host name, without a user name or password. Set the address with the lw_migration_link_address filter.', 'lw-migration' ), AddressRedactor::display( $address ) ) );
	}

	/** Issue #2: the code names the other site by an IP address, and the filter does not allow that. */
	public static function ipHost(): self {
		return new self( self::SCHEME, __( 'This pairing code names the other site by an IP address, and this site connects only to sites with a host name. A developer can allow IP addresses with the lw_migration_allow_ip_link_hosts filter.', 'lw-migration' ) );
	}
}

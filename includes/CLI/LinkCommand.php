<?php
/**
 * `wp lw-migration link`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Pairing;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Rest\LinksApi;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Destinations\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Connections between two single sites: one makes a pairing code, the other
 * types it; afterwards they can copy content to each other without logging in.
 */
final class LinkCommand {

	/**
	 * Reads the pairing code when it is not an argument: fn(): string (throws \RuntimeException).
	 *
	 * @var \Closure
	 */
	private \Closure $readCode;

	/**
	 * @param \Closure|null $readCode fn(): string (tests; null = a hidden prompt on a terminal, otherwise one line of STDIN).
	 */
	public function __construct( ?\Closure $readCode = null ) {
		$this->readCode = $readCode ?? static fn(): string => SecretInput::read(
			[ new Field( 'code', Field::SECRET, __( 'Pairing code', 'lw-migration' ), true ) ],
			Confirmation::stdinIsInteractive()
		)['code'];
	}

	/**
	 * Makes a pairing code for another site (the previous one stops working).
	 *
	 * The code is shown only now; it connects one site within 15 minutes,
	 * once. The connection acts for the admin given with --user.
	 *
	 * ## OPTIONS
	 *
	 * [--allow=<what>]
	 * : What the other site may do here: pull (copy this site), push (replace this site's content), or pull,push.
	 * ---
	 * default: pull
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration link create-code --user=admin
	 *     wp lw-migration link create-code --user=admin --allow=pull,push
	 *
	 * @subcommand create-code
	 *
	 * @param array<int, string>   $args  Arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function create_code( array $args, array $assoc ): void {
		self::singleSiteOnly();
		$owner = self::owner();
		if ( $owner['id'] <= 0 || ! current_user_can( Permission::capability() ) ) {
			Console::error( self::userText() );
			return;
		}
		$allow = self::rights( $assoc['allow'] ?? Links::PULL );
		try {
			$created = Pairing::fromWordPress()->createCode( $owner, $allow );
		} catch ( LinkException $e ) {
			Console::error( $e->getMessage() );
			return;
		} catch ( \RuntimeException $e ) {
			Console::error( __( 'The pairing code could not be saved. Check the database connection and try again.', 'lw-migration' ) );
			return;
		}
		Console::log( __( 'Pairing code (shown only now; it connects one site within 15 minutes, once):', 'lw-migration' ) );
		Console::log( $created['code'] );
		/* translators: %s: date and time the code stops working */
		Console::log( sprintf( __( 'Valid until %s, once.', 'lw-migration' ), wp_date( 'Y-m-d H:i', $created['expiresAt'] ) ) );
		Console::log( self::allowText( $allow ) );
		if ( $created['insecure'] ) {
			self::httpWarning();
		}
	}

	/**
	 * Connects this site with the site that made the code.
	 *
	 * The code is the whole secret of the connection. Leave it out and it is
	 * asked for (a hidden prompt on a terminal, otherwise one line from
	 * standard input), so it stays out of the process list and the shell
	 * history; <code> on the command line is visible to other users of the
	 * server until the command ends.
	 *
	 * ## OPTIONS
	 *
	 * [<code>]
	 * : The pairing code shown on the other site (safer: leave it out and enter it when asked).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration link add --user=admin
	 *     printf '%s\n' "$PAIRING_CODE" | wp lw-migration link add --user=admin
	 *
	 * @param array<int, string>   $args  The code, when given.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function add( array $args, array $assoc ): void {
		unset( $assoc ); // --user is WP-CLI's own flag.
		self::singleSiteOnly();
		$owner = self::owner();
		if ( $owner['id'] <= 0 || ! current_user_can( Permission::capability() ) ) {
			Console::error( self::userText() );
			return;
		}
		try {
			$code = isset( $args[0] ) && '' !== $args[0] ? (string) $args[0] : ( $this->readCode )();
		} catch ( \RuntimeException $e ) { // Nothing entered (translated).
			Console::error( $e->getMessage() );
			return;
		}
		try {
			$view = Pairing::fromWordPress()->add( $code, $owner );
		} catch ( LinkException $e ) {
			Console::error( $e->getMessage() );
			return;
		} catch ( \InvalidArgumentException $e ) {
			Console::error( self::userText() );
			return;
		}
		if ( ! empty( $view['insecure'] ) ) {
			self::httpWarning();
		}
		/* translators: 1: the other site's name, 2: its address, 3: connection id */
		Console::log( sprintf( __( 'Connected with %1$s (%2$s). Connection id: %3$s.', 'lw-migration' ), (string) $view['name'], (string) $view['address'], (string) $view['id'] ) );
	}

	/**
	 * Lists the connections of this site, never their secrets.
	 *
	 * Direction `out`: this site sends (it typed the code); `in`: this site
	 * accepts (it made the code). `paired` shows when, and for an incoming
	 * connection from which IP, it was paired. `last_used`: when the other
	 * site last used an incoming connection (`never` = not since the
	 * pairing); `-` for an outgoing one, whose use this site does not record.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration link list
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args  Arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function list_( array $args, array $assoc ): void {
		self::singleSiteOnly();
		$rows = [];
		foreach ( ( new Links() )->all( time() ) as $link ) {
			$paired = wp_date( 'Y-m-d H:i', $link['createdAt'] );
			if ( Links::IN === $link['direction'] && '' !== $link['pairedFrom'] ) {
				/* translators: 1: date and time, 2: IP address */
				$paired = sprintf( __( '%1$s from %2$s', 'lw-migration' ), $paired, $link['pairedFrom'] );
			}
			$rows[] = [
				'id'        => $link['id'],
				'direction' => $link['direction'],
				'site'      => $link['name'] . ' (' . $link['address'] . ')',
				'allow'     => implode( ',', $link['allow'] ),
				'state'     => $link['state'],
				'http'      => $link['insecure'] ? 'yes' : 'no',
				'paired'    => $paired,
				'last_used' => self::lastUsed( $link ),
			];
		}
		Console::items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'id', 'direction', 'site', 'allow', 'state', 'http', 'paired', 'last_used' ] );
	}

	/**
	 * The `last_used` cell: `-` for an outgoing connection (this site does
	 * not record its own use), `never` when the other site has not used an
	 * incoming one since the pairing, else when it last did.
	 *
	 * @param array{direction: string, createdAt: int, lastUsedAt: int} $link Link view.
	 */
	private static function lastUsed( array $link ): string {
		if ( Links::IN !== $link['direction'] ) {
			return '-';
		}

		return Links::neverUsed( $link ) ? __( 'never', 'lw-migration' ) : wp_date( 'Y-m-d H:i', $link['lastUsedAt'] );
	}

	/**
	 * Removes a connection; the other site is told when this site sends on it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Connection id (see `wp lw-migration link list`).
	 *
	 * [--yes]
	 * : Do not ask for confirmation (required when not run from a terminal).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration link remove 0123456789abcdef --yes
	 *
	 * @param array<int, string>   $args  Connection id.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function remove( array $args, array $assoc ): void {
		self::singleSiteOnly();
		$id   = (string) ( $args[0] ?? '' );
		$link = 1 === preg_match( Signer::LINK_PATTERN, $id ) ? ( new Links() )->find( $id, time() ) : null;
		if ( null === $link ) {
			Console::error( __( 'This connection does not exist (any more).', 'lw-migration' ) );
			return;
		}
		self::confirm( self::removeQuestion( $link['direction'], $link['name'] . ' (' . $link['address'] . ')' ), $assoc );
		try {
			$removed = LinksApi::fromServices( Services::get() )->remove( $id );
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		if ( Links::IN === $link['direction'] ) {
			Console::success( __( 'Removed.', 'lw-migration' ) );
			return;
		}
		Console::success( $removed['notified'] ? __( 'Removed. The other site was told.', 'lw-migration' ) : __( 'Removed here; the other site could not be told — remove it there too.', 'lw-migration' ) );
	}

	/**
	 * Stops every subcommand on a multisite network (spec 19).
	 */
	public static function singleSiteOnly(): void {
		if ( is_multisite() ) {
			Console::error( __( 'Connections work between single sites only.', 'lw-migration' ) );
		}
	}

	/**
	 * The question before a removal: an outgoing connection is what lets
	 * this site send or copy, an incoming one what lets the other site.
	 *
	 * @param string $direction Links::IN or Links::OUT.
	 * @param string $site      The other site's name and address.
	 */
	public static function removeQuestion( string $direction, string $site ): string {
		if ( Links::OUT === $direction ) {
			/* translators: %s: the other site's name and address */
			return sprintf( __( 'Remove the connection with %s? This site can no longer send itself there or copy that site; the other site is told. [y/n]', 'lw-migration' ), $site );
		}

		/* translators: %s: the other site's name and address */
		return sprintf( __( 'Remove the connection with %s? The other site can no longer use it. [y/n]', 'lw-migration' ), $site );
	}

	/**
	 * Returns only when the removal is confirmed; otherwise stops with an error.
	 *
	 * @param string               $question The question (removeQuestion()).
	 * @param array<string, mixed> $assoc    Flags.
	 */
	private static function confirm( string $question, array $assoc ): void {
		$ask      = static fn(): ?string => Prompt::line( $question );
		$decision = Confirmation::decide( $assoc, Confirmation::stdinIsInteractive(), $ask );
		if ( Confirmation::NEEDS_YES === $decision ) {
			Console::error( __( 'Pass --yes to remove without a prompt.', 'lw-migration' ) );
		}
		if ( Confirmation::DECLINED === $decision ) {
			Console::error( __( 'Nothing removed.', 'lw-migration' ) );
		}
	}

	/**
	 * The rights of --allow; any other value stops the command.
	 *
	 * @param mixed $value --allow value, e.g. pull or pull,push.
	 * @return array<int, string>
	 */
	private static function rights( $value ): array {
		$parts = is_string( $value ) ? explode( ',', $value ) : [];
		$allow = Links::allow( $parts );
		if ( [] === $parts || count( $allow ) !== count( $parts ) ) {
			Console::error( __( 'Give --allow=pull, --allow=push or --allow=pull,push.', 'lw-migration' ) );
		}

		return $allow;
	}

	/**
	 * @param array<int, string> $allow Rights.
	 */
	private static function allowText( array $allow ): string {
		$parts = [];
		if ( in_array( Links::PULL, $allow, true ) ) {
			$parts[] = __( 'copy this site (pull)', 'lw-migration' );
		}
		if ( in_array( Links::PUSH, $allow, true ) ) {
			$parts[] = __( 'replace this site\'s content (push)', 'lw-migration' );
		}

		/* translators: %s: what the other site may do, e.g. "copy this site (pull)" */
		return sprintf( __( 'The other site may: %s.', 'lw-migration' ), implode( ', ', $parts ) );
	}

	/**
	 * The admin of --user (id 0 and no login without it).
	 *
	 * @return array{id: int, login: string}
	 */
	private static function owner(): array {
		return [
			'id'    => get_current_user_id(),
			'login' => (string) wp_get_current_user()->user_login,
		];
	}

	private static function userText(): string {
		return __( 'Pass --user=<admin login>: the connection acts for that admin.', 'lw-migration' );
	}

	/**
	 * Ruling U1: plain http works, with a warning (also before a push) that
	 * the site travels unencrypted and can be read on the way (issue #1;
	 * the signed answers refuse a changed one).
	 */
	public static function httpWarning(): void {
		Console::warning( __( 'This connection uses unencrypted http: the whole site travels unencrypted, and anyone on the network between the two sites can read it on the way, including its users and their password hashes. Use https on both sites where you can.', 'lw-migration' ) );
	}
}

<?php
/**
 * Pairing two sites with a one-time code.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Pairing two sites (spec 19, protocol 1). The site that makes the code (B)
 * stores the link as `in` and confirms it on the first signed hello, which
 * also mails B's admins; the site that types the code (A) stores it as
 * `out` once the proof checks out, then says hello. The code's nonce never
 * travels: a request names the code by KeyExchange::lookup() and proves it
 * holds the code with KeyExchange::pairMac() over what it declares
 * (ruling I6). Both sides accept only addresses of PairingCode's allow-list
 * and names of valid UTF-8 without control characters, which they clip to
 * Links::MAX_TEXT bytes before storing. Decisions 1–5 of the M5 plan.
 */
final class Pairing {

	public const PROTOCOL = 1;

	public const ROUTE = '/lw-migration/v1/link/pair';

	/**
	 * @param Links         $links  Links.
	 * @param Codes         $codes  The open code.
	 * @param \Closure      $now    fn(): int.
	 * @param \Closure|null $send   Transport for add() (default: Client's guarded transport).
	 * @param \Closure|null $notify fn( array $link ): void after a link's first confirmation (default PairingMail::later).
	 */
	public function __construct( private Links $links, private Codes $codes, private \Closure $now, private ?\Closure $send = null, private ?\Closure $notify = null ) {
	}

	public static function fromWordPress(): self {
		return new self(
			new Links(),
			new Codes(),
			static fn(): int => time(),
			null,
			static function ( array $link ): void {
				PairingMail::later( $link ); // After the answer: the other site waits for it.
			}
		);
	}

	/**
	 * Where the other site reaches this one: its requests go to
	 * <address>/index.php?rest_route=… An international host name is sent in
	 * its ASCII form and a non-ASCII path percent-encoded, so the address
	 * fits a pairing code.
	 */
	public static function address(): string {
		/**
		 * The address another site uses to reach this one (default
		 * site_url()), for a site behind a proxy or reachable under another
		 * name.
		 *
		 * @param string $address Address.
		 */
		return PairingCode::ascii( rtrim( (string) apply_filters( 'lw_migration_link_address', site_url() ), '/' ) );
	}

	/**
	 * B: a new code; the previous one stops working. Without an owner or a
	 * right Codes::issue() throws \InvalidArgumentException.
	 *
	 * @param array{id: int, login: string} $owner Admin the connection acts for.
	 * @param array<int, string>            $allow What the other site may do here.
	 * @return array{code: string, expiresAt: int, insecure: bool}
	 * @throws LinkException SCHEME for an address another site cannot be given in a code.
	 */
	public function createCode( array $owner, array $allow ): array {
		$address = self::address();
		Client::assertScheme( $address );
		if ( ! PairingCode::isAddress( $address ) ) {
			throw LinkException::unfitAddress( $address );
		}
		$issued = $this->codes->issue( $owner, $allow, $this->now() );
		try {
			$code = PairingCode::encode( $address, $issued['nonce'] );
		} catch ( \InvalidArgumentException $e ) {
			$this->codes->clear();
			throw LinkException::unfitAddress( $address );
		}

		return [
			'code'      => $code,
			'expiresAt' => $issued['expiresAt'],
			'insecure'  => Client::insecure( $address ),
		];
	}

	/**
	 * B, read only: a well-formed pairing request for the open code, whose
	 * MAC proves it holds that code.
	 *
	 * @param mixed $body Decoded JSON body.
	 */
	public function opens( $body ): bool {
		return null !== $this->checked( $body );
	}

	/**
	 * B: answers a pairing request (after opens()). The secret is derived
	 * and the room checked before the code is used up; a code another
	 * request used meanwhile answers null (the refusal).
	 *
	 * @param mixed  $body Decoded JSON body.
	 * @param string $from The request's IP (shown to B's admins, I7).
	 * @return array{id: string, public: string, name: string, address: string, allow: array<int, string>, protocol: int, proof: string}|null
	 * @throws \RuntimeException Links::FULL before the code is used; Links::CODE_USED when the link cannot be stored after it was used (m12).
	 */
	public function accept( $body, string $from ): ?array {
		$now     = $this->now();
		$checked = $this->checked( $body );
		if ( null === $checked ) {
			return null;
		}
		[ $request, $code ] = $checked;
		if ( ! $this->links->room( $now ) ) {
			throw new \RuntimeException( 'Too many connections.', Links::FULL );
		}
		$id   = Links::newId();
		$own  = KeyExchange::keypair();
		$peer = $request['public'];
		try {
			$secret = KeyExchange::secret( $own['secret'], $peer, $id, $peer, $own['public'], $code['nonce'] );
		} catch ( \InvalidArgumentException $e ) {
			return null; // A degenerate key: the code stays usable.
		}
		$used = $this->codes->redeem( $request['code'], $now );
		if ( null === $used ) {
			return null;
		}
		$this->storeIncoming( $id, $request, $used, $secret, $from, $now );

		return [
			'id'       => $id,
			'public'   => bin2hex( $own['public'] ),
			'name'     => self::label( (string) get_bloginfo( 'name' ) ),
			'address'  => self::address(),
			'allow'    => $used['allow'],
			'protocol' => self::PROTOCOL,
			'proof'    => KeyExchange::proof( $secret, $id, $peer, $own['public'] ),
		];
	}

	/**
	 * B: stores the unconfirmed incoming link of a used code.
	 *
	 * @param string                                                                     $id      Link id.
	 * @param array{code: string, public: string, address: string, name: string}         $request The checked request.
	 * @param array{createdBy: array{id: int, login: string}, allow: array<int, string>} $used The used code.
	 * @param string                                                                     $secret  The link secret.
	 * @param string                                                                     $from    The request's IP.
	 * @param int                                                                        $now     Unix time.
	 * @throws \RuntimeException Links::CODE_USED when it cannot be stored (m12).
	 */
	private function storeIncoming( string $id, array $request, array $used, string $secret, string $from, int $now ): void {
		$link = [
			'id'         => $id,
			'direction'  => Links::IN,
			'address'    => $request['address'],
			'name'       => $request['name'],
			'allow'      => $used['allow'],
			'createdBy'  => $used['createdBy'],
			'pairedFrom' => Text::clip( $from, 64 ),
		];
		try {
			$this->links->add( $link, $secret, $now, false );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			throw new \RuntimeException( 'The code was used but the link could not be stored.', Links::CODE_USED );
		}
	}

	// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInComment -- "wordpress" is the answer's lower-case key.
	/**
	 * B: the signed hello confirms the link and describes this site; the
	 * first confirmation mails this site's admins (I7).
	 *
	 * @param array<string, mixed> $link The verified link (Guard).
	 * @return array{protocol: int, name: string, address: string, allow: array<int, string>, wordpress: string}
	 * @throws \RuntimeException When a pending link cannot be confirmed (the route answers Guard::busy()).
	 */
	public function hello( array $link ): array { // phpcs:enable WordPress.WP.CapitalPDangit.MisspelledInComment
		if ( ! $link['confirmed'] ) {
			if ( ! $this->links->confirm( (string) $link['id'], $this->now() ) ) {
				throw new \RuntimeException( 'The link could not be confirmed.' ); // The other site removes its side again.
			}
			if ( null !== $this->notify ) {
				( $this->notify )( array_diff_key( $link, [ 'key' => true ] ) ); // The mail never needs the secret.
			}
		}

		return [
			'protocol'  => self::PROTOCOL,
			'name'      => self::label( (string) get_bloginfo( 'name' ) ),
			'address'   => self::address(),
			'allow'     => Links::allow( (array) $link['allow'] ),
			'wordpress' => (string) get_bloginfo( 'version' ),
		];
	}

	/**
	 * A: connects with the other site's code, then says hello (Joiner).
	 *
	 * @param string                        $code  The code typed in.
	 * @param array{id: int, login: string} $owner Admin who typed the code.
	 * @return array<string, mixed> The new link's view (Links::all()), `insecure` true when either address is http.
	 * @throws \InvalidArgumentException Without a valid owner (nothing is sent).
	 * @throws LinkException On every other failure, with a user-facing message.
	 */
	public function add( string $code, array $owner ): array {
		return ( new Joiner( $this->links, $this->now, $this->send ) )->add( $code, $owner );
	}

	/**
	 * Read only: the request and the open code it proves, or null.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return array{0: array{code: string, public: string, address: string, name: string}, 1: array{nonce: string, createdBy: array{id: int, login: string}, allow: array<int, string>, raw: string}}|null
	 */
	private function checked( $body ): ?array {
		$request = is_multisite() ? null : PairingMessage::request( $body );
		$code    = null === $request ? null : $this->codes->matches( $request['code'], $this->now() ); // Holds the opened nonce: never logged, never answered.
		if ( null === $request || null === $code || ! hash_equals( KeyExchange::pairMac( $code['nonce'], $request['public'], $request['address'], $request['name'] ), $request['mac'] ) ) {
			return null;
		}
		$request['name'] = self::label( $request['name'] );
		unset( $request['mac'] );

		return [ $request, $code ];
	}

	/**
	 * A site name as both sides store and show it: plain text, at most Links::MAX_TEXT bytes.
	 *
	 * @param string $text Name.
	 */
	public static function label( string $text ): string {
		return Text::clip( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', sanitize_text_field( $text ) ), Links::MAX_TEXT );
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}

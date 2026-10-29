<?php
/**
 * The site that types a pairing code.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Side A of a pairing (Pairing::add()): sends the unsigned pairing request
 * named by the code's lookup and proven by its MAC (the nonce never travels,
 * ruling I6), checks the other site's proof, stores the outgoing link and
 * says the first signed hello. Every failure is a LinkException with the
 * user's text.
 */
final class Joiner {

	/**
	 * @param Links         $links Links.
	 * @param \Closure      $now   fn(): int.
	 * @param \Closure|null $send  Transport (default: Client's guarded transport).
	 */
	public function __construct( private Links $links, private \Closure $now, private ?\Closure $send = null ) {
	}

	/**
	 * Connects with the other site's code, then says hello (a failed hello
	 * removes the new link again). This site's address and room are checked
	 * before the request, so a failure here never uses the code up.
	 *
	 * @param string                        $code  The code typed in.
	 * @param array{id: int, login: string} $owner Admin who typed the code.
	 * @return array<string, mixed> The new link's view (Links::all()), `insecure` true when either address is http.
	 * @throws \InvalidArgumentException Without a valid owner (nothing is sent).
	 * @throws LinkException On every other failure, with a user-facing message.
	 */
	public function add( string $code, array $owner ): array {
		if ( ! LinkRow::isOwner( LinkRow::owner( $owner ) ) ) {
			throw new \InvalidArgumentException( 'A connection needs an admin.' );
		}
		$parsed = $this->decode( $code );
		$mine   = Pairing::address();
		if ( ! PairingCode::isAddress( $mine ) ) {
			throw LinkException::unfitAddress( $mine );
		}
		if ( ! $this->links->room( $this->now() ) ) {
			throw LinkException::notStored( Links::FULL ); // Before the other site's code is used up (m5).
		}
		$own  = KeyExchange::keypair();
		$name = Pairing::label( (string) get_bloginfo( 'name' ) );
		try {
			$answer = Client::unsigned(
				$this->send,
				$parsed['address'],
				Pairing::ROUTE,
				[
					'protocol' => Pairing::PROTOCOL,
					'code'     => KeyExchange::lookup( $parsed['nonce'] ),
					'public'   => bin2hex( $own['public'] ),
					'address'  => $mine,
					'name'     => $name,
					'mac'      => KeyExchange::pairMac( $parsed['nonce'], $own['public'], $mine, $name ),
				],
				$this->now()
			);
		} catch ( LinkException $e ) {
			throw LinkException::pairing( $e );
		}
		$peer   = self::answerOf( $answer );
		$secret = $this->secretFor( $own, $peer, $parsed['nonce'] );
		$this->store( $peer, $parsed['address'], $secret, $owner );
		$this->sayHello( $peer['id'], $parsed['address'], $secret );
		foreach ( $this->links->all( $this->now() ) as $view ) {
			if ( $view['id'] === $peer['id'] ) {
				return array_merge( $view, [ 'insecure' => $view['insecure'] || Client::insecure( $mine ) ] );
			}
		}
		throw LinkException::noMatch(); // Removed meanwhile.
	}

	/**
	 * A: the other site's answer to the pairing request, or PROTOCOL.
	 *
	 * @param array<mixed> $answer Decoded answer.
	 * @return array{id: string, public: string, name: string, allow: array<int, string>, proof: string}
	 * @throws LinkException PROTOCOL for any other shape.
	 */
	private static function answerOf( array $answer ): array {
		$peer = PairingMessage::answer( $answer );
		if ( null === $peer ) {
			throw LinkException::noMatch();
		}
		$peer['name'] = Pairing::label( $peer['name'] );

		return $peer;
	}

	/**
	 * A: the secret, once the other site's proof shows it derived the same.
	 *
	 * @param array{public: string, secret: string}                                                     $own   This side's key pair.
	 * @param array{id: string, public: string, name: string, allow: array<int, string>, proof: string} $peer  The answer.
	 * @param string                                                                                    $nonce The code's nonce.
	 * @throws LinkException PROTOCOL when the proof does not match.
	 */
	private function secretFor( array $own, array $peer, string $nonce ): string {
		try {
			$secret = KeyExchange::secret( $own['secret'], $peer['public'], $peer['id'], $own['public'], $peer['public'], $nonce );
		} catch ( \InvalidArgumentException $e ) {
			throw LinkException::noMatch();
		}
		if ( ! hash_equals( KeyExchange::proof( $secret, $peer['id'], $own['public'], $peer['public'] ), $peer['proof'] ) ) {
			throw LinkException::noMatch();
		}

		return $secret;
	}

	/**
	 * A: stores the outgoing link.
	 *
	 * @param array{id: string, public: string, name: string, allow: array<int, string>, proof: string} $peer    The answer.
	 * @param string                                                                                    $address The code's address.
	 * @param string                                                                                    $secret  The link secret.
	 * @param array{id: int, login: string}                                                             $owner   Admin who typed the code.
	 * @throws LinkException CODE when this site cannot store it; an id this site already holds means the code was made here (m6).
	 */
	private function store( array $peer, string $address, string $secret, array $owner ): void {
		try {
			$this->links->add(
				[
					'id'         => $peer['id'],
					'direction'  => Links::OUT,
					'address'    => $address,
					'name'       => $peer['name'],
					'allow'      => $peer['allow'],
					'createdBy'  => $owner,
					'pairedFrom' => '',
				],
				$secret,
				$this->now(),
				true
			);
		} catch ( \InvalidArgumentException $e ) {
			throw LinkException::noMatch();
		} catch ( \RuntimeException $e ) {
			if ( Links::TAKEN === $e->getCode() ) {
				throw LinkException::ownCode(); // The answer came from this site under another address.
			}
			throw LinkException::notStored( (int) $e->getCode() );
		}
	}

	/**
	 * A: the first signed request; a failure removes the new link again.
	 *
	 * @param string $id      Link id.
	 * @param string $address The other site's address.
	 * @param string $secret  The link secret.
	 * @throws LinkException When the other site does not confirm.
	 */
	private function sayHello( string $id, string $address, string $secret ): void {
		$client = new Client(
			[
				'id'      => $id,
				'address' => $address,
				'key'     => $secret,
			],
			$this->send,
			$this->now
		);
		try {
			$client->json( 'POST', '/hello' );
		} catch ( LinkException $e ) {
			$this->links->remove( $id );
			throw $e;
		}
	}

	/**
	 * A: the code typed in, or CODE.
	 *
	 * @param string $code Code.
	 * @return array{address: string, nonce: string}
	 * @throws LinkException CODE or SCHEME (also for an IP address the filter does not allow).
	 */
	private function decode( string $code ): array {
		try {
			$parsed = PairingCode::decode( $code );
		} catch ( \InvalidArgumentException $e ) {
			throw LinkException::notACode();
		}
		$parsed['address'] = rtrim( $parsed['address'], '/' );
		Client::assertScheme( $parsed['address'] );
		if ( AddressGuard::isLiteral( trim( (string) wp_parse_url( $parsed['address'], PHP_URL_HOST ), '[]' ) ) && ! self::ipHostsAllowed() ) {
			throw LinkException::ipHost();
		}
		if ( strtolower( $parsed['address'] ) === strtolower( Pairing::address() ) ) {
			throw LinkException::ownCode();
		}

		return $parsed;
	}

	/**
	 * Issue #2: a code typed in here comes from someone else, so by default
	 * it must name the other site by its host name: an IP address could aim
	 * this site's requests at its own network.
	 */
	private static function ipHostsAllowed(): bool {
		/**
		 * Whether a pairing code may name the other site by an IP address
		 * (for example a local test site on 127.0.0.1). Default false. The
		 * address is still checked before every request: the metadata
		 * service and other refused ranges stay refused, and loopback or
		 * private addresses also need core's http_request_host_is_external.
		 *
		 * @param bool $allow Whether IP addresses are allowed.
		 */
		return true === apply_filters( 'lw_migration_allow_ip_link_hosts', false );
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}

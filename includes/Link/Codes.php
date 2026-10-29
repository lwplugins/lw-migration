<?php
/**
 * The one open pairing code of this site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * The one open pairing code of this site: a lookup hash of its nonce (how a
 * pairing request names it), the nonce itself sealed (SecretBox, own key
 * context: the code's site needs it to check the request's MAC and derive
 * the secret, and it never travels), its expiry, the admin who made it (the
 * link acts for that admin) and what the other site may do. A new code
 * replaces the old one; redeem() uses it up by deleting the exact row read
 * (OptionRow::deleteIf), so a code works once even when two requests race.
 * matches() only reads: an unknown code writes nothing.
 */
final class Codes {

	public const TTL = 900;

	private const LOOKUP_PATTERN = '/^[a-f0-9]{64}\z/';

	private const KEY_CONTEXT = 'lw-migration/link-code/v1';

	/**
	 * The option row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null     $db  Database object; defaults to the global $wpdb.
	 * @param SecretBox|null $box Sealing key (default: derived from wp_salt( 'auth' ) with KEY_CONTEXT).
	 */
	public function __construct( ?object $db = null, private ?SecretBox $box = null ) {
		$this->row = new OptionRow( Options::LINK_CODE, $db );
	}

	/**
	 * @param array{id: int, login: string} $owner Admin who makes the code.
	 * @param array<int, string>            $allow What the other site may do here.
	 * @param int                           $now   Unix time.
	 * @return array{nonce: string, expiresAt: int}
	 * @throws \InvalidArgumentException For an empty right list or no owner.
	 * @throws \RuntimeException When the row cannot be written.
	 */
	public function issue( array $owner, array $allow, int $now ): array {
		$allow = Links::allow( $allow );
		$login = $owner['login'];
		if ( [] === $allow || $owner['id'] <= 0 || '' === $login || strlen( $login ) > LinkRow::MAX_LOGIN || ! LinkRow::utf8( $login ) ) {
			throw new \InvalidArgumentException( 'A pairing code needs an admin and at least one right.' );
		}
		$nonce = bin2hex( random_bytes( 16 ) );
		$value = json_encode(
			[
				'lookup'    => KeyExchange::lookup( $nonce ),
				'nonce'     => $this->box()->seal( $nonce ),
				'expiresAt' => $now + self::TTL,
				'createdBy' => [
					'id'    => $owner['id'],
					'login' => $login,
				],
				'allow'     => $allow,
			]
		);
		$raw   = $this->row->read();
		if ( false === $value || ! ( null === $raw ? $this->row->insert( $value ) : $this->row->swap( $raw, $value ) ) ) {
			throw new \RuntimeException( 'The pairing code could not be saved.' );
		}

		return [
			'nonce'     => $nonce,
			'expiresAt' => $now + self::TTL,
		];
	}

	/**
	 * Read only.
	 *
	 * @param string $lookup KeyExchange::lookup() of the nonce, as the pairing request names it.
	 * @param int    $now    Unix time.
	 * @return array{nonce: string, createdBy: array{id: int, login: string}, allow: array<int, string>, raw: string}|null
	 */
	public function matches( string $lookup, int $now ): ?array {
		if ( 1 !== preg_match( self::LOOKUP_PATTERN, $lookup ) ) {
			return null;
		}
		$raw  = $this->row->read();
		$data = $this->decode( $raw );
		if ( null === $raw || null === $data || ! hash_equals( $data['lookup'], $lookup ) || $data['expiresAt'] <= $now ) {
			return null;
		}
		$nonce = $this->box()->open( $data['nonce'] );
		if ( null === $nonce || 1 !== preg_match( PairingCode::NONCE_PATTERN, $nonce ) ) {
			return null;
		}

		return [
			'nonce'     => $nonce,
			'createdBy' => $data['createdBy'],
			'allow'     => Links::allow( $data['allow'] ),
			'raw'       => $raw,
		];
	}

	/**
	 * @param string $lookup KeyExchange::lookup() of the nonce.
	 * @param int    $now    Unix time.
	 * @return array{nonce: string, createdBy: array{id: int, login: string}, allow: array<int, string>}|null Null when the code does not match or was used meanwhile.
	 */
	public function redeem( string $lookup, int $now ): ?array {
		$match = $this->matches( $lookup, $now );
		if ( null === $match || [] === $match['allow'] || ! $this->row->deleteIf( $match['raw'] ) ) {
			return null;
		}
		unset( $match['raw'] );

		return $match;
	}

	/**
	 * @param int $now Unix time.
	 * @return int|null When the open code expires; null without a live one.
	 */
	public function pending( int $now ): ?int {
		$data = $this->decode( $this->row->read() );

		if ( null === $data || $data['expiresAt'] <= $now || null === $this->box()->open( $data['nonce'] ) ) {
			return null; // Gone, or sealed with a salt that no longer exists: a dead code is not shown.
		}

		return $data['expiresAt'];
	}

	/** Forgets the open code (the exact row read; a newer code stays). */
	public function clear(): void {
		$raw = $this->row->read();
		if ( null !== $raw ) {
			$this->row->deleteIf( $raw );
		}
	}

	/**
	 * Deletes the row when its code expired (or is unreadable).
	 *
	 * @param int $now Unix time.
	 */
	public function prune( int $now ): void {
		$raw = $this->row->read();
		if ( null === $raw ) {
			return;
		}
		$data = $this->decode( $raw );
		if ( null === $data || $data['expiresAt'] <= $now ) {
			$this->row->deleteIf( $raw );
		}
	}

	/**
	 * @param string|null $raw Row value.
	 * @return array{lookup: string, nonce: string, expiresAt: int, createdBy: array{id: int, login: string}, allow: array<int, string>}|null
	 */
	private function decode( ?string $raw ): ?array {
		$data = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || ! is_string( $data['lookup'] ?? null ) || ! is_string( $data['nonce'] ?? null ) || ! is_int( $data['expiresAt'] ?? null )
			|| ! is_array( $data['allow'] ?? null ) || ! is_array( $data['createdBy'] ?? null )
			|| ! is_int( $data['createdBy']['id'] ?? null ) || $data['createdBy']['id'] <= 0
			|| ! is_string( $data['createdBy']['login'] ?? null ) || '' === $data['createdBy']['login'] ) {
			return null;
		}

		return [
			'lookup'    => $data['lookup'],
			'nonce'     => $data['nonce'],
			'expiresAt' => $data['expiresAt'],
			'createdBy' => [
				'id'    => $data['createdBy']['id'],
				'login' => $data['createdBy']['login'],
			],
			'allow'     => array_values( $data['allow'] ),
		];
	}

	/**
	 * @throws \RuntimeException When the server has no sodium functions.
	 */
	private function box(): SecretBox {
		if ( null === $this->box ) {
			if ( ! SecretBox::available() ) {
				throw new \RuntimeException( 'The sodium functions are missing.' );
			}
			$this->box = new SecretBox( hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true ) );
		}

		return $this->box;
	}
}

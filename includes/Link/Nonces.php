<?php
/**
 * The replay cache of signed requests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * The replay cache of signed requests: `<link>:<nonce>` -> when it may be
 * forgotten (Signer::WINDOW after the later of its time and now; a request
 * outside the window is refused anyway). Expired entries are dropped on
 * every write; at most MAX entries. Written only after a signature was
 * verified (Guard). A full cache or five lost compare-and-swap races answer
 * BUSY (the other site tries again), never SEEN: contention must not look
 * like a replay.
 */
final class Nonces {

	public const OK = 'ok';

	public const SEEN = 'seen';

	public const BUSY = 'busy';

	public const MAX = 4096;

	private const TRIES = 5;

	private const KEY_PATTERN = '/^[a-f0-9]{16}:[a-f0-9]{32}\z/';

	/**
	 * The option row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null $db Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?object $db = null ) {
		$this->row = new OptionRow( Options::LINK_NONCES, $db );
	}

	/**
	 * @param string $linkId Link id.
	 * @param string $nonce  Request nonce (32 hex).
	 * @param int    $time   The request's time.
	 * @param int    $now    Unix time.
	 * @return string OK, SEEN or BUSY.
	 */
	public function remember( string $linkId, string $nonce, int $time, int $now ): string {
		$key = $linkId . ':' . $nonce;
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return self::BUSY; // Never stored, so never remembered: the request is not served.
		}
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			[ $raw, $seen ] = $this->read( $now );
			if ( isset( $seen[ $key ] ) ) {
				return self::SEEN;
			}
			if ( count( $seen ) >= self::MAX ) {
				return self::BUSY;
			}
			$seen[ $key ] = max( $time, $now ) + Signer::WINDOW;
			$value        = (string) json_encode( $seen );
			if ( null === $raw ? $this->row->insert( $value ) : $this->row->swap( $raw, $value ) ) {
				return self::OK;
			}
		}

		return self::BUSY;
	}

	/**
	 * @param int $now Unix time.
	 * @return int How many entries were dropped.
	 */
	public function prune( int $now ): int {
		[ $raw, $seen ] = $this->read( $now );
		$all            = null === $raw ? [] : (array) json_decode( $raw, true );
		$dropped        = count( $all ) - count( $seen );
		if ( null === $raw || 0 === $dropped ) {
			return 0;
		}

		return $this->row->swap( $raw, (string) json_encode( (object) $seen ) ) ? $dropped : 0;
	}

	/**
	 * @param int $now Unix time.
	 * @return array{0: string|null, 1: array<string, int>} The raw value and its live, well-formed entries.
	 */
	private function read( int $now ): array {
		$raw  = $this->row->read();
		$data = null === $raw ? null : json_decode( $raw, true );
		$seen = [];
		foreach ( is_array( $data ) ? $data : [] as $key => $expires ) {
			if ( is_string( $key ) && 1 === preg_match( self::KEY_PATTERN, $key ) && is_int( $expires ) && $expires >= $now ) {
				$seen[ $key ] = $expires;
			}
		}

		return [ $raw, $seen ];
	}
}

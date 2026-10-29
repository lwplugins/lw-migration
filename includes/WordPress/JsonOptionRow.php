<?php
/**
 * A JSON value in one wp_options row, changed by compare-and-swap.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress' get_option() answers from the object cache, which inside a long request
 * (a job slice) may still hold what the row said when the request began; a
 * write built on that would bring back what another request removed or roll
 * back its edit. This reads the row itself (OptionRow) on every call and
 * writes by compare-and-swap: change() gives the callback the current value
 * and stores its answer only if the row did not change meanwhile, else tries
 * again on the newer value. Stored as JSON (never a PHP object), never
 * autoloaded.
 */
final class JsonOptionRow {

	private const TRIES = 5;

	/**
	 * The option row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param string     $name Option name.
	 * @param \wpdb|null $db   Database object; defaults to the global $wpdb.
	 */
	public function __construct( string $name, ?object $db = null ) {
		$this->row = new OptionRow( $name, $db );
	}

	/**
	 * @return mixed The stored value decoded; null when there is no row or it is not JSON.
	 */
	public function get() {
		return self::decode( $this->row->read() );
	}

	/**
	 * Read, change, compare-and-swap. $change gets the current value (as get()
	 * returns it) and answers the new value, or null to write nothing; it may
	 * run more than once and may throw (nothing is written then).
	 *
	 * @param \Closure $change fn( mixed $current ): ?array.
	 * @return bool False only when every try lost a race or the write failed.
	 */
	public function change( \Closure $change ): bool {
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			$raw = $this->row->read();
			$new = $change( self::decode( $raw ) );
			if ( null === $new ) {
				return true;
			}
			if ( $this->write( $raw, $new ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string|null $raw Stored value.
	 * @return mixed
	 */
	private static function decode( ?string $raw ) {
		return null === $raw ? null : json_decode( $raw, true );
	}

	/**
	 * Removes the row, whatever it holds (tried again when another request
	 * changed it between the read and the delete).
	 *
	 * @return bool True when the row is gone (also when there was none); false
	 *              only when every try lost a race or the delete failed.
	 */
	public function delete(): bool {
		for ( $try = 0; $try < self::TRIES; ++$try ) {
			$raw = $this->row->read();
			if ( null === $raw || $this->row->deleteIf( $raw ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string|null  $raw   Value read before (null: no row yet).
	 * @param array<mixed> $value Value to store.
	 */
	private function write( ?string $raw, array $value ): bool {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return false; // Never store an empty value over the row.
		}
		if ( null === $raw ) {
			return $this->row->insert( $encoded );
		}

		return $raw === $encoded || $this->row->swap( $raw, $encoded );
	}
}

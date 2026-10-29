<?php
/**
 * Atomic reads and writes of one wp_options row.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress add_option() is an upsert (INSERT ... ON DUPLICATE KEY UPDATE) and get_option()
 * reads through the object cache, so neither can decide who wins a race. This
 * class talks to the row directly: insert only if missing, compare-and-swap,
 * delete only if unchanged — each succeeds iff exactly one row was affected.
 * Values are raw strings (no serialization); the row is never autoloaded.
 */
final class OptionRow {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * @param string     $name Option name.
	 * @param \wpdb|null $db   Database object; defaults to the global $wpdb.
	 */
	public function __construct( string $name, ?object $db = null ) {
		global $wpdb;
		$this->name = $name;
		$this->db   = $db ?? $wpdb;
	}

	/**
	 * Current raw value straight from the table, bypassing every cache.
	 */
	public function read(): ?string {
		$value = $this->db->get_var(
			$this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s LIMIT 1", $this->name ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Creates the row unless it exists.
	 *
	 * @param string $value Raw value.
	 */
	public function insert( string $value ): bool {
		return $this->write(
			$this->db->prepare( "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $this->name, $value ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		);
	}

	/**
	 * Replaces the value only if it is still exactly $old.
	 *
	 * @param string $old Value read before.
	 * @param string $new Value to store; must differ from $old (MySQL counts an unchanged row as unaffected).
	 */
	public function swap( string $old, string $new ): bool {
		return $this->write(
			$this->db->prepare( "UPDATE {$this->db->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $this->name, $old ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		);
	}

	/**
	 * Deletes the row only if its value is still exactly $old.
	 *
	 * @param string $old Value read before.
	 */
	public function deleteIf( string $old ): bool {
		return $this->write(
			$this->db->prepare( "DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s", $this->name, $old ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		);
	}

	/**
	 * @param string $sql Prepared statement.
	 */
	private function write( string $sql ): bool {
		$result = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared by the caller; atomic row operation, caches dropped below.
		$this->forgetCached();

		return false !== $result && 1 === (int) $this->db->rows_affected;
	}

	/**
	 * Drops what the options cache may remember about the row (value, "not found", autoload copy).
	 */
	private function forgetCached(): void {
		wp_cache_delete( $this->name, 'options' );
		foreach ( [ 'notoptions', 'alloptions' ] as $key ) {
			$cached = wp_cache_get( $key, 'options' );
			if ( is_array( $cached ) && array_key_exists( $this->name, $cached ) ) {
				unset( $cached[ $this->name ] );
				wp_cache_set( $key, $cached, 'options' );
			}
		}
	}
}

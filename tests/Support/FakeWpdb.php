<?php
/**
 * In-memory stand-in for $wpdb that models the option-row statements of OptionRow.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Models MySQL's rows_affected: INSERT IGNORE on an existing name and an UPDATE
 * that does not change the value both affect 0 rows. prepare() returns an
 * encoded (query, args) pair so the statement can be checked exactly.
 */
final class FakeWpdb {

	public const SELECT = 'SELECT option_value FROM wp_options WHERE option_name = %s LIMIT 1';
	public const INSERT = "INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES (%s, %s, 'off')";
	public const UPDATE = 'UPDATE wp_options SET option_value = %s WHERE option_name = %s AND option_value = %s';
	public const DELETE = 'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s';

	public string $options = 'wp_options';

	public string $base_prefix = 'wp_';

	public string $blogs = 'wp_blogs';

	/** @var array<int, int> Blog ids of the network NetworkReader::existing() sees. */
	public array $blogIds = [ 1 ];

	public int $rows_affected = 0;

	/** @var array<string, array{value: string, autoload: string}> */
	public array $rows = [];

	/** Runs once, before the next write statement (to simulate a concurrent request). */
	public ?\Closure $beforeWrite = null;

	public bool $failWrites = false;

	/** Only writes to this option name fail (the others work). */
	public ?string $failOn = null;

	/**
	 * @param array<string, string> $rows Raw option values present before the test.
	 */
	public static function install( array $rows = [] ): self {
		$db = new self();
		foreach ( $rows as $name => $value ) {
			$db->rows[ $name ] = [
				'value'    => $value,
				'autoload' => 'on',
			];
		}
		$GLOBALS['wpdb'] = $db;
		self::stubCache();

		return $db;
	}

	public static function stubCache(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	public static function reset(): void {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof self ) {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function value( string $name ): ?string {
		return $this->rows[ $name ]['value'] ?? null;
	}

	/**
	 * @param mixed ...$args Placeholder values.
	 */
	public function prepare( string $query, ...$args ): string {
		return (string) json_encode( [ $query, $args ] );
	}

	public function get_var( string $prepared ): ?string {
		[ $sql, $args ] = $this->decode( $prepared, self::SELECT );

		return $this->value( $args[0] );
	}

	/**
	 * The blog id lookup of NetworkReader::existing() and the pages of
	 * NetworkSites (one network: site_id is not modelled).
	 *
	 * @return array<int, string>
	 */
	public function get_col( string $prepared ): array {
		[ $sql, $args ] = json_decode( $prepared, true );
		if ( 'SELECT blog_id FROM wp_blogs WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d' === $sql ) {
			$ids = array_values( array_filter( $this->blogIds, static fn( int $id ): bool => $id > $args[1] ) );
			sort( $ids );

			return array_map( 'strval', array_slice( $ids, 0, $args[2] ) );
		}
		if ( ! str_starts_with( $sql, 'SELECT blog_id FROM wp_blogs WHERE blog_id IN' ) ) {
			throw new \LogicException( 'Unexpected query: ' . $sql );
		}

		return array_map( 'strval', array_values( array_intersect( $this->blogIds, $args ) ) );
	}

	/**
	 * @return int|false
	 */
	public function query( string $prepared ) {
		if ( null !== $this->beforeWrite ) {
			$hook              = $this->beforeWrite;
			$this->beforeWrite = null;
			$hook();
		}
		$this->rows_affected = 0;
		[ $sql, $args ] = json_decode( $prepared, true );
		if ( $this->failWrites || ( null !== $this->failOn && $this->failOn === ( $args[ self::UPDATE === $sql ? 1 : 0 ] ?? null ) ) ) {
			return false;
		}
		if ( self::INSERT === $sql && ! isset( $this->rows[ $args[0] ] ) ) {
			$this->rows[ $args[0] ] = [
				'value'    => $args[1],
				'autoload' => 'off',
			];
			$this->rows_affected    = 1;
		} elseif ( self::UPDATE === $sql && $this->value( $args[1] ) === $args[2] && $args[0] !== $args[2] ) {
			$this->rows[ $args[1] ]['value'] = $args[0];
			$this->rows_affected             = 1;
		} elseif ( self::DELETE === $sql && $this->value( $args[0] ) === $args[1] ) {
			unset( $this->rows[ $args[0] ] );
			$this->rows_affected = 1;
		} elseif ( ! in_array( $sql, [ self::INSERT, self::UPDATE, self::DELETE ], true ) ) {
			throw new \LogicException( 'Unexpected statement: ' . $sql );
		}

		return $this->rows_affected;
	}

	/**
	 * @return array{0: string, 1: array<int, string>}
	 */
	private function decode( string $prepared, string $expected ): array {
		$decoded = json_decode( $prepared, true );
		if ( $expected !== $decoded[0] ) {
			throw new \LogicException( 'Unexpected statement: ' . $decoded[0] );
		}

		return $decoded;
	}
}

<?php
/**
 * Minimal $wpdb over a real mysqli link for integration tests (bin/test-db.sh).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

/**
 * Only what OptionRow uses: options, prepare (%s/%d), get_var, query, rows_affected.
 * Each instance opens its own connection, like a separate request.
 */
final class MysqliWpdb {

	public string $options = 'wp_options';

	public int $rows_affected = 0;

	/** Runs once, before the next write statement (to simulate a concurrent request). */
	public ?\Closure $beforeWrite = null;

	private \mysqli $link;

	public function __construct() {
		mysqli_report( MYSQLI_REPORT_OFF );
		$link = mysqli_init();
		if ( false === $link || ! $link->real_connect( (string) getenv( 'LWM_DB_HOST' ), (string) getenv( 'LWM_DB_USER' ), (string) getenv( 'LWM_DB_PASS' ), (string) getenv( 'LWM_DB_NAME' ), (int) getenv( 'LWM_DB_PORT' ) ) ) {
			throw new \RuntimeException( 'Cannot connect to the test database.' );
		}
		$link->set_charset( 'utf8mb4' );
		$this->link = $link;
	}

	/**
	 * @param mixed ...$args Placeholder values.
	 */
	public function prepare( string $query, ...$args ): string {
		$i = 0;

		return (string) preg_replace_callback(
			'/%[sd]/',
			function ( array $m ) use ( $args, &$i ): string {
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : "'" . $this->link->real_escape_string( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_var( string $sql ): ?string {
		$result = $this->link->query( $sql );
		if ( ! $result instanceof \mysqli_result ) {
			throw new \RuntimeException( $this->link->error );
		}
		$row = $result->fetch_row();

		return null === $row || null === $row[0] ? null : (string) $row[0];
	}

	/**
	 * @return int|false
	 */
	public function query( string $sql ) {
		if ( null !== $this->beforeWrite ) {
			$hook              = $this->beforeWrite;
			$this->beforeWrite = null;
			$hook();
		}
		if ( false === $this->link->query( $sql ) ) {
			throw new \RuntimeException( $this->link->error );
		}
		$this->rows_affected = (int) $this->link->affected_rows;

		return $this->rows_affected;
	}

	public function close(): void {
		$this->link->close();
	}
}

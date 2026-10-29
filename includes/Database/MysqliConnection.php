<?php
/**
 * Connection over an open mysqli link.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Works with both mysqli report modes: errors as false return or as exception.
 */
final class MysqliConnection implements Connection {

	public function __construct( private \mysqli $link, private string $hostName, private string $database ) {
	}

	public function query( string $sql ): array {
		$result = $this->run( $sql );
		if ( ! $result instanceof \mysqli_result ) {
			return [];
		}
		$rows = [];
		$row  = $result->fetch_assoc();
		while ( is_array( $row ) ) {
			$rows[] = $row;
			$row    = $result->fetch_assoc();
		}
		$result->free();

		return $rows;
	}

	public function stream( string $sql, callable $onRow ): int {
		$result = $this->run( $sql, MYSQLI_USE_RESULT );
		if ( ! $result instanceof \mysqli_result ) {
			return 0;
		}
		$count = 0;
		try {
			$row = $result->fetch_assoc();
			while ( is_array( $row ) ) {
				$onRow( $row );
				++$count;
				$row = $result->fetch_assoc();
			}
		} catch ( \mysqli_sql_exception $e ) {
			throw new QueryException( $e->getMessage(), (int) $e->getCode(), $sql );
		} finally {
			$result->free();
		}
		if ( 0 !== $this->link->errno ) {
			throw new QueryException( $this->link->error, $this->link->errno, $sql );
		}

		return $count;
	}

	public function execute( string $sql ): void {
		$result = $this->run( $sql );
		if ( $result instanceof \mysqli_result ) {
			$result->free();
		}
	}

	public function escape( string $value ): string {
		return $this->link->real_escape_string( $value );
	}

	public function serverVersion(): string {
		$rows = $this->query( 'SELECT VERSION() AS v' );

		return (string) ( $rows[0]['v'] ?? '' );
	}

	public function variable( string $name ): ?string {
		$rows = $this->query( "SHOW VARIABLES LIKE '" . $this->escape( $name ) . "'" );

		foreach ( $rows as $row ) {
			if ( $name === $row['Variable_name'] && isset( $row['Value'] ) ) {
				return (string) $row['Value'];
			}
		}

		return null;
	}

	public function databaseName(): string {
		return $this->database;
	}

	public function host(): string {
		return $this->hostName;
	}

	/**
	 * @param string $sql  Statement.
	 * @param int    $mode MYSQLI_STORE_RESULT or MYSQLI_USE_RESULT.
	 * @return \mysqli_result|bool
	 * @throws QueryException On a server error.
	 */
	private function run( string $sql, int $mode = MYSQLI_STORE_RESULT ) {
		try {
			$result = $this->link->query( $sql, $mode );
		} catch ( \mysqli_sql_exception $e ) {
			throw new QueryException( $e->getMessage(), (int) $e->getCode(), $sql );
		}
		if ( false === $result ) {
			throw new QueryException( $this->link->error, $this->link->errno, $sql );
		}

		return $result;
	}
}

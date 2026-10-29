<?php
/**
 * Scriptable Connection for unit tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\QueryException;

/**
 * Records every execute(); a rule may make a statement fail with an errno.
 */
final class FakeConnection implements Connection {

	/** @var array<int, string> */
	public array $log = [];

	/** @var array<int, array{0: string, 1: int, 2: int, 3: string|null}> needle, errno, remaining failures, message */
	private array $failures = [];

	public string $charset = 'utf8mb4';

	/** @var array<string, string> Server variables returned by variable() (missing: null). */
	public array $variables = [];

	/** @var array<string, array<int, array<string, mixed>>> Scripted results by query prefix. */
	public array $rows = [];

	/** @var string|null The session sql_mode ConnectionGuard::assertSqlMode() reads (null: no answer). */
	public ?string $sqlMode = '';

	/**
	 * Statements starting with $prefix fail with $errno, $times times.
	 */
	public function failOn( string $prefix, int $errno, int $times = PHP_INT_MAX, ?string $message = null ): self {
		$this->failures[] = [ $prefix, $errno, $times, $message ];

		return $this;
	}

	public function query( string $sql ): array {
		if ( 'SELECT @@SESSION.sql_mode AS mode' === $sql ) {
			return null === $this->sqlMode ? [] : [ [ 'mode' => $this->sqlMode ] ];
		}
		foreach ( $this->rows as $prefix => $rows ) {
			if ( 0 === stripos( $sql, $prefix ) ) {
				return $rows;
			}
		}

		return [ [ 'cs' => $this->charset ] ];
	}

	public function stream( string $sql, callable $onRow ): int {
		return 0;
	}

	public function execute( string $sql ): void {
		$this->log[] = $sql;
		foreach ( $this->failures as $i => [ $prefix, $errno, $left, $message ] ) {
			if ( $left > 0 && 0 === stripos( $sql, $prefix ) ) {
				--$this->failures[ $i ][2];
				throw new QueryException( $message ?? "Simulated error {$errno} (max_user_connections)", $errno, $sql );
			}
		}
	}

	public function escape( string $value ): string {
		return addslashes( $value );
	}

	public function serverVersion(): string {
		return '8.0.36';
	}

	public function variable( string $name ): ?string {
		return $this->variables[ $name ] ?? null;
	}

	public function databaseName(): string {
		return 'fake';
	}

	public function host(): string {
		return 'localhost';
	}

	/**
	 * Statements after the last START TRANSACTION.
	 *
	 * @return array<int, string>
	 */
	public function afterLastStart(): array {
		$last = (int) array_search( 'START TRANSACTION', array_reverse( $this->log, true ), true );

		return array_slice( $this->log, $last + 1 );
	}
}

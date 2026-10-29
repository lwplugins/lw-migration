<?php
/**
 * Minimal SQL connection used by the dumper and importer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * All methods throw QueryException on a server error.
 */
interface Connection {

	/**
	 * @param string $sql Statement returning rows.
	 * @return array<int, array<string, string|null>>
	 */
	public function query( string $sql ): array;

	/**
	 * Runs a SELECT unbuffered and hands each row to the callback, so memory stays
	 * flat. No other query may run on this link inside the callback.
	 *
	 * @param string                                     $sql   Statement returning rows.
	 * @param callable(array<string, string|null>): void $onRow Called per row.
	 * @return int Number of rows delivered.
	 */
	public function stream( string $sql, callable $onRow ): int;

	public function execute( string $sql ): void;

	public function escape( string $value ): string;

	public function serverVersion(): string;

	public function variable( string $name ): ?string;

	public function databaseName(): string;

	public function host(): string;
}

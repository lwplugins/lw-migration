<?php
/**
 * Connection wrapper that simulates a lost connection in tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Database\Connection;

/**
 * A real connection that "dies" (throws) at the first statement starting with
 * a prefix, once armed after another statement ran.
 */
final class DyingConnection implements Connection {

	private ?string $after = null;

	private bool $armed = false;

	public function __construct( private Connection $db, private string $dieAt ) {
	}

	public function armAfter( string $statementPrefix ): void {
		$this->after = $statementPrefix;
	}

	public function disarm(): void {
		$this->after = null;
		$this->armed = false;
	}

	public function query( string $sql ): array {
		return $this->db->query( $sql );
	}

	public function stream( string $sql, callable $onRow ): int {
		return $this->db->stream( $sql, $onRow );
	}

	public function execute( string $sql ): void {
		if ( $this->armed && str_starts_with( $sql, $this->dieAt ) ) {
			throw new \RuntimeException( 'Connection lost.' );
		}
		$this->db->execute( $sql );
		if ( null !== $this->after && str_starts_with( $sql, $this->after ) ) {
			$this->armed = true;
		}
	}

	public function escape( string $value ): string {
		return $this->db->escape( $value );
	}

	public function serverVersion(): string {
		return $this->db->serverVersion();
	}

	public function variable( string $name ): ?string {
		return $this->db->variable( $name );
	}

	public function databaseName(): string {
		return $this->db->databaseName();
	}

	public function host(): string {
		return $this->db->host();
	}
}

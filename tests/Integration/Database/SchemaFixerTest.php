<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;

final class SchemaFixerTest extends DatabaseTestCase {

	public function test_fixed_create_keeps_columns_and_indexes_identical(): void {
		$this->exec(
			"CREATE TABLE sf_src (
				id INT NOT NULL AUTO_INCREMENT,
				a ENUM('uuid','inet4','x y') NOT NULL DEFAULT 'uuid',
				b VARCHAR(20) DEFAULT 'INET4' COMMENT 'sort type=asc',
				c INT COMMENT 'a /* b */ c',
				`type` VARCHAR(20) DEFAULT 'AUTOINCREMENT',
				`ROW_FORMAT` INT COMMENT 'WITHOUT ROWID',
				d INT COMMENT 'it''s ROW_FORMAT=PAGE',
				e TEXT,
				PRIMARY KEY (id),
				KEY k_b (b)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='x ROW_FORMAT=PAGE TRANSACTIONAL=1'"
		);
		$create = (string) array_values( self::db()->query( 'SHOW CREATE TABLE sf_src' )[0] )[1];
		$fixed  = ( new SchemaFixer( self::db()->serverVersion() ) )->fixCreate( $create );
		$fixed  = (string) preg_replace( '/CREATE TABLE `sf_src`/', 'CREATE TABLE `sf_copy`', $fixed, 1 );
		$this->exec( $fixed );

		$this->assertSame( $this->columns( 'sf_src' ), $this->columns( 'sf_copy' ) );
		$this->assertSame( $this->indexes( 'sf_src' ), $this->indexes( 'sf_copy' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function columns( string $table ): array {
		return self::db()->query(
			sprintf(
				"SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' ORDER BY ORDINAL_POSITION",
				$table
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function indexes( string $table ): array {
		return self::db()->query(
			sprintf(
				"SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' ORDER BY INDEX_NAME, SEQ_IN_INDEX",
				$table
			)
		);
	}
}

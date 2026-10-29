<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\TableLister;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;

final class TableListerTest extends DatabaseTestCase {

	public function test_lists_prefixed_tables_and_views(): void {
		$this->exec(
			'CREATE TABLE wp_a (id INT)',
			'CREATE TABLE wp_b (id INT)',
			'CREATE TABLE wpx_c (id INT)',
			'CREATE TABLE wbk_x (id INT)',
			'CREATE TABLE other (id INT)',
			'CREATE VIEW wp_v AS SELECT id FROM wp_a'
		);
		$list = ( new TableLister( self::db() ) )->list( [ 'wp_', 'wbk_', 'civicrm_' ] );
		sort( $list['tables'] );
		$this->assertSame( [ 'wbk_x', 'wp_a', 'wp_b' ], $list['tables'] );
		$this->assertSame( [ 'wp_v' ], $list['views'] );

		$all = ( new TableLister( self::db() ) )->list( [] );
		$this->assertCount( 5, $all['tables'] );
	}

	public function test_select_excludes_and_includes(): void {
		$this->assertSame( [ 'a', 'c', 'x' ], TableLister::select( [ 'a', 'b', 'c' ], [ 'b' ], [ 'x', 'a' ] ) );
	}
}

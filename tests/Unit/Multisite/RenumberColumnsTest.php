<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\RenumberColumns;
use PHPUnit\Framework\TestCase;

final class RenumberColumnsTest extends TestCase {

	public function test_the_list_holds_the_reference_columns_and_the_two_order_columns(): void {
		$this->assertCount( 36, RenumberColumns::LIST, '34 reference columns plus wc_orders.customer_id and wc_customer_lookup.user_id.' );
		$this->assertContains( [ 'posts', 'post_author' ], RenumberColumns::LIST );
		$this->assertContains( [ 'comments', 'user_id' ], RenumberColumns::LIST );
		$this->assertContains( [ 'wc_orders', 'customer_id' ], RenumberColumns::LIST );
		$this->assertContains( [ 'wc_customer_lookup', 'user_id' ], RenumberColumns::LIST );
		$this->assertSame( RenumberColumns::LIST, array_values( array_unique( RenumberColumns::LIST, SORT_REGULAR ) ), 'Every pair once.' );
	}

	public function test_every_name_is_a_plain_identifier(): void {
		foreach ( RenumberColumns::LIST as [ $table, $column ] ) {
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*\z/', $table );
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*\z/', $column );
		}
	}

	public function test_only_int_and_bigint_columns_can_hold_the_offset(): void {
		foreach ( [ 'int', 'INT', 'int(11)', 'int unsigned', 'int(10) unsigned', 'bigint', 'bigint(20) unsigned', 'BIGINT UNSIGNED', 'bigint(20) unsigned zerofill' ] as $type ) {
			$this->assertTrue( RenumberColumns::fits( $type ), $type );
		}
		foreach ( [ 'tinyint(4)', 'smallint unsigned', 'mediumint(9)', 'varchar(20)', 'decimal(20,0)', 'bigint1', 'int(11) unsigned; drop', '', 'char(10)' ] as $type ) {
			$this->assertFalse( RenumberColumns::fits( $type ), $type );
		}
	}
}

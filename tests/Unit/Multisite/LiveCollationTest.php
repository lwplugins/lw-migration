<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\LiveCollation;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use PHPUnit\Framework\TestCase;

final class LiveCollationTest extends TestCase {

	public function test_a_staged_value_takes_the_live_columns_collation(): void {
		$this->assertSame( 'u.user_login = CONVERT(s.user_login USING utf8mb4) COLLATE utf8mb4_unicode_520_ci', $this->collation()->login( 'u', 's' ) );
		$this->assertSame( 'CONVERT(s.user_email USING utf8mb3) COLLATE utf8mb3_general_ci', $this->collation()->form( 'user_email', 's.user_email' ) );
	}

	public function test_a_column_without_a_plain_collation_compares_plainly(): void {
		$this->assertSame( 's.user_nicename', $this->collation()->form( 'user_nicename', 's.user_nicename' ) );
		$this->assertSame( 's.display_name', $this->collation()->form( 'display_name', 's.display_name' ) );
		$this->assertSame( 's.other', $this->collation()->form( 'other', 's.other' ) );
	}

	private function collation(): LiveCollation {
		$db       = new FakeConnection();
		$db->rows = [
			'SHOW FULL COLUMNS FROM `wp_users`' => [
				[ 'Field' => 'user_login', 'Collation' => 'utf8mb4_unicode_520_ci' ],
				[ 'Field' => 'user_email', 'Collation' => 'utf8mb3_general_ci' ],
				[ 'Field' => 'user_nicename', 'Collation' => 'binary' ],
				[ 'Field' => 'display_name', 'Collation' => 'x; DROP TABLE y' ],
			],
		];

		return new LiveCollation( $db, '`wp_users`' );
	}
}

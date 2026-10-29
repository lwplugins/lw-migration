<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class TableSwapDeferredTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_failed_view_warnings_are_short_valid_utf8(): void {
		$path = $this->dir . '/views.sql';
		file_put_contents( $path, "CREATE VIEW `wp_v` AS select 1;\n" );
		$db = ( new FakeConnection() )->failOn( 'CREATE OR REPLACE VIEW', 1356, 1, "View references \xff\xfe" . str_repeat( 'y', 5000 ) );

		$warnings = ( new TableSwap( $db, 'lwtab12_', 'wp_' ) )->applyDeferred( $path );
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'View statement failed for `wp_v` (1356): the view references a missing table, column or function', $warnings[0], 'The server text never reaches a warning.' );
		$this->assertSame( 1, preg_match( '//u', $warnings[0] ) );
		$this->assertLessThanOrEqual( 400, strlen( $warnings[0] ) );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\ResetType;
use PHPUnit\Framework\TestCase;

final class ResetTypeTest extends TestCase {

	public function test_each_type_has_its_steps_and_the_backup_comes_first(): void {
		$this->assertSame( [ 'reset_backup', 'reset_plugins' ], ResetType::steps( 'plugins', true ) );
		$this->assertSame( [ 'reset_themes' ], ResetType::steps( 'themes', false ) );
		$this->assertSame( [ 'reset_backup', 'reset_media' ], ResetType::steps( 'media', true ) );
		$this->assertSame( [ 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ], ResetType::steps( 'database', false ) );
		$this->assertSame(
			[ 'reset_backup', 'reset_plugins', 'reset_themes', 'reset_media', 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ],
			ResetType::steps( 'full', true )
		);
	}

	public function test_only_the_five_types_exist(): void {
		foreach ( ResetType::ALL as $type ) {
			$this->assertTrue( ResetType::isValid( $type ) );
		}
		foreach ( [ '', 'Plugins', 'all', 'plugins ', 'db' ] as $bad ) {
			$this->assertFalse( ResetType::isValid( $bad ), $bad );
		}
		$this->expectException( \InvalidArgumentException::class );
		ResetType::steps( 'all', true );
	}

	public function test_what_each_type_touches(): void {
		$this->assertSame( [ true, true, false, false, true ], array_map( [ ResetType::class, 'touchesCode' ], ResetType::ALL ) );
		$this->assertSame( [ false, false, true, false, true ], array_map( [ ResetType::class, 'touchesMedia' ], ResetType::ALL ) );
		$this->assertSame( [ false, false, false, true, true ], array_map( [ ResetType::class, 'touchesDatabase' ], ResetType::ALL ) );
		$this->assertSame( [ true, true, true, false, false ], array_map( [ ResetType::class, 'allowedOnNetwork' ], ResetType::ALL ) );
	}
}

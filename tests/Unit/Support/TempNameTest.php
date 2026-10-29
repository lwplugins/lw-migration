<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use LightweightPlugins\Migration\Support\TempName;
use PHPUnit\Framework\TestCase;

final class TempNameTest extends TestCase {

	public function test_generated_names_match_and_sit_in_the_folder(): void {
		$path = TempName::generate( '/x/y/' );

		$this->assertSame( '/x/y', dirname( $path ) );
		$this->assertTrue( TempName::is( basename( $path ) ) );
		$this->assertTrue( TempName::is( TempName::name() ) );
		$this->assertNotSame( TempName::name(), TempName::name() );
	}

	public function test_only_exact_temp_names_match(): void {
		$this->assertTrue( TempName::is( '.lwm-0123456789abcdef.part' ) );
		foreach ( [ ".lwm-0123456789abcdef.part\n", '.lwm-0123456789ABCDEF.part', '.lwm-0123.part', '.lwm-0123456789abcdef.part.bak', 'x.lwm-0123456789abcdef.part', 'dir/.lwm-0123456789abcdef.part' ] as $name ) {
			$this->assertFalse( TempName::is( $name ), $name );
		}
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit;

use LightweightPlugins\Migration\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {

	public function test_maps_namespace_to_includes(): void {
		$path = Autoloader::path_for( 'LightweightPlugins\\Migration\\Archive\\Format' );
		$this->assertSame( realpath( dirname( __DIR__, 2 ) . '/includes/Archive/Format.php' ), realpath( (string) $path ) );
	}

	public function test_ignores_foreign_classes(): void {
		$this->assertNull( Autoloader::path_for( 'Other\\Thing' ) );
	}
}

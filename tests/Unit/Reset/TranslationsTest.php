<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\Translations;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class TranslationsTest extends TestCase {

	public function test_only_the_files_of_that_slug_go(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/themes' );
			$files = [ 'old-hu_HU.mo', 'old-hu_HU.po', 'old-de_DE_formal.l10n.php', 'old-fr_FR-0123456789abcdef0123456789abcdef.json', 'old-ast.mo', 'oldx-hu_HU.mo', 'old-hu_HU.mo.bak', 'old.mo' ];
			foreach ( $files as $file ) {
				file_put_contents( $dir . '/themes/' . $file, 'x' );
			}
			mkdir( $dir . '/themes/old-hu_HU.po.d' );
			$this->assertSame( 5, Translations::remove( $dir, 'themes', 'old' ) );
			$this->assertSame( [ '.', '..', 'old-hu_HU.mo.bak', 'old-hu_HU.po.d', 'old.mo', 'oldx-hu_HU.mo' ], scandir( $dir . '/themes' ) );
			$this->assertSame( 0, Translations::remove( $dir, 'plugins', 'old' ), 'A missing folder removes nothing.' );
		} finally {
			TempDir::remove( $dir );
		}
	}
}

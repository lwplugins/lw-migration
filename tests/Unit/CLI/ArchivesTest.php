<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\CLI;

use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\CLI\Archives;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ArchivesTest extends TestCase {

	public function test_backup_name_then_path(): void {
		$dir = TempDir::create();
		mkdir( $dir . '/own' );
		file_put_contents( $dir . '/own/a.wpress', 'x' );
		file_put_contents( $dir . '/loose.wpress', 'x' );
		file_put_contents( $dir . '/loose.zip', 'x' );
		$repo = new Repository( $dir . '/own', '' );

		$this->assertSame( $dir . '/own/a.wpress', Archives::resolve( 'a.wpress', $repo ) );
		$this->assertSame( $dir . '/loose.wpress', Archives::resolve( $dir . '/loose.wpress', $repo ) );
		$this->assertNull( Archives::resolve( $dir . '/loose.zip', $repo ) );
		$this->assertNull( Archives::resolve( 'missing.wpress', $repo ) );
		TempDir::remove( $dir );
	}

	public function test_messages_show_what_the_user_typed_instead_of_server_paths(): void {
		$dir = TempDir::create();
		mkdir( $dir . '/out' );
		$link = $dir . '/link';
		symlink( $dir . '/out', $link );

		$message = sprintf( 'Cannot open archive: %s/own/a.wpress; cannot write %s/out/x/.lwm-1.part', $dir, $dir );
		$this->assertSame(
			'Cannot open archive: a.wpress; cannot write out/x/.lwm-1.part',
			Archives::redact( $message, [ $dir . '/own/a.wpress' => 'a.wpress', $link => 'out' ] )
		);
		TempDir::remove( $dir );
	}
}

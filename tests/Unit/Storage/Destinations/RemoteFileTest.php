<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use PHPUnit\Framework\TestCase;

final class RemoteFileTest extends TestCase {

	public function test_round_trips_and_refuses_damage(): void {
		$file = new RemoteFile( 'a.wpress', 42, 1760000000, 'backups/a.wpress' );

		$this->assertEquals( $file, RemoteFile::fromArray( $file->toArray() ) );
		$this->assertNull( RemoteFile::fromArray( array_merge( $file->toArray(), [ 'size' => -1 ] ) ) );
		$this->assertNull( RemoteFile::fromArray( array_merge( $file->toArray(), [ 'name' => 'x/a.wpress' ] ) ) );
		$this->assertNull( RemoteFile::fromArray( [] ) );
	}
}

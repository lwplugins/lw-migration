<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\NetworkRoots;
use PHPUnit\Framework\TestCase;

final class NetworkRootsTest extends TestCase {

	public function test_the_network_mode_adds_nothing(): void {
		$state = [
			'mode'   => 'network',
			'chosen' => [],
		];
		$this->assertSame( [], NetworkRoots::extraRoots( '/c/uploads', '/c', $state ) );
		$this->assertSame( [], NetworkRoots::exclusions( '/c/uploads', '/c', $state ) );
		$this->assertFalse( NetworkRoots::skipsMainUploads( $state ) );
		$this->assertSame( [], NetworkRoots::extraRoots( '/c/uploads', '/c', [] ), 'No network state: nothing.' );
		$this->assertFalse( NetworkRoots::skipsMainUploads( [] ) );
	}

	public function test_chosen_subsites_get_their_own_roots_and_the_main_uploads_lose_sites(): void {
		$state = [
			'mode'   => 'sites',
			'chosen' => [ 1, 3 ],
		];
		$this->assertSame(
			[
				[
					'dir'   => '/c/uploads/sites/3',
					'entry' => 'uploads/sites/3',
				],
				[
					'dir'   => '/c/blogs.dir/3',
					'entry' => 'blogs.dir/3',
				],
			],
			NetworkRoots::extraRoots( '/c/uploads', '/c', $state )
		);
		$this->assertSame( [ '/c/uploads/sites', '/c/blogs.dir' ], NetworkRoots::exclusions( '/c/uploads', '/c', $state ) );
		$this->assertFalse( NetworkRoots::skipsMainUploads( $state ) );
		$this->assertTrue(
			NetworkRoots::skipsMainUploads(
				[
					'mode'   => 'sites',
					'chosen' => [ 3 ],
				]
			)
		);
	}

	public function test_several_subsites_keep_their_order(): void {
		$state = [
			'mode'   => 'sites',
			'chosen' => [ 2, 5 ],
		];

		$this->assertSame(
			[ 'uploads/sites/2', 'blogs.dir/2', 'uploads/sites/5', 'blogs.dir/5' ],
			array_column( NetworkRoots::extraRoots( '/c/uploads/', '/c/', $state ), 'entry' )
		);
		$this->assertSame( '/c/uploads/sites/2', NetworkRoots::extraRoots( '/c/uploads/', '/c/', $state )[0]['dir'], 'Trailing slashes are not doubled.' );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\BlogUrlMove;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use PHPUnit\Framework\TestCase;

final class BlogUrlMoveTest extends TestCase {

	public function test_old_addresses_come_from_the_archive_entry_and_new_ones_from_the_mapping(): void {
		$move = BlogUrlMove::fromMapping( self::mapping( 2, 7 ), self::target( true ), 'wp-content/uploads' );

		$this->assertSame( 2, $move->oldBlogId );
		$this->assertSame( 7, $move->newBlogId );
		$this->assertSame( 'http://old.test/b2', $move->oldSiteUrl );
		$this->assertSame( 'http://10.0.0.5/b2', $move->oldInternalSiteUrl );
		$this->assertSame( 'http://old.test/b2/home', $move->oldHomeUrl );
		$this->assertSame( 'http://10.0.0.5/b2/home', $move->oldInternalHomeUrl );
		$this->assertSame( 'http://old.test/wp-content/uploads/sites/2/', $move->oldUploadsUrl );
		$this->assertSame( 'https://shop.new.test', $move->newSiteUrl );
		$this->assertSame( 'https://shop.new.test/home', $move->newHomeUrl );
		$this->assertSame( 'https://shop.new.test/wp-content/uploads/sites/7/', $move->newUploadsUrl );
		$this->assertSame( 'wp-content/uploads', $move->uploadsRel );
		$this->assertTrue( $move->uploadBlogsDir );
	}

	public function test_the_site_of_a_single_site_archive_has_no_old_id(): void {
		$move = BlogUrlMove::fromMapping( self::mapping( null, 5 ), self::target( false ), 'wp-content/uploads' );

		$this->assertNull( $move->oldBlogId );
		$this->assertFalse( $move->uploadBlogsDir );
	}

	public function test_an_unresolved_mapping_is_refused(): void {
		$this->expectException( \LogicException::class );

		BlogUrlMove::fromMapping( self::mapping( 2, null ), self::target( false ), 'wp-content/uploads' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function mapping( ?int $old, ?int $new ): array {
		return [
			'old'           => [
				'BlogID'          => $old,
				'SiteURL'         => 'http://old.test/b2',
				'HomeURL'         => 'http://old.test/b2/home',
				'InternalSiteURL' => 'http://10.0.0.5/b2',
				'InternalHomeURL' => 'http://10.0.0.5/b2/home',
				'WordPress'       => [ 'UploadsURL' => 'http://old.test/wp-content/uploads/sites/2/' ],
			],
			'newId'         => $new,
			'newSiteUrl'    => 'https://shop.new.test',
			'newHomeUrl'    => 'https://shop.new.test/home',
			'newUploadsUrl' => 'https://shop.new.test/wp-content/uploads/sites/7/',
		];
	}

	private static function target( bool $blogsDir ): TargetNetwork {
		return TargetNetwork::fromArray( [ 'domain' => 'new.test', 'path' => '/', 'scheme' => 'https', 'uploadBlogsDir' => $blogsDir ] );
	}
}

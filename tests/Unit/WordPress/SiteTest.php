<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\WordPress;

use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\WordPress\Site;
use PHPUnit\Framework\TestCase;

final class SiteTest extends TestCase {

	public function test_round_trip_and_derived_values(): void {
		$site = SiteFixture::make( '/srv/a', [ 'themes' => [ 'x' => '/srv/extra-themes' ] ] );
		$copy = Site::fromArray( json_decode( (string) json_encode( $site->toArray() ), true ) );

		$this->assertSame( $site->toArray(), $copy->toArray() );
		$this->assertSame( 'wp-content/uploads', $copy->uploadsRelPath() );
		$this->assertSame( [ '/srv/a/wp-content/themes', '/srv/extra-themes' ], $copy->themeRoots() );
		$target = $copy->siteTarget();
		$this->assertSame( 'https://old.test', $target->homeUrl );
		$this->assertSame( '/srv/a/wp-content', $target->contentDir );
	}

	public function test_uploads_outside_abspath_have_no_relative_path(): void {
		$this->assertSame( '', SiteFixture::make( '/srv/a', [ 'uploadsDir' => '/data/uploads' ] )->uploadsRelPath() );
	}
}

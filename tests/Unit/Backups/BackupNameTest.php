<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\BackupName;
use PHPUnit\Framework\TestCase;

final class BackupNameTest extends TestCase {

	public function test_site_and_safety_names(): void {
		$time = gmmktime( 9, 5, 3, 10, 7, 2026 );
		$this->assertSame( 'shop-example-com-blog-20261007-090503-007.wpress', BackupName::forSite( 'https://Shop.Example.com/blog/', $time, 7 ) );
		$this->assertSame( 'site-20261007-090503-123.wpress', BackupName::forSite( '', $time, 1123 ) );
		$this->assertSame( 'pre-import-20261007-090503.wpress', BackupName::safety( $time ) );
		$this->assertTrue( BackupName::isSafety( BackupName::safety( $time ) ) );
	}

	public function test_the_site_slug_is_the_start_of_every_backup_name(): void {
		$this->assertSame( 'wphu-org', BackupName::siteSlug( 'https://wphu.org' ) );
		$this->assertSame( 'www-example-com-blog', BackupName::siteSlug( 'https://www.Example.com/blog/' ) );
		$this->assertSame( 'localhost', BackupName::siteSlug( 'http://localhost:8080' ) );
		$this->assertSame( 'xn-bcher-kva-example', BackupName::siteSlug( 'https://xn--bcher-kva.example' ) );
		$this->assertSame( 'site', BackupName::siteSlug( '' ) );
		$this->assertSame( 'site-pre-import-example-com', BackupName::siteSlug( 'https://pre-import.example.com' ) );
		$this->assertSame( 'site-pre-reset', BackupName::siteSlug( 'https://pre-reset' ) );
		$this->assertLessThanOrEqual( 100, strlen( BackupName::siteSlug( 'https://' . str_repeat( 'a', 60 ) . '.' . str_repeat( 'b', 60 ) . '.example' ) ) );
	}

	public function test_the_site_slug_of_a_unicode_host_replaces_each_non_ascii_run(): void {
		$this->assertSame( 'b-cher-example', BackupName::siteSlug( 'https://bücher.example' ) );
	}

	public function test_a_pre_reset_name_is_a_safety_backup_too(): void {
		$name = BackupName::preReset( 1791363600 );
		$this->assertSame( 'pre-reset-20261007-090000.wpress', $name );
		$this->assertTrue( BackupName::isPreReset( $name ) );
		$this->assertTrue( BackupName::isSafety( $name ) );
		$this->assertTrue( BackupName::isSafety( BackupName::safety( 1791363600 ) ) );
		$this->assertFalse( BackupName::isPreReset( BackupName::safety( 1791363600 ) ) );
		$this->assertFalse( BackupName::isPreReset( 'pre-reset-20261007-090000-001.wpress' ) );
		$this->assertStringStartsWith( 'site-pre-reset', BackupName::forSite( 'https://pre-reset.example', 1791363600, 1 ) );
	}

	public function test_a_site_named_like_a_safety_backup_is_not_one(): void {
		$time = gmmktime( 9, 5, 3, 10, 7, 2026 );
		$this->assertSame( 'site-pre-import-example-com-20261007-090503-007.wpress', BackupName::forSite( 'https://pre-import.example.com', $time, 7 ) );
		$this->assertSame( 'site-pre-import-20261007-090503-007.wpress', BackupName::forSite( 'https://pre/import', $time, 7 ) );
		$this->assertFalse( BackupName::isSafety( BackupName::forSite( 'https://pre-import.example.com', $time, 7 ) ) );
		$this->assertFalse( BackupName::isSafety( BackupName::forSite( 'https://pre/import', $time, 7 ) ) );
		foreach ( [ 'pre-import-20261007-090503-007.wpress', 'pre-import-anything.wpress', 'pre-import-20261007-090503.wpress.wpress', 'x-pre-import-20261007-090503.wpress' ] as $not ) {
			$this->assertFalse( BackupName::isSafety( $not ), $not );
		}
	}

	public function test_the_slug_cut_leaves_no_trailing_dash(): void {
		$time = gmmktime( 9, 5, 3, 10, 7, 2026 );
		$this->assertSame( str_repeat( 'a', 99 ) . '-20261007-090503-001.wpress', BackupName::forSite( 'https://' . str_repeat( 'a', 99 ) . '.b', $time, 1 ) );
	}

	public function test_time_of_a_generated_name(): void {
		$time = gmmktime( 9, 5, 3, 10, 7, 2026 );
		$this->assertSame( $time, BackupName::timeOf( BackupName::forSite( 'https://example.com', $time, 7 ) ) );
		$this->assertNull( BackupName::timeOf( 'a.wpress' ) );
		$this->assertNull( BackupName::timeOf( 'x-20261399-250000-001.wpress' ) );
	}

	public function test_a_schedule_name_carries_the_schedule_id_and_its_time(): void {
		$name = BackupName::forSchedule( 'https://example.com/blog', 1767225600, 7, '0a1b2c3d' );

		$this->assertSame( 'example-com-blog-20260101-000000-007-0a1b2c3d.wpress', $name );
		$this->assertTrue( BackupName::isValid( $name ) );
		$this->assertSame( '0a1b2c3d', BackupName::scheduleOf( $name ) );
		$this->assertSame( 1767225600, BackupName::timeOf( $name ) );
		$this->assertNull( BackupName::scheduleOf( BackupName::forSite( 'https://example.com', 1767225600, 7 ) ) );
		$this->assertNull( BackupName::scheduleOf( 'pre-import-20260101-000000.wpress' ) );
		$this->assertNull( BackupName::scheduleOf( 'copied-by-hand-0a1b2c3d.wpress' ) );
	}

	public function test_a_schedule_name_needs_a_valid_schedule_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		BackupName::forSchedule( 'https://example.com', 1767225600, 7, '../x' );
	}

	public function test_manual_and_schedule_names_never_read_as_each_other(): void {
		$time = 1767225600;
		foreach ( [ '0a1b2c3d', '12345678', '00000000', 'ffffffff' ] as $id ) {
			foreach ( [ 'https://example.com', 'https://12345678.test', 'https://' . str_repeat( 'a', 120 ) . '.test' ] as $home ) {
				$scheduled = BackupName::forSchedule( $home, $time, 999, $id );
				$manual    = BackupName::forSite( $home, $time, 999 );

				$this->assertSame( $id, BackupName::scheduleOf( $scheduled ), $scheduled );
				$this->assertSame( $time, BackupName::timeOf( $scheduled ), $scheduled );
				$this->assertFalse( BackupName::isSafety( $scheduled ), $scheduled );
				$this->assertNull( BackupName::scheduleOf( $manual ), $manual );
				$this->assertSame( $time, BackupName::timeOf( $manual ), $manual );
			}
		}
		$this->assertNull( BackupName::scheduleOf( 'site-20260101-000000-001-0A1B2C3D.wpress' ), 'Upper-case hex is not ours.' );
		$this->assertNull( BackupName::scheduleOf( 'site-20260101-000000-001-0a1b2c3.wpress' ), 'Seven digits are not an id.' );
	}

	public function test_validation(): void {
		foreach ( [ 'a.wpress', 'migration-wp-20170913-095743-931.wpress', 'site (1).WPRESS' ] as $ok ) {
			$this->assertTrue( BackupName::isValid( $ok ), $ok );
		}
		foreach ( [ '', '.hidden.wpress', '../a.wpress', 'a/b.wpress', 'a\\b.wpress', "a\0.wpress", "a\n.wpress", 'a.zip', str_repeat( 'a', 250 ) . '.wpress' ] as $bad ) {
			$this->assertFalse( BackupName::isValid( $bad ), $bad );
		}
	}

	/**
	 * Review Focus 5 / plan D7.
	 */
	public function test_is_of_site_matches_only_this_sites_generated_names(): void {
		$home = 'https://example.com/shop';
		$own  = BackupName::forSite( $home, 1760238000, 7 );

		$this->assertTrue( BackupName::isOfSite( $own, $home ) );
		$this->assertTrue( BackupName::isOfSite( BackupName::forSchedule( $home, 1760238000, 7, 'abcd1234' ), $home ) );
		$this->assertFalse( BackupName::isOfSite( BackupName::forSite( 'https://example.com', 1760238000, 7 ), $home ), 'Another site in the same bucket.' );
		$this->assertFalse( BackupName::isOfSite( BackupName::forSite( 'https://example.com/shop-old', 1760238000, 7 ), $home ), 'A longer slug that starts like ours.' );
		$this->assertFalse( BackupName::isOfSite( 'example-com-shop.wpress', $home ), 'Copied in by hand.' );
		$this->assertFalse( BackupName::isOfSite( BackupName::safety( 1760238000 ), $home ) );
		$this->assertFalse( BackupName::isOfSite( 'example-com-shop-20261399-000000-001.wpress', $home ), 'Not a real time.' );
	}
}

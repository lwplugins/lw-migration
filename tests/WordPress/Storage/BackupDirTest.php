<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class BackupDirTest extends MonkeyTestCase {

	private const MARKER = '0123456789abcdef';

	public function test_a_missing_row_is_the_default_folder(): void {
		FakeWpdb::install();
		$setting = BackupDir::read();

		$this->assertSame( [ '', null, '' ], [ $setting->path, $setting->moving, $setting->marker ] );
	}

	public function test_a_stored_row_is_read_as_it_is(): void {
		$db = FakeWpdb::install( [ Options::BACKUP_DIR => '{"path":"/h/b","moving":"","marker":"' . self::MARKER . '"}' ] );

		$setting = BackupDir::read( $db );
		$this->assertSame( [ '/h/b', '', self::MARKER ], [ $setting->path, $setting->moving, $setting->marker ] );
		$this->assertSame( '{"path":"/h/b","moving":"","marker":"' . self::MARKER . '"}', $setting->toJson() );
	}

	public function test_parse_is_lenient_and_never_trusts_a_path(): void {
		$cases = [
			'not json',
			'"a string"',
			'{"path":"../x","marker":"' . self::MARKER . '"}',
			'{"path":"rel","marker":"' . self::MARKER . '"}',
			'{"path":"/a/../b","marker":"' . self::MARKER . '"}',
			'{"path":"/a//b","marker":"' . self::MARKER . '"}',
			'{"path":"/a/b\n","marker":"' . self::MARKER . '"}',
			'{"path":["/a"],"marker":"' . self::MARKER . '"}',
			'{"path":"/a/b","moving":"/c","marker":"0123"}',
			'{"path":"/a/b","moving":"/c"}',
			'{"path":"/a/b","moving":"/c","marker":"0123456789ABCDEF"}',
		];
		foreach ( $cases as $raw ) {
			$setting = BackupDir::parse( $raw );
			$this->assertSame( '', $setting->path, $raw );
			$this->assertNull( $setting->moving, $raw );
		}
		$same = BackupDir::parse( '{"path":"/a/b","moving":"/a/b","marker":"' . self::MARKER . '"}' );
		$this->assertSame( [ '/a/b', null ], [ $same->path, $same->moving ] );
		$bad = BackupDir::parse( '{"path":"/a/b","moving":"x/../y","marker":"' . self::MARKER . '"}' );
		$this->assertSame( [ '/a/b', null ], [ $bad->path, $bad->moving ] );
		$back = BackupDir::parse( '{"path":"/a/b","moving":"","marker":"' . self::MARKER . '"}' );
		$this->assertSame( [ '/a/b', '' ], [ $back->path, $back->moving ] );
	}

	public function test_replace_is_compare_and_swap(): void {
		$db    = FakeWpdb::install();
		$first = new BackupDir( '', '/h/b', self::MARKER );
		$this->assertTrue( BackupDir::replace( new BackupDir(), $first, $db ) );
		$this->assertSame( $first->toJson(), $db->value( Options::BACKUP_DIR ) );
		$this->assertSame( 'off', $db->rows[ Options::BACKUP_DIR ]['autoload'] );

		$next = new BackupDir( '/h/b', null, self::MARKER );
		$this->assertTrue( BackupDir::replace( $first, $next, $db ) );
		$this->assertSame( $next->toJson(), $db->value( Options::BACKUP_DIR ) );

		$this->assertFalse( BackupDir::replace( $first, new BackupDir( '/h/c', null, self::MARKER ), $db ), 'The row holds something else.' );
		$this->assertSame( $next->toJson(), $db->value( Options::BACKUP_DIR ) );
		$this->assertTrue( BackupDir::replace( $first, $next, $db ), 'The row already holds the value.' );
	}

	public function test_with_marker_keeps_an_existing_marker_and_makes_one_once(): void {
		$made = ( new BackupDir() )->withMarker();
		$this->assertMatchesRegularExpression( BackupDir::MARKER_PATTERN, $made->marker );
		$this->assertSame( $made, $made->withMarker() );
		$this->assertSame( self::MARKER, ( new BackupDir( '/a', null, self::MARKER ) )->withMarker()->marker );
	}
}

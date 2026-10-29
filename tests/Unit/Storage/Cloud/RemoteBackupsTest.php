<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Cloud;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Storage\Cloud\RemoteBackups;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use PHPUnit\Framework\TestCase;

final class RemoteBackupsTest extends TestCase {

	private const HOME = 'https://example.com';

	public static function file( string $name ): RemoteFile {
		return new RemoteFile( $name, 10, 0, 'p/' . $name );
	}

	public function test_only_this_sites_names_in_the_scope_are_pruned_and_the_new_one_stays(): void {
		$day   = 86400;
		$now   = 1760238000;
		$names = [];
		for ( $i = 0; $i < 4; $i++ ) {
			$names[] = BackupName::forSite( self::HOME, $now - $i * $day, $i );
		}
		$files = array_map( [ self::class, 'file' ], $names );
		$files[] = self::file( BackupName::forSite( 'https://other.test', $now - 9 * $day, 1 ) );
		$files[] = self::file( BackupName::forSchedule( self::HOME, $now - 9 * $day, 1, 'abcd1234' ) );
		$files[] = self::file( BackupName::safety( $now - 9 * $day ) );
		$files[] = self::file( 'mine.wpress' );
		$files[] = self::file( 'lw-migration-test-0123456789abcdef.txt' );

		$pruned = RemoteBackups::toPrune( $files, self::HOME, new Retention( 2 ), $now, $names[0], null );

		$this->assertSame( [ $names[2], $names[3] ], array_map( static fn( RemoteFile $f ): string => $f->name, $pruned ) );
		$this->assertSame( 'p/' . $names[2], $pruned[0]->ref );
	}

	public function test_a_schedule_scope_prunes_only_that_schedules_names(): void {
		$now   = 1760238000;
		$a     = BackupName::forSchedule( self::HOME, $now, 1, 'abcd1234' );
		$b     = BackupName::forSchedule( self::HOME, $now - 86400, 2, 'abcd1234' );
		$c     = BackupName::forSchedule( self::HOME, $now - 86400, 3, 'ffff0000' );
		$files = [ self::file( $a ), self::file( $b ), self::file( $c ), self::file( BackupName::forSite( self::HOME, $now - 99999, 1 ) ) ];

		$pruned = RemoteBackups::toPrune( $files, self::HOME, new Retention( 1 ), $now, $a, 'abcd1234' );

		$this->assertSame( [ $b ], array_map( static fn( RemoteFile $f ): string => $f->name, $pruned ) );
	}

	public function test_ours_keys_this_sites_files_by_name(): void {
		$name = BackupName::forSite( self::HOME, 1760238000, 1 );

		$this->assertSame( [ $name ], array_keys( RemoteBackups::ours( [ self::file( $name ), self::file( 'x.wpress' ) ], self::HOME ) ) );
	}
}

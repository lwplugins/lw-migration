<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupKind;
use PHPUnit\Framework\TestCase;

final class BackupKindTest extends TestCase {

	private static function kind( string $name, string $folder = Backup::OWN ): string {
		return BackupKind::of( new Backup( $name, '/x/' . $name, 10, 1, $folder ) );
	}

	public function test_each_backup_of_our_folder_has_one_kind_by_its_name(): void {
		$this->assertSame( BackupKind::SCHEDULED, self::kind( 'site-test-20261016-030012-418-abcd1234.wpress' ) );
		$this->assertSame( BackupKind::MANUAL, self::kind( 'site-test-20261015-135934-564.wpress' ) );
		$this->assertSame( BackupKind::MANUAL, self::kind( 'copied-in-by-hand.wpress' ), 'A file without our time stamp is a manual one.' );
		$this->assertSame( BackupKind::SAFETY_RESTORE, self::kind( 'pre-import-20261015-134430.wpress' ) );
		$this->assertSame( BackupKind::SAFETY_RESET, self::kind( 'pre-reset-20261015-134430.wpress' ) );
	}

	public function test_anything_in_the_other_tools_folder_is_other(): void {
		$this->assertSame( BackupKind::OTHER, self::kind( 'site-test-20261016-030012-418-abcd1234.wpress', Backup::EXTERNAL ) );
		$this->assertSame( BackupKind::OTHER, self::kind( 'pre-import-20261015-134430.wpress', Backup::EXTERNAL ) );
	}

	public function test_the_kinds_are_the_payload_strings(): void {
		$this->assertSame( [ 'scheduled', 'manual', 'safetyRestore', 'safetyReset', 'other' ], [ BackupKind::SCHEDULED, BackupKind::MANUAL, BackupKind::SAFETY_RESTORE, BackupKind::SAFETY_RESET, BackupKind::OTHER ] );
	}
}

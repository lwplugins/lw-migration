<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage;

use Brain\Monkey\Filters;
use LightweightPlugins\Migration\Storage\BackupFileMode;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class BackupFileModeTest extends MonkeyTestCase {

	public function test_the_default_is_owner_only(): void {
		$this->assertSame( 0600, BackupFileMode::get() );
	}

	/**
	 * @dataProvider provide_requests
	 */
	public function test_the_filter_value_is_clamped( int $requested, int $expected ): void {
		Filters\expectApplied( 'lw_migration_backup_file_mode' )->once()->with( 0600 )->andReturn( $requested );

		$this->assertSame( $expected, BackupFileMode::get() );
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function provide_requests(): array {
		return [
			'group read'         => [ 0640, 0640 ],
			'world readable'     => [ 0644, 0640 ],
			'everything'         => [ 0777, 0640 ],
			'group write'        => [ 0660, 0640 ],
			'nothing'            => [ 0, 0600 ],
			'read-only for self' => [ 0400, 0600 ],
		];
	}
}

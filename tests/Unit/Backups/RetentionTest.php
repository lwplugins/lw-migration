<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Retention;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase {

	/** @return array<int, Backup> */
	private static function backups(): array {
		return [
			new Backup( 'b1-19700101-001640-001.wpress', '/x/b1', 100, 1000, Backup::OWN ),
			new Backup( 'b2-19700101-003320-001.wpress', '/x/b2', 100, 2000, Backup::OWN ),
			new Backup( 'pre-import-20260101-000000.wpress', '/x/p', 999, 2500, Backup::OWN ),
			new Backup( 'b3-19700101-005000-001.wpress', '/x/b3', 100, 3000, Backup::OWN ),
		];
	}

	public function test_an_empty_file_neither_counts_nor_goes(): void {
		$backups = [
			new Backup( 'b1-19700101-001640-001.wpress', '/x/b1', 100, 1000, Backup::OWN ),
			new Backup( 'b2-19700101-003320-001.wpress', '/x/b2', 0, 2000, Backup::OWN ),
			new Backup( 'b3-19700101-005000-001.wpress', '/x/b3', 100, 3000, Backup::OWN ),
		];

		$this->assertSame( [], ( new Retention( 2 ) )->prune( $backups, 3000 ) );
	}

	public function test_unlimited_keeps_everything(): void {
		$this->assertSame( [], ( new Retention() )->prune( self::backups(), 5000 ) );
	}

	public function test_count_size_and_age(): void {
		$this->assertSame( [ 'b1-19700101-001640-001.wpress' ], ( new Retention( 2 ) )->prune( self::backups(), 5000 ) );
		$this->assertSame( [ 'b2-19700101-003320-001.wpress', 'b1-19700101-001640-001.wpress' ], ( new Retention( 0, 150 ) )->prune( self::backups(), 5000 ) );
		$this->assertSame( [ 'b1-19700101-001640-001.wpress' ], ( new Retention( 0, 0, 1 ) )->prune( self::backups(), 1000 + 86400 + 1 ) );
	}

	public function test_the_newest_always_stays(): void {
		$this->assertSame( [ 'b2-19700101-003320-001.wpress', 'b1-19700101-001640-001.wpress' ], ( new Retention( 0, 1, 1 ) )->prune( self::backups(), 10000000 ) );
	}

	public function test_a_single_old_oversized_backup_is_kept(): void {
		$only = [ new Backup( 'only-19700101-000001-001.wpress', '/x/only', 5000, 1, Backup::OWN ) ];

		$this->assertSame( [], ( new Retention( 1, 1, 1 ) )->prune( $only, 10000000 ) );
	}

	public function test_safety_backups_are_never_pruned(): void {
		$backups = [
			new Backup( 'pre-import-20200101-000000.wpress', '/x/p1', 9999, 1, Backup::OWN ),
			new Backup( 'pre-import-20200102-000000.wpress', '/x/p2', 9999, 2, Backup::OWN ),
			new Backup( 'b1-19700101-001640-001.wpress', '/x/b1', 100, 1000, Backup::OWN ),
		];

		$this->assertSame( [], ( new Retention( 1, 1, 1 ) )->prune( $backups, 10000000 ) );
		$this->assertSame( [], ( new Retention( 1, 1, 1 ) )->prune( array_slice( $backups, 0, 2 ), 10000000 ) );
	}

	public function test_a_pre_reset_backup_is_never_pruned(): void {
		$backups = [
			new Backup( 'pre-reset-20200101-000000.wpress', '/x/r1', 9999, 1, Backup::OWN ),
			new Backup( 'b1-19700101-001640-001.wpress', '/x/b1', 100, 1000, Backup::OWN ),
		];

		$this->assertSame( [], ( new Retention( 1, 1, 1 ) )->prune( $backups, 10000000 ) );
		$this->assertSame( [], ( new Retention( 1 ) )->prune( $backups, 10000000 ) );
		// The name carries no site time stamp, so it is outside retention twice over: by its time and by isSafety().
		$this->assertNull( BackupName::timeOf( 'pre-reset-20200101-000000.wpress' ) );
	}

	public function test_only_our_own_backups_are_pruned_from_a_mixed_list(): void {
		$mixed = [
			new Backup( 'ext-old.wpress', '/ext/ext-old', 100, 10, Backup::EXTERNAL ),
			new Backup( 'pre-import-20200101-000000.wpress', '/own/p', 100, 20, Backup::OWN ),
			new Backup( 'own-old-19700101-000030-001.wpress', '/own/own-old', 100, 30, Backup::OWN ),
			new Backup( 'ext-new.wpress', '/ext/ext-new', 100, 9000, Backup::EXTERNAL ),
			new Backup( 'own-new-19700101-021320-001.wpress', '/own/own-new', 100, 8000, Backup::OWN ),
		];

		$this->assertSame( [ 'own-old-19700101-000030-001.wpress' ], ( new Retention( 1, 1, 1 ) )->prune( $mixed, 10000000 ) );
	}

	public function test_a_site_named_pre_import_is_prunable(): void {
		$backups = [
			new Backup( 'site-pre-import-example-com-20200101-000000-001.wpress', '/own/a', 100, 1000, Backup::OWN ),
			new Backup( 'site-pre-import-20200102-000000-001.wpress', '/own/b', 100, 2000, Backup::OWN ),
		];

		$this->assertSame( [ 'site-pre-import-example-com-20200101-000000-001.wpress' ], ( new Retention( 1 ) )->prune( $backups, gmmktime( 0, 0, 0, 1, 1, 2026 ) ) );
	}

	public function test_size_limit_is_contiguous(): void {
		$backups = [
			new Backup( 'new-19700101-005000-001.wpress', '/own/n', 100, 3000, Backup::OWN ),
			new Backup( 'mid-19700101-003320-001.wpress', '/own/m', 200, 2000, Backup::OWN ),
			new Backup( 'old-19700101-001640-001.wpress', '/own/o', 40, 1000, Backup::OWN ),
		];

		$this->assertSame( [ 'mid-19700101-003320-001.wpress', 'old-19700101-001640-001.wpress' ], ( new Retention( 0, 150 ) )->prune( $backups, 5000 ) );
	}

	public function test_a_future_mtime_cannot_pin_the_newest_slot(): void {
		$now     = gmmktime( 0, 0, 0, 1, 1, 2026 );
		$backups = [
			new Backup( 'site-20200101-000000-001.wpress', '/own/old', 100, $now + 999999, Backup::OWN ),
			new Backup( 'imported.wpress', '/own/odd', 100, $now + 999999, Backup::OWN ),
			new Backup( 'site-20251231-000000-001.wpress', '/own/new', 100, $now - 100, Backup::OWN ),
		];

		$this->assertSame( [ 'site-20200101-000000-001.wpress' ], ( new Retention( 1 ) )->prune( $backups, $now ), 'Only the name time counts; a name without one is never pruned.' );
	}

	public function test_a_touched_old_backup_is_still_old(): void {
		$now     = gmmktime( 0, 0, 0, 1, 1, 2026 );
		$backups = [
			new Backup( 'site-20200101-000000-001.wpress', '/own/old', 100, $now - 10, Backup::OWN ),
			new Backup( 'site-20251231-000000-001.wpress', '/own/new', 100, $now - 86400, Backup::OWN ),
		];

		$this->assertSame( [ 'site-20200101-000000-001.wpress' ], ( new Retention( 1 ) )->prune( $backups, $now ) );
	}

	public function test_the_just_written_backup_is_always_kept(): void {
		$now     = gmmktime( 0, 0, 0, 1, 1, 2026 );
		$backups = [
			new Backup( 'site-20251231-000000-001.wpress', '/own/a', 100, $now - 50, Backup::OWN ),
			new Backup( 'site-20300101-000000-001.wpress', '/own/future', 100, $now - 10, Backup::OWN ),
			new Backup( 'site-20251230-000000-001.wpress', '/own/b', 500, $now - 100, Backup::OWN ),
		];

		$this->assertSame(
			[ 'site-20300101-000000-001.wpress', 'site-20251231-000000-001.wpress' ],
			( new Retention( 1, 1, 1 ) )->prune( $backups, $now, 'site-20251230-000000-001.wpress' )
		);
	}

	public function test_a_name_time_in_the_future_stays_newest_after_the_clock_went_back(): void {
		$now     = gmmktime( 0, 0, 0, 1, 1, 2026 );
		$backups = [
			new Backup( 'site-20260102-000000-001.wpress', '/own/ahead', 100, $now - 10, Backup::OWN ),
			new Backup( 'site-20251231-000000-001.wpress', '/own/older', 100, $now - 5, Backup::OWN ),
		];

		$this->assertSame( [ 'site-20251231-000000-001.wpress' ], ( new Retention( 1 ) )->prune( $backups, $now ) );
	}

	public function test_a_manually_copied_archive_is_never_pruned(): void {
		$now     = gmmktime( 0, 0, 0, 1, 1, 2026 );
		$backups = [
			new Backup( 'site-20251231-000000-001.wpress', '/own/new', 100, $now - 10, Backup::OWN ),
			new Backup( 'copied-in.wpress', '/own/copied', 999999, 1, Backup::OWN ),
			new Backup( 'site-20200101-000000.wpress', '/own/almost', 999999, 1, Backup::OWN ),
			new Backup( 'site-20200101-000000-001.wpress', '/own/old', 100, 1, Backup::OWN ),
		];

		$this->assertSame( [ 'site-20200101-000000-001.wpress' ], ( new Retention( 1, 1, 1 ) )->prune( $backups, $now ) );
	}

	/**
	 * @param array<int, string> $names Backup names.
	 * @return array<int, Backup>
	 */
	private static function own( array $names ): array {
		return array_map( static fn( string $name ): Backup => new Backup( $name, '/b/' . $name, 10, 0, Backup::OWN ), $names );
	}

	public function test_a_schedule_prunes_only_its_own_backups(): void {
		$t     = 1767225600;
		$mine  = [
			BackupName::forSchedule( 'https://s.test', $t, 1, 'aaaaaaaa' ),
			BackupName::forSchedule( 'https://s.test', $t + 60, 1, 'aaaaaaaa' ),
			BackupName::forSchedule( 'https://s.test', $t + 120, 1, 'aaaaaaaa' ),
		];
		$other = [
			BackupName::forSchedule( 'https://s.test', $t - 600, 1, 'bbbbbbbb' ),
			BackupName::forSite( 'https://s.test', $t - 600, 1 ),
			'pre-import-20250101-000000.wpress',
		];

		$this->assertSame( [ $mine[0] ], ( new Retention( 2 ) )->prune( self::own( array_merge( $mine, $other ) ), $t + 200, $mine[2], 'aaaaaaaa' ) );
	}

	public function test_the_global_rules_never_touch_scheduled_backups(): void {
		$t        = 1767225600;
		$manual   = [ BackupName::forSite( 'https://s.test', $t, 1 ), BackupName::forSite( 'https://s.test', $t + 60, 1 ) ];
		$schedule = [ BackupName::forSchedule( 'https://s.test', $t - 600, 1, 'aaaaaaaa' ), BackupName::forSchedule( 'https://s.test', $t - 900, 1, 'aaaaaaaa' ) ];

		$this->assertSame( [ $manual[0] ], ( new Retention( 1 ) )->prune( self::own( array_merge( $manual, $schedule ) ), $t + 200 ) );
	}

	public function test_scheduled_backups_beside_them_change_nothing_for_manual_ones(): void {
		$t         = 1767225600;
		$manual    = array_merge( self::backups(), self::own( [ BackupName::forSite( 'https://s.test', $t, 1 ) ] ) );
		$scheduled = self::own(
			[
				BackupName::forSchedule( 'https://s.test', $t + 9000, 1, 'aaaaaaaa' ),
				BackupName::forSchedule( 'https://s.test', 10, 1, 'bbbbbbbb' ),
			]
		);
		foreach ( [ new Retention( 1 ), new Retention( 2 ), new Retention( 0, 150 ), new Retention( 0, 0, 1 ), new Retention( 1, 1, 1 ) ] as $rules ) {
			$this->assertSame( $rules->prune( $manual, $t + 100 ), $rules->prune( array_merge( $scheduled, $manual ), $t + 100 ) );
		}
	}

	public function test_a_schedule_keeps_its_newest_and_never_a_safety_backup(): void {
		$t       = 1767225600;
		$newest  = BackupName::forSchedule( 'https://s.test', $t, 1, 'aaaaaaaa' );
		$older   = BackupName::forSchedule( 'https://s.test', $t - 86400 * 10, 1, 'aaaaaaaa' );
		$backups = self::own( [ $older, $newest, 'pre-import-20200101-000000.wpress' ] );

		$this->assertSame( [ $older ], ( new Retention( 1, 1, 1 ) )->prune( $backups, $t + 86400 * 100, null, 'aaaaaaaa' ), 'Without $keep the newest stays.' );
		$this->assertSame( [], ( new Retention( 1, 1, 1 ) )->prune( self::own( [ 'pre-import-20200101-000000.wpress' ] ), $t, null, 'aaaaaaaa' ) );
	}

	public function test_from_option(): void {
		$r = Retention::fromOption( [ 'count' => '3', 'bytes' => 10, 'days' => -5 ] );
		$this->assertSame( [ 3, 10, 0 ], [ $r->maxCount, $r->maxBytes, $r->maxDays ] );
		$this->assertSame( 0, Retention::fromOption( 'junk' )->maxCount );
	}

	public function test_to_array_names_the_three_limits(): void {
		$this->assertSame( [ 'count' => 3, 'bytes' => 10, 'days' => 0 ], ( new Retention( 3, 10, -5 ) )->toArray() );
	}
}

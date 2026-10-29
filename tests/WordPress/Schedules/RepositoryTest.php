<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Schedules\ScheduleException;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class RepositoryTest extends MonkeyTestCase {

	private Repository $repository;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		$this->repository = new Repository( static fn(): int => 1000 );
	}

	private static function schedule( string $id, string $title = 'S' ): Schedule {
		return Schedule::fromArray(
			[
				'id'    => $id,
				'title' => $title,
			]
		);
	}

	public function test_the_first_read_creates_one_disabled_daily_backup(): void {
		$all = $this->repository->all();

		$this->assertCount( 1, $all );
		$this->assertFalse( $all[0]->enabled );
		$this->assertSame( 'Daily backup', $all[0]->title );
		$this->assertSame( [ Schedule::DAILY, 3, 0 ], [ $all[0]->frequency, $all[0]->hour, $all[0]->minute ] );
		$this->assertSame( [ 'count' => 7, 'bytes' => 0, 'days' => 0 ], $all[0]->retention );
		$this->assertSame( Schedule::NOTIFY_FAILURE, $all[0]->notifyWhen );
		$this->assertSame( [ 1000, 1000, 1000 ], [ $all[0]->anchor, $all[0]->createdAt, $all[0]->updatedAt ] );
		$this->assertSame( $all[0]->id, $this->repository->all()[0]->id, 'Created once.' );
	}

	public function test_the_default_title_is_stored_untranslated_and_shown_in_the_readers_language(): void {
		Functions\when( '__' )->alias( static fn( string $text ): string => 'Daily backup' === $text ? 'Napi mentés' : $text );
		$default = $this->repository->all()[0];

		$this->assertSame( Repository::DEFAULT_TITLE, $default->title, 'Stored as the source text, not in the language of the first reader.' );
		$this->assertSame( 'Napi mentés', Repository::shownTitle( $default ) );

		$default->title = 'Napi mentés';
		$this->assertSame( 'Napi mentés', Repository::shownTitle( $default ), 'Once saved by a user, the title is theirs.' );
		$this->assertSame( 'Nightly', Repository::shownTitle( self::schedule( 'bbbbbbbb', 'Nightly' ) ) );
	}

	public function test_find_and_existing_never_create_the_default(): void {
		$this->assertNull( $this->repository->find( 'aaaaaaaa' ) );
		$this->assertSame( [], $this->repository->existing() );
		$this->assertArrayNotHasKey( Options::SCHEDULES, FakeOptions::$values, 'Cron and finish paths read without writing (R7).' );

		$created = $this->repository->all()[0];
		$this->assertSame( $created->id, $this->repository->find( $created->id )?->id );
		$this->assertSame( [ $created->id ], array_map( static fn( Schedule $s ): string => $s->id, $this->repository->existing() ) );
	}

	public function test_two_first_reads_at_once_agree_on_the_stored_default(): void {
		$other = Repository::defaultSchedule( 900 )->toArray();
		Functions\when( 'add_option' )->alias(
			static function ( $name ) use ( $other ): bool {
				FakeOptions::$values[ $name ] = [ $other ]; // Core's add_option() upserts: the other request's row is what stays.
				return true;
			}
		);

		$all = $this->repository->all();

		$this->assertSame( [ $other['id'] ], array_map( static fn( Schedule $s ): string => $s->id, $all ) );
	}

	public function test_save_refuses_a_password_that_is_not_sealed(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		FakeOptions::install( [ Options::SCHEDULES => [] ] );
		$schedule           = self::schedule( 'aaaaaaaa' );
		$schedule->password = 'very-secret-pw';

		try {
			$this->repository->save( $schedule );
			$this->fail( 'A plain password is refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( 'very-secret-pw', $e->getMessage() );
		}
		$schedule->password = 'lwm1:forged';
		try {
			$this->repository->save( $schedule );
			$this->fail( 'A value that does not open is refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( [], FakeOptions::$values[ Options::SCHEDULES ] );
		}
	}

	public function test_an_unreadable_seal_is_kept_only_when_it_is_the_stored_one(): void {
		Functions\when( 'wp_salt' )->justReturn( 'new-salt' );
		$stored = self::schedule( 'aaaaaaaa' )->toArray();
		$stored['password'] = 'lwm1:sealed-under-the-old-salt';
		FakeOptions::install( [ Options::SCHEDULES => [ $stored ] ] );

		$kept        = Schedule::fromArray( $stored );
		$kept->title = 'Renamed';
		$this->repository->save( $kept );
		$this->assertSame( [ 'Renamed', 'lwm1:sealed-under-the-old-salt' ], [ FakeOptions::$values[ Options::SCHEDULES ][0]['title'], FakeOptions::$values[ Options::SCHEDULES ][0]['password'] ], 'The unchanged seal stays.' );

		$other           = Schedule::fromArray( $stored );
		$other->password = 'lwm1:another-unreadable-seal';
		try {
			$this->repository->save( $other );
			$this->fail( 'A different seal that does not open is refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'lwm1:sealed-under-the-old-salt', FakeOptions::$values[ Options::SCHEDULES ][0]['password'] );
		}

		$fresh           = self::schedule( 'bbbbbbbb' );
		$fresh->password = 'lwm1:sealed-under-the-old-salt';
		$this->expectException( \InvalidArgumentException::class );
		$this->repository->save( $fresh ); // Only the stored row of the same schedule counts.
	}

	public function test_an_emptied_list_stays_empty(): void {
		FakeOptions::install( [ Options::SCHEDULES => [] ] );

		$this->assertSame( [], $this->repository->all() );
	}

	public function test_save_replaces_by_id_and_appends_new_ones(): void {
		FakeOptions::install( [ Options::SCHEDULES => [] ] );
		$this->repository->save( self::schedule( 'aaaaaaaa', 'One' ) );
		$this->repository->save( self::schedule( 'bbbbbbbb', 'Two' ) );
		$this->repository->save( self::schedule( 'aaaaaaaa', 'One again' ) );

		$this->assertSame( [ 'One again', 'Two' ], array_map( static fn( Schedule $s ): string => $s->title, $this->repository->all() ) );
		$this->assertSame( 'Two', $this->repository->find( 'bbbbbbbb' )?->title );
		$this->assertNull( $this->repository->find( 'cccccccc' ) );
		$this->assertIsArray( FakeOptions::$values[ Options::SCHEDULES ][0], 'Stored as arrays, never objects.' );
	}

	public function test_the_anchor_restarts_on_a_new_schedule_a_timing_change_and_switching_on(): void {
		FakeOptions::install( [ Options::SCHEDULES => [] ] );
		$clock      = 1000;
		$repository = new Repository(
			static function () use ( &$clock ): int {
				return $clock;
			}
		);
		$schedule   = Schedule::fromArray(
			[
				'id'        => 'aaaaaaaa',
				'frequency' => Schedule::HOURS,
				'every'     => 6,
			]
		);
		$repository->save( $schedule );
		$this->assertSame( 1000, $repository->find( 'aaaaaaaa' )?->anchor, 'A new schedule counts from its save.' );

		$clock        = 2000;
		$edit         = $repository->find( 'aaaaaaaa' );
		$edit->title  = 'Renamed';
		$edit->anchor = 1;
		$repository->save( $edit );
		$this->assertSame( 1000, $repository->find( 'aaaaaaaa' )?->anchor, 'An edit that keeps the timing keeps the counting.' );

		$clock         = 3000;
		$edit          = $repository->find( 'aaaaaaaa' );
		$edit->enabled = true;
		$repository->save( $edit );
		$this->assertSame( 3000, $repository->find( 'aaaaaaaa' )?->anchor, 'Switching it on counts from now.' );

		$clock       = 4000;
		$edit        = $repository->find( 'aaaaaaaa' );
		$edit->every = 12;
		$repository->save( $edit );
		$this->assertSame( 4000, $repository->find( 'aaaaaaaa' )?->anchor, 'A timing change counts from now.' );
	}

	public function test_at_most_twenty_schedules(): void {
		FakeOptions::install( [ Options::SCHEDULES => [] ] );
		for ( $i = 0; $i < Repository::MAX; $i++ ) {
			$this->repository->save( self::schedule( sprintf( '%08x', $i ) ) );
		}

		$this->expectException( ScheduleException::class );
		$this->repository->save( self::schedule( 'ffffffff' ) );
	}

	public function test_delete_removes_the_schedule_and_its_log(): void {
		FakeOptions::install( [ Options::SCHEDULES => [] ] );
		$this->repository->save( self::schedule( 'aaaaaaaa' ) );
		Log::add( 'aaaaaaaa', [ 'status' => 'done' ] );

		$this->assertTrue( $this->repository->delete( 'aaaaaaaa' ) );
		$this->assertFalse( $this->repository->delete( 'aaaaaaaa' ) );
		$this->assertSame( [], $this->repository->all() );
		$this->assertArrayNotHasKey( Options::SCHEDULE_LOG_PREFIX . 'aaaaaaaa', FakeOptions::$values );
	}

	public function test_broken_rows_are_skipped(): void {
		FakeOptions::install( [ Options::SCHEDULES => [ 'x', [ 'id' => '../x' ], [ 'id' => 'aaaaaaaa' ] ] ] );

		$this->assertSame( [ 'aaaaaaaa' ], array_map( static fn( Schedule $s ): string => $s->id, $this->repository->all() ) );
	}

	public function test_the_option_never_holds_the_plain_password(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		FakeOptions::install( [ Options::SCHEDULES => [] ] );
		$schedule           = self::schedule( 'aaaaaaaa' );
		$schedule->password = ( new SealedPassword() )->seal( 'very-secret-pw' );
		$this->repository->save( $schedule );

		$this->assertStringNotContainsString( 'very-secret-pw', serialize( FakeOptions::$values ) );
		$this->assertSame( 'very-secret-pw', ( new SealedPassword() )->open( (string) $this->repository->find( 'aaaaaaaa' )?->password ) );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LogTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
	}

	public function test_entries_are_kept_newest_first_up_to_thirty(): void {
		for ( $i = 1; $i <= 35; $i++ ) {
			Log::add( 'aaaaaaaa', [ 'job' => (string) $i, 'status' => 'done', 'endedAt' => $i ] );
		}
		$all = Log::all( 'aaaaaaaa' );

		$this->assertCount( Log::MAX, $all );
		$this->assertSame( '35', $all[0]['job'] );
		$this->assertSame( '6', $all[29]['job'] );
		$this->assertSame( $all[0], Log::last( 'aaaaaaaa' ) );
		$this->assertSame( [], Log::all( 'bbbbbbbb' ) );
		$this->assertNull( Log::last( 'bbbbbbbb' ) );
	}

	public function test_an_entry_has_exactly_the_known_fields_with_their_types(): void {
		Log::add(
			'aaaaaaaa',
			[
				'job'    => 'abc',
				'status' => 'failed',
				'size'   => '12',
				'extra'  => 'dropped',
				'error'  => [ 'not text' ],
			]
		);

		$this->assertSame(
			[
				'job'       => 'abc',
				'trigger'   => '',
				'status'    => 'failed',
				'startedAt' => 0,
				'endedAt'   => 0,
				'backup'    => '',
				'size'      => 12,
				'error'     => '',
				'warnings'  => 0,
				'mail'      => '',
			],
			Log::last( 'aaaaaaaa' )
		);
	}

	public function test_long_texts_are_clipped_and_numbers_never_negative(): void {
		Log::add(
			'aaaaaaaa',
			[
				'error'    => str_repeat( 'é', Log::TEXT_LENGTH + 50 ),
				'backup'   => str_repeat( 'b', Log::TEXT_LENGTH + 1 ),
				'mail'     => str_repeat( 'm', Log::TEXT_LENGTH + 1 ),
				'size'     => -5,
				'warnings' => '-1',
				'endedAt'  => -100,
			]
		);
		$entry = Log::last( 'aaaaaaaa' );

		$this->assertSame( str_repeat( 'é', Log::TEXT_LENGTH ), $entry['error'], 'Clipped by characters, never inside one.' );
		$this->assertSame( Log::TEXT_LENGTH, mb_strlen( (string) $entry['backup'] ) );
		$this->assertSame( Log::TEXT_LENGTH, mb_strlen( (string) $entry['mail'] ) );
		$this->assertSame( [ 0, 0, 0 ], [ $entry['size'], $entry['warnings'], $entry['endedAt'] ] );
	}

	public function test_clear_deletes_the_option(): void {
		Log::add( 'aaaaaaaa', [ 'status' => 'done' ] );
		Log::clear( 'aaaaaaaa' );

		$this->assertArrayNotHasKey( Options::SCHEDULE_LOG_PREFIX . 'aaaaaaaa', FakeOptions::$values );
	}

	public function test_only_a_schedule_id_names_a_log(): void {
		$this->expectException( \InvalidArgumentException::class );
		Log::all( '../x' );
	}

	public function test_the_failure_mail_time_lives_beside_the_entries_and_goes_with_clear(): void {
		$this->assertSame( 0, Log::failureMailAt( 'aaaaaaaa' ) );
		Log::add( 'aaaaaaaa', [ 'status' => 'failed', 'mail' => 'pending' ], 1767225600 );
		Log::add( 'aaaaaaaa', [ 'status' => 'failed', 'mail' => 'throttled' ] );

		$this->assertSame( 1767225600, Log::failureMailAt( 'aaaaaaaa' ), 'An entry without a time keeps the stored one.' );
		$this->assertSame( [ 'throttled', 'pending' ], array_column( Log::all( 'aaaaaaaa' ), 'mail' ) );
		Log::setMail( 'aaaaaaaa', 'sent' );
		$this->assertSame( [ 'sent', 'pending' ], array_column( Log::all( 'aaaaaaaa' ), 'mail' ), 'Only the newest entry changes.' );
		Log::setMail( 'aaaaaaaa', 'failed', 0 );
		$this->assertSame( 0, Log::failureMailAt( 'aaaaaaaa' ) );

		Log::clear( 'aaaaaaaa' );
		$this->assertSame( 0, Log::failureMailAt( 'aaaaaaaa' ) );
		$this->assertArrayNotHasKey( Options::SCHEDULE_LOG_PREFIX . 'aaaaaaaa', FakeOptions::$values );
	}

	public function test_set_mail_on_an_empty_log_writes_nothing(): void {
		Log::setMail( 'aaaaaaaa', 'sent' );

		$this->assertArrayNotHasKey( Options::SCHEDULE_LOG_PREFIX . 'aaaaaaaa', FakeOptions::$values );
	}

	public function test_an_old_plain_list_or_a_broken_option_reads_as_an_empty_log(): void {
		$option = Options::SCHEDULE_LOG_PREFIX . 'aaaaaaaa';
		foreach ( [ [ [ 'job' => 'old', 'status' => 'done' ] ], 'text', 7, [ 'entries' => 'x', 'failureMailAt' => '9' ] ] as $stored ) {
			FakeOptions::$values[ $option ] = $stored;

			$this->assertSame( [], Log::all( 'aaaaaaaa' ) );
			$this->assertNull( Log::last( 'aaaaaaaa' ) );
			$this->assertSame( 0, Log::failureMailAt( 'aaaaaaaa' ) );
		}

		FakeOptions::$values[ $option ] = [ [ 'job' => 'old', 'status' => 'done' ] ];
		Log::add( 'aaaaaaaa', [ 'job' => 'new', 'status' => 'done' ] );

		$this->assertSame( [ 'entries', 'failureMailAt' ], array_keys( FakeOptions::$values[ $option ] ), 'The next write stores the current shape.' );
		$this->assertSame( [ 'new' ], array_column( Log::all( 'aaaaaaaa' ), 'job' ) );
	}

	public function test_cloud_lines_are_kept_only_when_present_clipped_and_capped(): void {
		Log::add( '0a1b2c3d', [ 'status' => 'done' ] );
		$this->assertArrayNotHasKey( 'cloud', Log::last( '0a1b2c3d' ) );

		$rows = array_fill( 0, 30, [ 'name' => str_repeat( 'n', 300 ), 'status' => 'failed', 'error' => str_repeat( 'e', 900 ), 'extra' => 'x' ] );
		Log::add( '0a1b2c3d', [ 'status' => 'partial', 'cloud' => $rows ] );

		$cloud = Log::last( '0a1b2c3d' )['cloud'];
		$this->assertCount( 20, $cloud );
		$this->assertSame( [ 'name', 'status', 'error' ], array_keys( $cloud[0] ) );
		$this->assertSame( 100, mb_strlen( $cloud[0]['name'] ) );
		$this->assertSame( Log::TEXT_LENGTH, mb_strlen( $cloud[0]['error'] ) );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\ResumeCheck;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use PHPUnit\Framework\TestCase;

final class ResumeCheckTest extends TestCase {

	/**
	 * @param int $count  The Runner's start count from this cursor.
	 * @param int $deaths Deaths already counted in the cursor.
	 */
	private static function job( int $count, int $deaths = 0 ): Job {
		$job          = new Job( '0123456789abcdef', 'export', [] );
		$job->attempt = [
			'print' => 'p',
			'count' => $count,
		];
		if ( $deaths > 0 ) {
			$job->cursor['deaths'] = $deaths;
		}

		return $job;
	}

	/**
	 * @dataProvider provide_starts
	 *
	 * @param int    $count    Start count.
	 * @param int    $deaths   Deaths before.
	 * @param string $decision Expected decision.
	 * @param int    $after    Deaths after.
	 */
	public function test_a_repeated_start_counts_a_death_and_the_second_death_cuts_off( int $count, int $deaths, string $decision, int $after ): void {
		$job = self::job( $count, $deaths );

		$this->assertSame( $decision, ResumeCheck::start( $job ) );
		$this->assertSame( $after, (int) ( $job->cursor['deaths'] ?? 0 ) );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: string, 3: int}>
	 */
	public static function provide_starts(): array {
		return [
			'outside the Runner'                    => [ 0, 0, ResumeCheck::GO, 0 ],
			'a first start'                         => [ 1, 0, ResumeCheck::GO, 0 ],
			'the call before died'                  => [ 2, 0, ResumeCheck::GO, 1 ],
			'a first start after one death'         => [ 1, 1, ResumeCheck::GO, 1 ],
			'the second death on this part'         => [ 2, 1, ResumeCheck::CUT_ABORT, 2 ],
			'two deaths on one cursor (D27)'        => [ ResumeCheck::CUT_OFF_STARTS, 0, ResumeCheck::CUT_ABORT, 0 ],
			'the cut-off call itself died'          => [ ResumeCheck::CUT_OFF_STARTS, 1, ResumeCheck::CUT, 1 ],
			'the cut-off seen again'                => [ ResumeCheck::CUT_OFF_STARTS + 1, 0, ResumeCheck::CUT, 0 ],
		];
	}

	public function test_the_destination_is_asked_after_a_death_or_a_passing_failure_and_a_part_clears_both(): void {
		$died    = self::job( 2 );
		$passing = self::job( 1 );
		ResumeCheck::passing( $passing );

		$this->assertFalse( ResumeCheck::askFirst( self::job( 1, 1 ) ), 'One question per death: the next start sends.' );
		$this->assertTrue( ResumeCheck::askFirst( $died ) );
		$this->assertTrue( ResumeCheck::askFirst( $passing ) );

		ResumeCheck::asked( $passing );
		$this->assertFalse( ResumeCheck::askFirst( $passing ) );

		ResumeCheck::passing( $died );
		$died->cursor['deaths'] = 1;
		ResumeCheck::progressed( $died );
		$this->assertArrayNotHasKey( 'deaths', $died->cursor );
		$this->assertArrayNotHasKey( 'verify', $died->cursor );
	}

	public function test_an_answer_fits_only_the_same_upload_between_zero_and_what_was_sent(): void {
		$sent = new UploadSession( 'gdrive', 'a.wpress', 23, 10, 5, 'https://up.example/s1', [ 'x' => 1 ] );

		$this->assertTrue( ResumeCheck::fits( $sent, $sent->with( 15, [] ) ), 'The part that was in flight arrived.' );
		$this->assertTrue( ResumeCheck::fits( $sent, $sent->with( 10, [] ) ) );
		$this->assertTrue( ResumeCheck::fits( $sent, $sent->with( 0, [] ) ), 'Structurally this upload (lost() decides on the offset).' );
		$this->assertFalse( ResumeCheck::fits( $sent, $sent->with( 16, [] ) ), 'More than was ever sent.' );
		$this->assertFalse( ResumeCheck::fits( $sent, $sent->with( -1, [] ) ) );
		$this->assertFalse( ResumeCheck::fits( $sent, new UploadSession( 'gdrive', 'a.wpress', 23, 10, 5, 'https://up.example/other' ) ) );
		$this->assertFalse( ResumeCheck::fits( $sent, new UploadSession( 'gdrive', 'b.wpress', 23, 10, 5, 'https://up.example/s1' ) ) );
		$this->assertFalse( ResumeCheck::fits( $sent, new UploadSession( 'gdrive', 'a.wpress', 24, 10, 5, 'https://up.example/s1' ) ) );
		$this->assertFalse( ResumeCheck::fits( $sent, new UploadSession( 'onedrive', 'a.wpress', 23, 10, 5, 'https://up.example/s1' ) ) );
		$this->assertFalse( ResumeCheck::fits( $sent, new UploadSession( 'gdrive', 'a.wpress', 23, 10, 7, 'https://up.example/s1' ) ), 'Another part size.' );
	}

	/**
	 * Task 6 m2: an answer below what the destination had already confirmed
	 * means it lost part of the upload; sending again from there could go
	 * round in circles, so the upload starts over (SESSION_GONE).
	 */
	public function test_an_answer_below_the_confirmed_offset_is_a_lost_upload(): void {
		$sent = new UploadSession( 'gdrive', 'a.wpress', 23, 10, 5, 'https://up.example/s1' );

		$this->assertTrue( ResumeCheck::lost( $sent, $sent->with( 9, [] ) ) );
		$this->assertTrue( ResumeCheck::lost( $sent, $sent->with( 0, [] ) ) );
		$this->assertFalse( ResumeCheck::lost( $sent, $sent->with( 10, [] ) ) );
		$this->assertFalse( ResumeCheck::lost( $sent, $sent->with( 15, [] ) ) );
	}

	public function test_the_last_part_bounds_the_answer_by_the_size(): void {
		$last = new UploadSession( 'gdrive', 'a.wpress', 23, 20, 5, 's' );

		$this->assertTrue( ResumeCheck::fits( $last, $last->with( 23, [] ) ) );
		$this->assertFalse( ResumeCheck::fits( $last, $last->with( 25, [] ) ) );
	}
}

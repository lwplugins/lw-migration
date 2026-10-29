<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpPartWriter;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\FakeFtp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FtpPartWriterTest extends MonkeyTestCase {

	private const PART = 'a.wpress.part';

	private const WHERE = 'ftps://ftp.example.com:21';

	public function test_a_server_announcing_rest_gets_rest_and_others_appe(): void {
		$ftp = new FakeFtp();
		$this->assertSame( FtpPartWriter::REST, FtpPartWriter::mode( $ftp ) );
		$ftp->rest = false;
		$this->assertSame( FtpPartWriter::APPEND, FtpPartWriter::mode( $ftp ) );
	}

	public function test_rest_mode_writes_each_part_at_its_offset_and_a_part_sent_again_changes_nothing(): void {
		$ftp = new FakeFtp();

		FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 0, 'abcde', self::WHERE );
		FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 5, 'fghij', self::WHERE );
		FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 5, 'fghij', self::WHERE ); // The request died before the job was saved.

		$this->assertSame( 'abcdefghij', $ftp->files[ self::PART ] );
		$this->assertContains( 'REST 5 + STOR ' . self::PART . ' 5', $ftp->calls );
	}

	/**
	 * Review Focus 2.
	 */
	public function test_without_rest_a_part_is_appended_after_a_size_check(): void {
		$ftp       = new FakeFtp();
		$ftp->rest = false;

		FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 0, 'abcde', self::WHERE );
		FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );

		$this->assertSame( 'abcdefghij', $ftp->files[ self::PART ] );
		$this->assertSame( [ 'STOR ' . self::PART . ' 5', 'SIZE ' . self::PART, 'SIZE ' . self::PART, 'APPE ' . self::PART . ' 5', 'SIZE ' . self::PART ], $ftp->calls );
	}

	/**
	 * Review Focus 2: half of the part arrived before the request died.
	 */
	public function test_append_sends_only_the_missing_tail_of_a_half_sent_part(): void {
		$ftp                       = new FakeFtp();
		$ftp->files[ self::PART ] = 'abcdefg';

		FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );

		$this->assertSame( 'abcdefghij', $ftp->files[ self::PART ] );
		$this->assertContains( 'APPE ' . self::PART . ' 3', $ftp->calls );
	}

	public function test_append_skips_a_part_that_already_arrived(): void {
		$ftp                       = new FakeFtp();
		$ftp->files[ self::PART ] = 'abcdefghij';

		FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );

		$this->assertSame( [], array_values( array_filter( $ftp->calls, static fn( string $c ): bool => str_starts_with( $c, 'APPE' ) ) ) );
	}

	public function test_append_with_a_remote_size_outside_the_part_starts_over(): void {
		$ftp                       = new FakeFtp();
		$ftp->files[ self::PART ] = 'abc';

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );
			$this->fail( 'Bytes are missing before the part.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
	}

	public function test_a_refused_rest_store_falls_back_to_append(): void {
		$ftp                       = new FakeFtp();
		$ftp->restStore            = false; // Announces REST, refuses STOR after it (some servers' default).
		$ftp->files[ self::PART ] = 'abcde';

		FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 5, 'fghij', self::WHERE );

		$this->assertSame( 'abcdefghij', $ftp->files[ self::PART ] );
	}

	/**
	 * Final review I1: a refused REST+STOR and APPE may be a passing 4xx
	 * (426/450/451) or a full disk (452) — ext-ftp's false tells none of
	 * them apart, so it is a passing failure the retry budget ends.
	 */
	public function test_a_rest_server_refusing_store_and_appe_is_a_passing_failure(): void {
		$ftp                      = new FakeFtp();
		$ftp->restStore           = false;
		$ftp->appe                = false;
		$ftp->files[ self::PART ] = 'abcde';

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 5, 'fghij', self::WHERE );
			$this->fail( 'Neither REST+STOR nor APPE was accepted.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::SERVER, $e->reason );
			$this->assertSame( self::WHERE . ' did not accept the part (it may be full).', $e->getMessage() );
		}
		$this->assertSame( 'abcde', $ftp->files[ self::PART ], 'Nothing was damaged.' );
	}

	public function test_an_appe_refused_once_is_passing_and_the_retry_appends_the_part(): void {
		$ftp                      = new FakeFtp();
		$ftp->rest                = false;
		$ftp->appe                = false;
		$ftp->files[ self::PART ] = 'abcde';

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );
			$this->fail( 'APPE was refused.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::SERVER, $e->reason );
		}
		$ftp->appe = true;
		FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );

		$this->assertSame( 'abcdefghij', $ftp->files[ self::PART ] );
	}

	public function test_a_server_without_size_cannot_continue_by_appending(): void {
		$ftp        = new FakeFtp();
		$ftp->sizes = false;

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );
			$this->fail( 'No SIZE.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'SIZE', $e->getMessage() );
		}
	}

	public function test_a_server_that_ignored_the_offset_starts_the_upload_over(): void {
		$ftp                       = new FakeFtp();
		$ftp->ignoresOffset        = true;
		$ftp->files[ self::PART ] = 'abcde';

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 5, 'fghij', self::WHERE );
			$this->fail( 'The earlier part was truncated away.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
	}

	public function test_a_refused_first_part_is_a_permission_problem(): void {
		$refusing          = new FakeFtp();
		$refusing->refuses = [ 'store' ];

		try {
			FtpPartWriter::write( $refusing, FtpPartWriter::REST, self::PART, 0, 'abc', self::WHERE );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::DENIED, $e->reason );
		}
	}

	/**
	 * Task 6 carry: APPE sends only what lies beyond SIZE, so a part file
	 * longer than the part's end is not ours to continue.
	 */
	public function test_append_with_a_remote_file_past_the_part_end_starts_over_without_appending(): void {
		$ftp                      = new FakeFtp();
		$ftp->files[ self::PART ] = 'abcdefghijk';

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::APPEND, self::PART, 5, 'fghij', self::WHERE );
			$this->fail( 'More bytes than the part reaches.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
		$this->assertSame( 'abcdefghijk', $ftp->files[ self::PART ] );
	}

	/**
	 * Without SIZE no write can be checked and no upload resumed: the first
	 * part already says why, instead of the upload starting over each time.
	 */
	public function test_a_server_without_size_is_refused_at_the_first_part_naming_size(): void {
		$ftp        = new FakeFtp();
		$ftp->sizes = false;

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 0, 'abcde', self::WHERE );
			$this->fail( 'No SIZE.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'SIZE', $e->getMessage() );
			$this->assertStringNotContainsString( self::PART, $e->getMessage(), 'No file name or path in messages.' );
		}
	}

	public function test_a_size_other_than_the_parts_end_after_a_write_starts_over(): void {
		$ftp                      = new FakeFtp();
		$ftp->files[ self::PART ] = 'abcdefghijklmno'; // A REST+STOR that overwrote in the middle of a longer file.

		try {
			FtpPartWriter::write( $ftp, FtpPartWriter::REST, self::PART, 5, 'fghij', self::WHERE );
			$this->fail( 'The file does not end where the part does.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
	}
}

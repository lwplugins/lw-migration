<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\JobLog;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class JobLogTest extends TestCase {

	public function test_appends_single_lines_with_utc_time(): void {
		$dir = TempDir::create();
		JobLog::append( $dir, 0, "first\nline" );
		JobLog::append( $dir, 60, 'second' );
		$this->assertSame( "1970-01-01T00:00:00Z first line\n1970-01-01T00:01:00Z second\n", file_get_contents( $dir . '/job.log' ) );
		TempDir::remove( $dir );
	}

	public function test_control_characters_are_stored_as_escapes(): void {
		$dir = TempDir::create();
		JobLog::append( $dir, 0, "Checksum mismatch for ../\x1b]52;c;QUJD\x07a.txt\r\nnext\tline \u{9b}2K ő" );
		$this->assertSame( "1970-01-01T00:00:00Z Checksum mismatch for ../\\x1b]52;c;QUJD\\x07a.txt  next\tline \\u009b2K ő\n", file_get_contents( $dir . '/job.log' ) );
		TempDir::remove( $dir );
	}

	public function test_missing_folder_is_ignored_and_size_is_capped(): void {
		JobLog::append( '/nonexistent/lwm', 0, 'x' );
		$dir = TempDir::create();
		file_put_contents( $dir . '/job.log', str_repeat( 'x', 1048576 ) );
		JobLog::append( $dir, 0, 'more' );
		$this->assertSame( 1048576, filesize( $dir . '/job.log' ) );
		TempDir::remove( $dir );
	}

	public function test_failure_line_is_written_past_the_cap(): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/job.log', str_repeat( 'x', 1048576 ) );
		JobLog::append( $dir, 0, 'FAILED in step s', true );
		$this->assertStringEndsWith( "1970-01-01T00:00:00Z FAILED in step s\n", (string) file_get_contents( $dir . '/job.log' ) );
		TempDir::remove( $dir );
	}
}

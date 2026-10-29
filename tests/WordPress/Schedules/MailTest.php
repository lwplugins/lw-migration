<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Schedules\Mail;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class MailTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_bloginfo' )->justReturn( 'Pék &amp; Társa' );
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => html_entity_decode( $text, ENT_QUOTES ) );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'admin_url' )->alias( static fn( string $path ): string => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'size_format' )->justReturn( '5 MB' );
	}

	/**
	 * @param array<string, int|string> $fields Entry fields.
	 * @return array<string, int|string>
	 */
	private static function entry( array $fields ): array {
		return array_merge(
			[
				'job'       => 'abc',
				'trigger'   => 'cron',
				'status'    => 'done',
				'startedAt' => 1767225600,
				'endedAt'   => 1767225600 + 252,
				'backup'    => 'site-test-20260101-000000-001-0a1b2c3d.wpress',
				'size'      => 5242880,
				'error'     => '',
				'warnings'  => 0,
				'mail'      => '',
			],
			$fields
		);
	}

	public function test_a_ready_backup_names_the_file_size_and_duration(): void {
		$mail = Mail::compose( self::entry( [] ), 'Nightly' );

		$this->assertSame( '[Pék & Társa] Scheduled backup "Nightly" is ready', $mail['subject'] );
		$this->assertStringContainsString( 'Site: https://site.test/', $mail['body'] );
		$this->assertStringContainsString( 'Started: 2026-01-01 00:00', $mail['body'] );
		$this->assertStringContainsString( 'Duration: 4 min 12 s', $mail['body'] );
		$this->assertStringContainsString( 'Backup: site-test-20260101-000000-001-0a1b2c3d.wpress (5 MB)', $mail['body'] );
		$this->assertStringContainsString( 'https://site.test/wp-admin/admin.php?page=lw-migration#schedules', $mail['body'] );
		$this->assertStringNotContainsString( 'Reason:', $mail['body'] );
	}

	public function test_a_failure_gives_the_reason_on_one_line(): void {
		$mail = Mail::compose(
			self::entry(
				[
					'status'   => 'failed',
					'error'    => "Disk full\r\nBcc: evil@x.test",
					'warnings' => 2,
				]
			),
			"Night\nly"
		);

		$this->assertSame( '[Pék & Társa] Scheduled backup "Night ly" failed', $mail['subject'] );
		$this->assertStringContainsString( 'Reason: Disk full Bcc: evil@x.test', $mail['body'], 'Line breaks of a message never start a new line.' );
		$this->assertStringContainsString( '2 warnings, see the run log.', $mail['body'] );
		$this->assertStringNotContainsString( 'Backup: ', $mail['body'] );
	}

	public function test_format_and_separator_characters_never_reach_a_header_or_a_line(): void {
		Functions\when( 'get_bloginfo' )->justReturn( "P\u{202E}ék\u{2028}Bcc: x@x.test" );
		$mail = Mail::compose(
			self::entry(
				[
					'status' => 'failed',
					'error'  => "Disk\u{2029}full\u{0085}now\u{200B}",
				]
			),
			"Night\u{2028}ly\u{FEFF}"
		);

		$this->assertSame( '[Pék Bcc: x@x.test] Scheduled backup "Night ly" failed', $mail['subject'] );
		$this->assertStringContainsString( 'Reason: Disk full now', $mail['body'] );
		$this->assertSame( 0, preg_match( '/[\p{Cf}\p{Zl}\p{Zp}\x{0085}\r]/u', $mail['subject'] . $mail['body'] ) );
	}

	public function test_invalid_bytes_in_a_title_do_not_empty_it(): void {
		$mail = Mail::compose( self::entry( [] ), "Night\xFFly" );

		$this->assertSame( 1, preg_match( '//u', $mail['subject'] ) );
		$this->assertStringContainsString( 'Night', $mail['subject'] );
		$this->assertStringContainsString( 'ly"', $mail['subject'] );
	}

	public function test_the_site_address_stays_on_its_line(): void {
		Functions\when( 'home_url' )->justReturn( "https://site.test/\r\nBcc: evil@x.test" );

		$this->assertStringContainsString( "Site: https://site.test/ Bcc: evil@x.test\n", Mail::compose( self::entry( [] ), 'Nightly' )['body'] );
	}

	public function test_a_partial_run_says_which_uploads_failed(): void {
		$mail = Mail::compose(
			[
				'status'    => 'partial',
				'startedAt' => 0,
				'endedAt'   => 70,
				'backup'    => 'a.wpress',
				'size'      => 5,
				'error'     => '',
				'warnings'  => 1,
				'cloud'     => [
					[ 'name' => 'Offsite', 'status' => 'ok', 'error' => '' ],
					[ 'name' => 'Second', 'status' => 'failed', 'error' => "Access\ndenied." ],
				],
			],
			'Nightly'
		);

		$this->assertStringContainsString( 'not every upload', $mail['subject'] );
		$this->assertStringContainsString( 'Backup: a.wpress', $mail['body'] );
		$this->assertStringContainsString( 'Offsite: uploaded', $mail['body'] );
		$this->assertStringContainsString( 'Second: failed (Access denied.)', $mail['body'], 'One line per destination.' );
	}

	public function test_a_destination_still_pending_when_the_run_ended_says_not_uploaded(): void {
		$mail = Mail::compose(
			[
				'status'    => 'partial',
				'startedAt' => 0,
				'endedAt'   => 70,
				'backup'    => 'a.wpress',
				'size'      => 5,
				'error'     => '',
				'warnings'  => 0,
				'cloud'     => [ [ 'name' => 'Offsite', 'status' => 'pending', 'error' => '' ] ],
			],
			'Nightly'
		);

		$this->assertStringContainsString( "Offsite: not uploaded\n", $mail['body'] );
		$this->assertStringNotContainsString( 'Offsite: failed', $mail['body'] );
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\ReauthNotice;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ReauthNoticeTest extends MonkeyTestCase {

	/** @var array<int, array{0: array<int, string>, 1: string, 2: string}> */
	private array $sent = [];

	private bool $accept = true;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install( [ 'admin_email' => 'owner@example.com' ] );
		Functions\when( 'is_email' )->alias( static fn( $a ) => false !== strpos( (string) $a, '@' ) ? $a : false );
		Functions\when( 'get_bloginfo' )->justReturn( "Example\nShop" );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
	}

	private function notice(): ReauthNotice {
		return new ReauthNotice(
			function ( array $to, string $subject, string $body ): bool {
				$this->sent[] = [ $to, $subject, $body ];
				return $this->accept;
			},
			static fn(): string => 'https://site.test/wp-admin/admin.php?page=lw-migration&tab=destinations'
		);
	}

	/**
	 * @param array<string, mixed>|null $schedule state.schedule (null: not a schedule run).
	 */
	private static function job( ?array $schedule ): Job {
		$job = new Job( 'aaaaaaaaaaaaaaaa', 'export', [ 'cloud_upload' ] );
		if ( null !== $schedule ) {
			$job->state['schedule'] = $schedule;
		}
		return $job;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function schedule( string $trigger = 'cron', string $when = 'failure', string $email = 'ops@example.com' ): array {
		return [
			'id'      => 'sch00001',
			'title'   => 'Nightly',
			'trigger' => $trigger,
			'notify'  => [
				'when'  => $when,
				'email' => $email,
			],
		];
	}

	private static function connected(): Profile {
		$profile             = new Profile( 'abcd1234', 'gdrive', 'Office Drive' );
		$profile->connection = [
			'account' => 'someone@example.com',
			'client'  => 'mark',
		];

		return $profile;
	}

	public function test_only_a_cron_run_of_a_schedule_that_mails_is_due(): void {
		$this->assertTrue( $this->notice()->due( self::job( self::schedule() ) ) );
		$this->assertTrue( $this->notice()->due( self::job( self::schedule( 'cron', 'success' ) ) ), 'Any setting but off.' );
		$this->assertFalse( $this->notice()->due( self::job( self::schedule( 'manual' ) ) ), 'A person started it and sees the result.' );
		$this->assertFalse( $this->notice()->due( self::job( self::schedule( 'cron', 'off' ) ) ) );
		$this->assertFalse( $this->notice()->due( self::job( null ) ), 'Not a schedule run.' );
		$this->assertFalse( $this->notice()->due( self::job( [ 'trigger' => 'cron', 'notify' => 'x' ] ) ) );
	}

	public function test_the_mail_goes_to_the_schedule_address_with_our_own_text_and_the_page_link(): void {
		$sent = $this->notice()->notify( self::job( self::schedule() ), self::connected() );

		$this->assertTrue( $sent );
		[ $to, $subject, $body ] = $this->sent[0];
		$this->assertSame( [ 'ops@example.com' ], $to );
		$this->assertSame( '[Example Shop] Connect the backup destination "Office Drive" again', $subject, 'One line, the site name included.' );
		$this->assertStringContainsString( 'tab=destinations', $body );
		$this->assertStringContainsString( 'Nightly', $body );
		$this->assertStringNotContainsString( 'someone@example.com', $subject . $body, 'Never the connected account.' );
		$this->assertStringNotContainsString( 'mark', $subject . $body );
	}

	public function test_without_a_schedule_address_the_default_or_admin_address_is_used(): void {
		$this->notice()->notify( self::job( self::schedule( 'cron', 'success', '' ) ), self::connected() );

		$this->assertSame( [ 'owner@example.com' ], $this->sent[0][0] );
	}

	public function test_a_mail_that_is_not_accepted_or_throws_is_a_warning_of_the_run(): void {
		$job          = self::job( self::schedule() );
		$this->accept = false;

		$this->assertFalse( $this->notice()->notify( $job, self::connected() ) );

		$throwing = new ReauthNotice(
			static function (): bool {
				throw new \RuntimeException( 'SMTP down' );
			},
			static fn(): string => 'https://site.test/'
		);
		$this->assertFalse( $throwing->notify( $job, self::connected() ) );
		$this->assertCount( 2, $job->warnings );
		$this->assertStringContainsString( 'Office Drive', $job->warnings[0] );
		$this->assertStringNotContainsString( 'SMTP', implode( ' ', $job->warnings ) );
	}

	public function test_by_default_wp_mail_sends_plain_text_linking_the_destinations_tab(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn( string $path ): string => 'https://site.test/wp-admin/' . $path );
		$mails = [];
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body, $headers ) use ( &$mails ): bool {
				$mails[] = [ $to, $body, $headers ];
				return true;
			}
		);

		$this->assertTrue( ( new ReauthNotice() )->notify( self::job( self::schedule() ), self::connected() ) );
		$this->assertSame( [ 'ops@example.com' ], $mails[0][0] );
		$this->assertStringContainsString( 'https://site.test/wp-admin/admin.php?page=lw-migration&tab=destinations', $mails[0][1] );
		$this->assertSame( [ 'Content-Type: text/plain; charset=UTF-8' ], $mails[0][2] );
	}
}

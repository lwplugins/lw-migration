<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Schedules;

use LightweightPlugins\Migration\Schedules\MailPolicy;
use LightweightPlugins\Migration\Schedules\Schedule;
use PHPUnit\Framework\TestCase;

final class MailPolicyTest extends TestCase {

	private const NOW = 1767225600;

	public function test_the_setting_decides_which_results_mail(): void {
		$cases = [
			[ 'off', 'done', MailPolicy::SKIP ],
			[ 'off', 'failed', MailPolicy::SKIP ],
			[ 'failure', 'done', MailPolicy::SKIP ],
			[ 'failure', 'failed', MailPolicy::SEND ],
			[ 'failure', 'skipped', MailPolicy::SEND ],
			[ 'success', 'done', MailPolicy::SEND ],
			[ 'success', 'failed', MailPolicy::SKIP ],
			[ 'always', 'done', MailPolicy::SEND ],
			[ 'always', 'failed', MailPolicy::SEND ],
		];
		foreach ( $cases as [ $when, $status, $expected ] ) {
			$this->assertSame( $expected, MailPolicy::decide( $when, 'cron', $status, [], self::NOW ), $when . '/' . $status );
		}
	}

	public function test_manual_and_cancelled_runs_never_mail(): void {
		$this->assertSame( MailPolicy::SKIP, MailPolicy::decide( 'always', 'manual', 'done', [], self::NOW ) );
		$this->assertSame( MailPolicy::SKIP, MailPolicy::decide( 'always', 'manual', 'failed', [], self::NOW ) );
		$this->assertSame( MailPolicy::SKIP, MailPolicy::decide( 'always', 'cron', 'cancelled', [], self::NOW ) );
	}

	public function test_one_failure_mail_a_day_while_it_keeps_failing(): void {
		$failedAndMailed = [
			'status'  => 'failed',
			'mail'    => 'sent',
			'endedAt' => self::NOW - 3600,
		];
		$failedQuiet     = [
			'status'  => 'failed',
			'mail'    => 'throttled',
			'endedAt' => self::NOW - 1800,
		];

		$this->assertSame( MailPolicy::THROTTLE, MailPolicy::decide( 'failure', 'cron', 'failed', [ $failedQuiet, $failedAndMailed ], self::NOW ) );
		$failedAndMailed['endedAt'] = self::NOW - MailPolicy::THROTTLE_SECONDS - 1;
		$this->assertSame( MailPolicy::SEND, MailPolicy::decide( 'failure', 'cron', 'failed', [ $failedQuiet, $failedAndMailed ], self::NOW ), 'A day later it mails again.' );
	}

	public function test_the_first_failure_after_a_success_always_mails(): void {
		$previous = [
			[
				'status'  => 'done',
				'mail'    => '',
				'endedAt' => self::NOW - 60,
			],
			[
				'status'  => 'failed',
				'mail'    => 'sent',
				'endedAt' => self::NOW - 120,
			],
		];

		$this->assertSame( MailPolicy::SEND, MailPolicy::decide( 'failure', 'cron', 'failed', $previous, self::NOW ) );
	}

	public function test_the_stored_failure_mail_time_throttles_however_many_entries_came_since(): void {
		$this->assertSame( MailPolicy::THROTTLE, MailPolicy::decide( 'failure', 'cron', 'failed', [], self::NOW, self::NOW - 3600 ) );
		$this->assertSame( MailPolicy::SEND, MailPolicy::decide( 'failure', 'cron', 'failed', [], self::NOW, self::NOW - MailPolicy::THROTTLE_SECONDS - 1 ) );
	}

	public function test_a_mail_still_pending_counts_as_sent(): void {
		$previous = [
			[
				'status'  => 'failed',
				'mail'    => 'pending',
				'endedAt' => self::NOW - 60,
			],
		];

		$this->assertSame( MailPolicy::THROTTLE, MailPolicy::decide( 'always', 'cron', 'skipped', $previous, self::NOW ) );
	}

	public function test_a_partial_run_mails_like_a_failure_and_also_for_success(): void {
		$this->assertSame( MailPolicy::SEND, MailPolicy::decide( Schedule::NOTIFY_FAILURE, 'cron', 'partial', [], self::NOW ) );
		$this->assertSame( MailPolicy::SEND, MailPolicy::decide( Schedule::NOTIFY_SUCCESS, 'cron', 'partial', [], self::NOW ) );
		$this->assertSame( MailPolicy::SEND, MailPolicy::decide( Schedule::NOTIFY_ALWAYS, 'cron', 'partial', [], self::NOW ) );
		$this->assertSame( MailPolicy::THROTTLE, MailPolicy::decide( Schedule::NOTIFY_FAILURE, 'cron', 'partial', [], self::NOW, self::NOW - 100 ) );
		$this->assertSame( MailPolicy::SKIP, MailPolicy::decide( Schedule::NOTIFY_FAILURE, 'manual', 'partial', [], self::NOW ) );
	}

	public function test_a_partial_run_under_always_mails_like_a_success_and_is_never_throttled(): void {
		$previous = [ [ 'status' => 'failed', 'mail' => 'sent', 'endedAt' => self::NOW - 100 ] ];

		$this->assertSame( MailPolicy::SEND, MailPolicy::decide( Schedule::NOTIFY_ALWAYS, 'cron', 'partial', $previous, self::NOW, self::NOW - 100 ) );
	}
}

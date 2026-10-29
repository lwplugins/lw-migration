<?php
/**
 * Mails the result of a scheduled run.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

defined( 'ABSPATH' ) || exit;

/**
 * MailPolicy decides, Recipients resolves, Mail writes, wp_mail sends (plain
 * text). The recorder asks first (decide), writes the entry as PENDING, then
 * sends (send) and records the result. Never retried: a failed send is
 * recorded in the log entry and its reason handed to the caller's note.
 */
final class Notifier {

	public const PENDING = 'pending';

	public const SENT = 'sent';

	public const FAILED = 'failed';

	public const THROTTLED = 'throttled';

	/**
	 * Sender: fn( array $to, string $subject, string $body ): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $send;

	/**
	 * @param \Closure|null $send Sender (default: wp_mail, plain text).
	 */
	public function __construct( ?\Closure $send = null ) {
		$this->send = $send ?? static fn( array $to, string $subject, string $body ): bool => (bool) wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	/**
	 * @param array<string, mixed>                  $snapshot      state.schedule of the run (trigger, notify).
	 * @param string                                $status        Status of the run.
	 * @param array<int, array<string, int|string>> $previous      Earlier entries, newest first.
	 * @param int                                   $failureMailAt Log::failureMailAt().
	 * @param int                                   $now           Unix time.
	 * @return string '' (no mail), PENDING (send it) or THROTTLED.
	 */
	public function decide( array $snapshot, string $status, array $previous, int $failureMailAt, int $now ): string {
		$notify = is_array( $snapshot['notify'] ?? null ) ? $snapshot['notify'] : [];

		return match ( MailPolicy::decide( (string) ( $notify['when'] ?? '' ), (string) ( $snapshot['trigger'] ?? '' ), $status, $previous, $now, $failureMailAt ) ) {
			MailPolicy::SEND => self::PENDING,
			MailPolicy::THROTTLE => self::THROTTLED,
			default => '',
		};
	}

	/**
	 * @param array<string, mixed>      $snapshot state.schedule of the run (title, notify).
	 * @param array<string, int|string> $entry    The log entry.
	 * @param \Closure                  $note     fn( string $reason ): void, told why a send failed (raw text: the caller redacts).
	 * @return string SENT or FAILED.
	 */
	public function send( array $snapshot, array $entry, \Closure $note ): string {
		$notify = is_array( $snapshot['notify'] ?? null ) ? $snapshot['notify'] : [];
		$to     = Recipients::resolve( (string) ( $notify['email'] ?? '' ) );
		if ( [] === $to ) {
			$note( 'No valid recipient address for the result mail.' );
			return self::FAILED;
		}
		$mail = Mail::compose( $entry, (string) ( $snapshot['title'] ?? '' ) );
		try {
			if ( ( $this->send )( $to, $mail['subject'], $mail['body'] ) ) {
				return self::SENT;
			}
			$note( 'The result mail was not accepted for delivery.' );
		} catch ( \Throwable $e ) {
			$note( 'The result mail could not be sent: ' . $e->getMessage() );
		}

		return self::FAILED;
	}
}

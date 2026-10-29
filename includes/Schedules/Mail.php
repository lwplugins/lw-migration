<?php
/**
 * The text of a schedule mail.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * Plain text, built only from the log entry (already redacted: no server
 * path, URL token or database error text), the schedule's title and the
 * site's public address. No password, no download link (a download needs a
 * login anyway); a link to the Schedules section instead. Every value is
 * kept on one line (the subject too, translation included), so a message can
 * never start a header or a new line, and no invisible character reorders it.
 */
final class Mail {

	private const DATE = 'Y-m-d H:i';

	/**
	 * @param array<string, mixed> $entry Log entry (Log field set).
	 * @param string               $title Schedule title.
	 * @return array{subject: string, body: string}
	 */
	public static function compose( array $entry, string $title ): array {
		$site  = self::line( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$title = self::line( $title );
		$done  = in_array( $entry['status'], [ 'done', RunRecorder::PARTIAL ], true );
		if ( RunRecorder::PARTIAL === $entry['status'] ) {
			/* translators: 1: site name, 2: schedule name */
			$subject = sprintf( __( '[%1$s] Scheduled backup "%2$s" is ready, but not every upload succeeded', 'lw-migration' ), $site, $title );
			$result  = __( 'The backup is ready, but not every upload succeeded.', 'lw-migration' );
		} elseif ( $done ) {
			/* translators: 1: site name, 2: schedule name */
			$subject = sprintf( __( '[%1$s] Scheduled backup "%2$s" is ready', 'lw-migration' ), $site, $title );
			$result  = __( 'The backup is ready.', 'lw-migration' );
		} else {
			/* translators: 1: site name, 2: schedule name */
			$subject = sprintf( __( '[%1$s] Scheduled backup "%2$s" failed', 'lw-migration' ), $site, $title );
			$result  = RunRecorder::SKIPPED === $entry['status'] ? __( 'The backup did not run.', 'lw-migration' ) : __( 'The backup failed.', 'lw-migration' );
		}

		return [
			'subject' => self::line( $subject ),
			'body'    => implode( "\n", self::lines( $entry, $title, $result, $done ) ) . "\n",
		];
	}

	/**
	 * @param array<string, mixed> $entry  Log entry.
	 * @param string               $title  Schedule title (one line).
	 * @param string               $result Result sentence.
	 * @param bool                 $done   Whether the backup is ready.
	 * @return array<int, string>
	 */
	private static function lines( array $entry, string $title, string $result, bool $done ): array {
		$warnings = (int) $entry['warnings'];
		$lines    = [
			/* translators: %s: site address */
			sprintf( __( 'Site: %s', 'lw-migration' ), self::line( home_url( '/' ) ) ),
			/* translators: %s: schedule name */
			sprintf( __( 'Schedule: %s', 'lw-migration' ), $title ),
			/* translators: %s: one sentence about the result */
			sprintf( __( 'Result: %s', 'lw-migration' ), $result ),
			/* translators: %s: date and time in the site's time zone */
			sprintf( __( 'Started: %s', 'lw-migration' ), (string) wp_date( self::DATE, (int) $entry['startedAt'] ) ),
			/* translators: %s: duration, e.g. 4 min 12 s */
			sprintf( __( 'Duration: %s', 'lw-migration' ), self::duration( (int) $entry['endedAt'] - (int) $entry['startedAt'] ) ),
		];
		if ( $done ) {
			/* translators: 1: backup file name, 2: file size */
			$lines[] = sprintf( __( 'Backup: %1$s (%2$s)', 'lw-migration' ), self::line( (string) $entry['backup'] ), (string) size_format( (int) $entry['size'], 1 ) );
		}
		foreach ( is_array( $entry['cloud'] ?? null ) ? $entry['cloud'] : [] as $row ) {
			$lines[] = self::cloudLine( $row );
		}
		if ( $warnings > 0 ) {
			/* translators: %d: number of warnings */
			$lines[] = sprintf( _n( '%d warning, see the run log.', '%d warnings, see the run log.', $warnings, 'lw-migration' ), $warnings );
		}
		if ( ! $done && '' !== (string) $entry['error'] ) {
			/* translators: %s: error message */
			$lines[] = sprintf( __( 'Reason: %s', 'lw-migration' ), self::line( (string) $entry['error'] ) );
		}
		$lines[] = '';
		/* translators: %s: address of the Schedules section in wp-admin */
		$lines[] = sprintf( __( 'Schedules and run logs: %s', 'lw-migration' ), admin_url( 'admin.php?page=lw-migration#schedules' ) );

		return $lines;
	}

	/**
	 * @param array{name: string, status: string, error: string} $row Cloud line of the log entry.
	 */
	private static function cloudLine( array $row ): string {
		$name = self::line( $row['name'] );
		if ( 'ok' === $row['status'] ) {
			/* translators: %s: destination name */
			return sprintf( __( '%s: uploaded', 'lw-migration' ), $name );
		}
		$word = self::word( $row['status'] );
		if ( '' === $row['error'] ) {
			/* translators: 1: destination name, 2: "failed", "skipped" or "not uploaded" */
			return sprintf( __( '%1$s: %2$s', 'lw-migration' ), $name, $word );
		}

		/* translators: 1: destination name, 2: "failed", "skipped" or "not uploaded", 3: reason */
		return sprintf( __( '%1$s: %2$s (%3$s)', 'lw-migration' ), $name, $word, self::line( $row['error'] ) );
	}

	/**
	 * A row neither skipped nor failed was still pending when the job ended
	 * (like CloudLines::ofLog()).
	 *
	 * @param string $status Cloud row status.
	 */
	private static function word( string $status ): string {
		if ( CloudOutcome::SKIPPED === $status ) {
			return __( 'skipped', 'lw-migration' );
		}

		return CloudOutcome::FAILED === $status ? __( 'failed', 'lw-migration' ) : __( 'not uploaded', 'lw-migration' );
	}

	/**
	 * One line of valid UTF-8: invisible format characters (bidi overrides,
	 * zero-width marks) go, control characters and line or paragraph
	 * separators become one space. Also used by the reconnect mail
	 * (Storage\Cloud\ReauthNotice).
	 *
	 * @param string $text Any bytes.
	 */
	public static function line( string $text ): string {
		$text = (string) preg_replace( '/\p{Cf}+/u', '', mb_scrub( $text, 'UTF-8' ) );

		return trim( (string) preg_replace( '/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text ) );
	}

	private static function duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		if ( $seconds < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%d s', 'lw-migration' ), $seconds );
		}

		/* translators: 1: minutes, 2: seconds */
		return sprintf( __( '%1$d min %2$d s', 'lw-migration' ), intdiv( $seconds, 60 ), $seconds % 60 );
	}
}

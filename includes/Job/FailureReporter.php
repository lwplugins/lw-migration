<?php
/**
 * Turns a job failure into a short, safe message and a detailed log line.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The job's error (shown to the user) and every job.log line lose the site's
 * absolute paths (the log keeps the exception class and the relative
 * file:line). Both have the job's own secret values masked. Logging is best
 * effort.
 */
final class FailureReporter {

	/** Bytes of a failure message kept in the job. */
	private const ERROR_BYTES = 1024;

	/** Replaces a secret value in messages. */
	private const MASK = '***';

	/** How a backups folder with a random suffix is shown (BackupDirRules::SUFFIX_PATTERN). */
	public const BACKUPS_FOLDER = 'lw-migration-backups-…';

	/**
	 * A byte that can be part of a path (boundary test for redaction). Bytes of
	 * multi-byte characters are not, so a path right after typographic quotes
	 * („“«‘) or a pipe is still found.
	 */
	private const PATH_CHAR = '[^\s\'"`:;,()<>\[\]{}=|\x80-\xFF]';

	/**
	 * Paths without trailing slash, longest first.
	 *
	 * @var array<int, string>
	 */
	private array $redactPaths;

	/**
	 * @param JobStore           $store       Job store (for the job folder).
	 * @param array<int, string> $redactPaths Absolute paths removed from failure messages.
	 */
	public function __construct( private JobStore $store, array $redactPaths ) {
		$this->redactPaths = self::normalise( $redactPaths );
	}

	public function fail( Job $job, \Throwable $e, int $now ): void {
		$this->markFailed( $job, $e->getMessage(), $now );
		$this->write( $job, $now, sprintf( 'FAILED in step %s: %s: %s at %s:%d', (string) $job->currentStep(), get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ), true );
	}

	/**
	 * Fails a job for a reason found outside its steps (e.g. the driver lost the site lock).
	 *
	 * @param Job    $job     Job.
	 * @param string $message Reason, shown to the user.
	 * @param int    $now     Unix time.
	 * @return void
	 */
	public function stop( Job $job, string $message, int $now ): void {
		$this->markFailed( $job, $message, $now );
		$this->write( $job, $now, sprintf( 'FAILED in step %s: %s', (string) $job->currentStep(), $message ), true );
	}

	/**
	 * Logs and forgets the warnings added since the last flush (all of them,
	 * also those past the cap of $job->warnings).
	 *
	 * @param Job $job Job.
	 * @param int $now Unix time.
	 * @return void
	 */
	public function flushWarnings( Job $job, int $now ): void {
		foreach ( $job->newWarnings as $warning ) {
			$this->write( $job, $now, 'WARNING ' . $warning, false );
		}
		$job->newWarnings = [];
	}

	private function markFailed( Job $job, string $message, int $now ): void {
		$job->status = Job::FAILED;
		$job->error  = Text::clip( $this->redact( $this->scrub( $job, $message ) ), self::ERROR_BYTES );
		$this->flushWarnings( $job, $now );
	}

	public function note( Job $job, int $now, string $line ): void {
		$this->write( $job, $now, $line, false );
	}

	private function write( Job $job, int $now, string $line, bool $always ): void {
		try {
			JobLog::append( $this->store->dir( $job->id ), $now, $this->redact( $this->scrub( $job, $line ) ), $always );
		} catch ( \Throwable $e ) {
			unset( $e ); // Logging is best effort.
		}
	}

	private function scrub( Job $job, string $text ): string {
		$values = array_filter( $job->secrets, static fn( $v ): bool => is_string( $v ) && '' !== $v );

		return [] === $values ? $text : str_replace( array_values( $values ), self::MASK, $text );
	}

	/**
	 * Removes each path where it stands as a whole path (prefix): "/srv/site"
	 * leaves "/srv/site-old/x" and "/backup/srv/site/x" alone. The random
	 * segment of a backups folder in a web root is a protection: it is shown
	 * as "lw-migration-backups-…" wherever it stands. Also used for REST error
	 * messages and job views (Rest\ErrorMap, Rest\JobView).
	 *
	 * @param string             $message Message.
	 * @param array<int, string> $paths   Absolute paths (any order, with or without a trailing slash).
	 * @return string
	 */
	public static function redactWith( string $message, array $paths ): string {
		foreach ( self::normalise( $paths ) as $path ) {
			$pattern = '#(?<!' . self::PATH_CHAR . ')' . preg_quote( $path, '#' ) . '(?:/|(?!' . self::PATH_CHAR . '))#';
			$result  = preg_replace( $pattern, '', $message );
			$message = null === $result ? $message : $result;
		}
		$result = preg_replace( '/lw-migration-backups-[a-f0-9]{12}/i', self::BACKUPS_FOLDER, $message );

		return null === $result ? $message : $result;
	}

	/**
	 * @param array<int, string> $paths Absolute paths.
	 * @return array<int, string> Without trailing slash or empties, longest first.
	 */
	private static function normalise( array $paths ): array {
		$paths = array_map( static fn( string $p ): string => rtrim( $p, '/' ), $paths );
		$paths = array_values( array_filter( $paths, static fn( string $p ): bool => '' !== $p ) );
		usort( $paths, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $paths;
	}

	private function redact( string $message ): string {
		return self::redactWith( $message, $this->redactPaths );
	}
}

<?php
/**
 * Append-only technical log of one job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

use LightweightPlugins\Migration\Support\ControlChars;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Lives in the protected job folder; never served to the browser. Capped at
 * ~1 MiB: past MAX_BYTES ordinary lines are dropped, while a line marked
 * $always (the job's failure, once per job) is still written.
 */
final class JobLog {

	public const FILE = 'job.log';

	private const MAX_BYTES = 1048576;

	private const LINE_BYTES = 8192;

	/**
	 * @param string $dir    Job folder.
	 * @param int    $now    Unix time.
	 * @param string $line   One line (newlines are flattened, control characters shown as escapes, at most 8 KB kept).
	 * @param bool   $always Write even when the log is full.
	 * @return void
	 */
	public static function append( string $dir, int $now, string $line, bool $always = false ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$file = $dir . '/' . self::FILE;
		clearstatcache( true, $file );
		if ( ! $always && is_file( $file ) && filesize( $file ) >= self::MAX_BYTES ) {
			return;
		}
		$text = Text::clip( ControlChars::escape( str_replace( [ "\r", "\n" ], ' ', $line ) ), self::LINE_BYTES );
		Files::privateFile( $file );
		file_put_contents( $file, gmdate( 'Y-m-d\TH:i:s\Z', $now ) . ' ' . $text . "\n", FILE_APPEND | LOCK_EX );
	}
}

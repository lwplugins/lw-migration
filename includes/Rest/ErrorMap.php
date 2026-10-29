<?php
/**
 * Turns a failure into the parts of a REST error.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Job\FailureReporter;
use LightweightPlugins\Migration\Job\JobBusyException;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Support\Text;
use LightweightPlugins\Migration\WordPress\SiteBusyException;

defined( 'ABSPATH' ) || exit;

/**
 * Only RestException keeps its (user-facing, translated) text, without the
 * server's paths and with addresses in display form. Everything else gets a
 * fixed message, because its text may name files, queries, the database
 * server or a library's internals: other invalid arguments a generic 400,
 * internal job failures (JobException) and anything unexpected a 500 ("try
 * again" would mislead for a lasting fault). A storage failure is a 500 that
 * names what to fix (the backup folder's permissions), never its path.
 * The busy refusals are built here (translated), the site one from the lock
 * expiry the exception carries. The branches with fixed translated texts are
 * tested in the WordPress suite (ErrorMapMessagesTest); the rest is unit-tested.
 */
final class ErrorMap {

	/** Another job holds the site lock (also WebDriver's refusal). */
	public const SITE_BUSY = 'lw_migration_site_busy';

	/** A request value was refused (RestException::invalid() or the generic text). */
	public const INVALID = 'lw_migration_invalid';

	/** Another request holds this job right now. */
	public const JOB_BUSY = 'lw_migration_job_busy';

	/** The storage folder cannot be created or written. */
	public const STORAGE = 'lw_migration_storage';

	private const MESSAGE_BYTES = 1024;

	/**
	 * @param \Throwable         $e        What the route threw.
	 * @param array<int, string> $paths    Absolute paths removed from messages.
	 * @param string             $fallback Message for unexpected failures (translated by the caller).
	 * @return array{code: string, message: string, status: int, data: array<string, mixed>}
	 */
	public static function of( \Throwable $e, array $paths, string $fallback ): array {
		if ( $e instanceof RestException ) {
			return self::error( $e->errorCode, $e->getMessage(), $e->status, $paths, $e->data );
		}
		if ( $e instanceof SiteBusyException ) {
			return self::error( self::SITE_BUSY, SiteBusyException::describe( $e->expiresAt ), 409, [] );
		}
		if ( $e instanceof JobBusyException ) {
			return self::error( self::JOB_BUSY, __( 'This job is being worked on by another request; try again in a moment.', 'lw-migration' ), 409, [] );
		}
		if ( $e instanceof StorageException ) {
			return self::error( self::STORAGE, __( 'The plugin\'s folders or the backups folder cannot be created or written. Check their permissions.', 'lw-migration' ), 500, [] );
		}
		if ( $e instanceof \InvalidArgumentException || $e instanceof ExportException || $e instanceof ImportException ) {
			return self::error( self::INVALID, __( 'The request is not valid.', 'lw-migration' ), 400, [] );
		}

		return self::error( 'lw_migration_error', $fallback, 500, [] );
	}

	/**
	 * @param string               $code    Error code.
	 * @param string               $message Message.
	 * @param int                  $status  HTTP status.
	 * @param array<int, string>   $paths   Paths to remove.
	 * @param array<string, mixed> $data    Extra fields.
	 * @return array{code: string, message: string, status: int, data: array<string, mixed>}
	 */
	private static function error( string $code, string $message, int $status, array $paths, array $data = [] ): array {
		return [
			'code'    => $code,
			'message' => Text::clip( AddressRedactor::reduce( FailureReporter::redactWith( $message, $paths ) ), self::MESSAGE_BYTES ),
			'status'  => $status,
			'data'    => $data,
		];
	}
}

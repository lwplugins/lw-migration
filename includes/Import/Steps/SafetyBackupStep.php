<?php
/**
 * Import step: backs up the current database before anything changes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Export\NestedExport;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the database-only export steps, unchanged, as a nested job in the
 * "safety" work folder (NestedExport); the export's store step names it
 * pre-import-….wpress, retention never prunes it, and it never prunes other
 * backups (the one being restored may be among them). The nested job lives
 * in this step's cursor as cursor.safety, so the store step's hidden temp is
 * at cursor.safety.cursor.temp (ImportCleanup discards it). One nested step
 * per call; a nested failure keeps its warnings and reaches the Runner as
 * "Safety backup: …".
 */
final class SafetyBackupStep implements StepInterface {

	/** Cursor key of the nested export job. */
	public const CURSOR = 'safety';

	/**
	 * @param array<string, StepInterface> $exportSteps Export step implementations by key.
	 */
	public function __construct( private array $exportSteps ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! ImportOptions::fromArray( $job->options )->safetyBackup ) {
			return StepResult::done();
		}
		$more = $this->nested()->advance( $job, self::CURSOR, $deadline );
		if ( null !== $more ) {
			return $more;
		}
		$backup = NestedExport::backup( $job, self::CURSOR );
		if ( null === $backup ) {
			throw new ImportException( __( 'The backup of the current database was not saved, so the import was stopped before anything changed.', 'lw-migration' ) );
		}
		$job->state['safetyBackup'] = $backup;

		/* translators: %s: backup file name */
		return StepResult::done( sprintf( __( 'Current database saved as %s.', 'lw-migration' ), (string) $backup['name'] ) );
	}

	/**
	 * The hidden temporary name of the safety backup's store step in an import
	 * job's cursor (cursor.safety.cursor.temp), or null.
	 *
	 * @param array<string, mixed> $cursor Import job cursor.
	 */
	public static function storeTemp( array $cursor ): ?string {
		return NestedExport::storeTemp( $cursor, self::CURSOR );
	}

	/**
	 * The name the safety backup's store step saved as reserving (an empty
	 * file it may have left), or ''.
	 *
	 * @param array<string, mixed> $cursor Import job cursor.
	 */
	public static function storeReserving( array $cursor ): string {
		return NestedExport::storeReserving( $cursor, self::CURSOR );
	}

	/**
	 * The database-only export in the safety work folder, with the texts of
	 * the backup taken before an import.
	 */
	private function nested(): NestedExport {
		return new NestedExport(
			$this->exportSteps,
			ExportPipeline::stepsFor( is_multisite(), true ),
			ExportOptions::databaseOnly()->toArray(),
			ImportFiles::SAFETY,
			/* translators: %s: error of the backup taken before the import */
			static fn( string $message, ?\Throwable $previous ): ImportException => new ImportException( sprintf( __( 'Safety backup: %s', 'lw-migration' ), $message ), 0, $previous ),
			/* translators: %s: warning of the backup taken before the import */
			static fn( string $warning ): string => sprintf( __( 'Safety backup: %s', 'lw-migration' ), $warning ),
			static fn( string $text ): string => self::progressText( $text ),
			static fn( ?string $missing ): ImportException => self::refused( $missing )
		);
	}

	/**
	 * @param string $text What the nested step does now ('' = nothing to tell).
	 */
	private static function progressText( string $text ): string {
		if ( '' === $text ) {
			return __( 'Backing up the current database first...', 'lw-migration' );
		}

		/* translators: %s: what the backup of the current database does now, e.g. "Exporting the database: table 3 of 12..." */
		return sprintf( __( 'Backing up the current database first: %s', 'lw-migration' ), $text );
	}

	/**
	 * @param string|null $missing The key of a missing nested step, or null for an input request.
	 */
	private static function refused( ?string $missing ): ImportException {
		if ( null === $missing ) {
			return new ImportException( __( 'The backup of the current database asked for input it cannot get, so the import was stopped before anything changed.', 'lw-migration' ) );
		}

		/* translators: %s: step key */
		return new ImportException( sprintf( __( 'The backup of the current database cannot run: its step "%s" is missing. The import was stopped before anything changed.', 'lw-migration' ), $missing ) );
	}
}

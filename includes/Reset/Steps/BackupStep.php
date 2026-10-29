<?php
/**
 * Reset step: backs up the whole site before anything is removed.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Export\NestedExport;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the whole export (files and database) as a nested job in the
 * "reset-backup" work folder (NestedExport, cursor.backup); the store step
 * names it pre-reset-….wpress, retention never prunes it and it prunes
 * nothing. Once stored it is labelled with what the reset removes, once: a
 * replay finds state.backup set and is done. No backup, no reset.
 */
final class BackupStep implements StepInterface {

	public const KEY = ResetType::BACKUP;

	/** Cursor key of the nested export job. */
	public const CURSOR = 'backup';

	/** Work sub-folder of the nested export job. */
	public const WORK_SUB = 'reset-backup';

	/** @var \Closure fn( string $name, string $text ): void */
	private \Closure $label;

	/**
	 * @param array<string, StepInterface> $exportSteps Export step implementations by key.
	 * @param \Closure|null                $label       fn( string $name, string $text ): void; null = Labels::set().
	 */
	public function __construct( private array $exportSteps, ?\Closure $label = null ) {
		$this->label = $label ?? static function ( string $name, string $text ): void {
			Labels::set( $name, $text );
		};
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( isset( $job->state['backup'] ) ) {
			return StepResult::done();
		}
		$more = $this->nested()->advance( $job, self::CURSOR, $deadline );
		if ( null !== $more ) {
			return $more;
		}
		$backup = NestedExport::backup( $job, self::CURSOR );
		if ( null === $backup ) {
			throw new JobException( __( 'The backup before the reset was not saved, so nothing was reset.', 'lw-migration' ) );
		}
		$job->state['backup'] = [
			'name' => (string) $backup['name'],
			'size' => (int) ( $backup['size'] ?? 0 ),
		];
		try {
			( $this->label )( (string) $backup['name'], ResetTexts::backupLabel( ResetJobs::type( $job ) ) );
		} catch ( \Throwable $e ) {
			$job->addWarning( __( 'The backup was saved but could not be labelled.', 'lw-migration' ) );
		}

		/* translators: %s: backup file name */
		return StepResult::done( sprintf( __( 'Backup saved as %s.', 'lw-migration' ), (string) $backup['name'] ) );
	}

	/**
	 * The whole export in the reset's work folder, with the texts of the
	 * backup taken before a reset.
	 */
	private function nested(): NestedExport {
		return new NestedExport(
			$this->exportSteps,
			ExportPipeline::stepsFor( is_multisite(), false ),
			ExportOptions::beforeReset()->toArray(),
			self::WORK_SUB,
			/* translators: %s: error of the backup taken before the reset */
			static fn( string $message, ?\Throwable $previous ): JobException => new JobException( sprintf( __( 'Backup before the reset: %s', 'lw-migration' ), $message ), 0, $previous ),
			/* translators: %s: warning of the backup taken before the reset */
			static fn( string $warning ): string => sprintf( __( 'Backup before the reset: %s', 'lw-migration' ), $warning ),
			static fn( string $text ): string => self::progressText( $text ),
			static fn( ?string $missing ): JobException => self::refused( $missing )
		);
	}

	/**
	 * @param string $text What the nested step does now ('' = nothing to tell).
	 */
	private static function progressText( string $text ): string {
		if ( '' === $text ) {
			return __( 'Backing up the site first...', 'lw-migration' );
		}

		/* translators: %s: what the backup does now, e.g. "Exporting the database: table 3 of 12..." */
		return sprintf( __( 'Backing up the site first: %s', 'lw-migration' ), $text );
	}

	/**
	 * @param string|null $missing The key of a missing nested step, or null for an input request.
	 */
	private static function refused( ?string $missing ): JobException {
		if ( null === $missing ) {
			return new JobException( __( 'The backup before the reset asked for input it cannot get, so nothing was reset.', 'lw-migration' ) );
		}

		/* translators: %s: step key */
		return new JobException( sprintf( __( 'The backup before the reset cannot run: its step "%s" is missing. Nothing was reset.', 'lw-migration' ), $missing ) );
	}
}

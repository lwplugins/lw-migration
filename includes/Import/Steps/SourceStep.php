<?php
/**
 * Import step: finds a local archive (file or existing backup).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Local archives are read in place, never copied (decision 16).
 */
final class SourceStep implements StepInterface {

	/**
	 * @param Repository $backups Backups a BACKUP source is looked up in.
	 * @param string     $jobsDir The plugin's job folder; a FILE source inside it is refused.
	 */
	public function __construct( private Repository $backups, private string $jobsDir ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$options = ImportOptions::fromArray( $job->options );
		$path    = $this->resolve( $options );
		if ( null === $path ) {
			// Only the file name: the message reaches job.json and the CLI, never an absolute path.
			/* translators: %s: backup or archive file name */
			throw new ImportException( sprintf( __( 'The archive cannot be found or read: %s', 'lw-migration' ), basename( $options->location ) ) );
		}
		$job->state['archive']      = $path;
		$job->state['archiveOwned'] = false;

		return StepResult::done( __( 'Archive found.', 'lw-migration' ) );
	}

	/**
	 * The real path of a readable .wpress file, or null.
	 *
	 * @param ImportOptions $options Import source.
	 */
	private function resolve( ImportOptions $options ): ?string {
		if ( ImportOptions::BACKUP === $options->source ) {
			$backup = '' === $options->folder ? $this->backups->find( $options->location ) : $this->backups->findIn( $options->location, $options->folder );
			$path   = null === $backup ? null : $backup->path;
		} elseif ( ImportOptions::FILE === $options->source ) {
			// A link could point the import at any file; another job's work files are never a source.
			$path = is_link( $options->location ) ? null : $options->location;
		} else {
			$path = null;
		}
		$real = null === $path ? false : realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) || ! Reader::hasArchiveExtension( $real ) ) {
			return null;
		}
		if ( ImportOptions::FILE === $options->source && $this->insideJobs( $real ) ) {
			return null;
		}

		return $real;
	}

	private function insideJobs( string $real ): bool {
		$jobs = realpath( $this->jobsDir );
		$jobs = false === $jobs ? rtrim( $this->jobsDir, '/' ) : $jobs;

		return '' !== $jobs && str_starts_with( $real, $jobs . '/' );
	}
}

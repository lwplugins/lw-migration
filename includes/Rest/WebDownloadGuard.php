<?php
/**
 * Stops a whole-file download that cannot finish in one browser request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the url_download step of a runner the browser drives. The check sits
 * at the step itself, so it also holds when url_resolve finds the server
 * cannot send parts and hands over to url_download in the same tick: the job
 * fails with advice instead of dying at a proxy timeout mid-download.
 */
final class WebDownloadGuard implements StepInterface {

	/** Key of the step this guard wraps. */
	public const STEP = 'url_download';

	/**
	 * @param StepInterface $inner The download step.
	 */
	public function __construct( private StepInterface $inner ) {
	}

	/**
	 * The steps with the download step guarded (when there is one).
	 *
	 * @param array<string, StepInterface> $steps Steps by key.
	 * @return array<string, StepInterface>
	 */
	public static function wrap( array $steps ): array {
		if ( isset( $steps[ self::STEP ] ) && ! $steps[ self::STEP ] instanceof self ) {
			$steps[ self::STEP ] = new self( $steps[ self::STEP ] );
		}

		return $steps;
	}

	/**
	 * @param Job      $job      Job.
	 * @param Deadline $deadline Deadline.
	 * @throws ImportException When the download may not fit one request.
	 */
	public function run( Job $job, Deadline $deadline ): StepResult {
		$reason = WebLimits::tooLargeForOneRequest( $job );
		if ( null !== $reason ) {
			throw new ImportException( $reason );
		}

		return $this->inner->run( $job, $deadline );
	}
}

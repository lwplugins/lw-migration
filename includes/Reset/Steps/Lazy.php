<?php
/**
 * A step built the first time it runs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the pipeline list a step whose construction reads the site (the
 * plugin or theme list) without paying for it in a job of another type.
 */
final class Lazy implements StepInterface {

	/** @var StepInterface|null The step, once built. */
	private ?StepInterface $step = null;

	/**
	 * @param \Closure $make fn(): StepInterface.
	 */
	public function __construct( private \Closure $make ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$this->step ??= ( $this->make )();

		return $this->step->run( $job, $deadline );
	}
}

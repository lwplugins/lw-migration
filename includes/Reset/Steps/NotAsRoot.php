<?php
/**
 * Refuses every step of a file reset run as root.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetRefused;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps each step of the reset pipeline: a reset that changes files
 * (plugins, themes, media, full) fails on any request made as root or as an
 * unknown user before the step touches anything, the backup and the
 * database steps of a full reset included (a resume as root fails the job,
 * fail closed). The database reset may run as root.
 */
final class NotAsRoot implements StepInterface {

	/**
	 * @param StepInterface  $step The wrapped step.
	 * @param RootGuard|null $root Null = this process.
	 */
	public function __construct( private StepInterface $step, private ?RootGuard $root = null ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$type = ResetJobs::type( $job );
		if ( ( ResetType::touchesCode( $type ) || ResetType::touchesMedia( $type ) ) && ( $this->root ?? RootGuard::current() )->isRoot() ) {
			throw new JobException( ResetTexts::refusal( ResetRefused::AS_ROOT ) );
		}

		return $this->step->run( $job, $deadline );
	}
}

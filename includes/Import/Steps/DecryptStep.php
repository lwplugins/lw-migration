<?php
/**
 * Import step: checks the password of an encrypted archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Package\WrongPasswordException;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the package.json signature; asking again clears the wrong value (Runner).
 *
 * An encrypted archive without a signature cannot be checked here: any password
 * passes, and a wrong one fails at the first data entry, before anything is put in place.
 */
final class DecryptStep implements StepInterface {

	public function run( Job $job, Deadline $deadline ): StepResult {
		$package = ImportState::package( $job );
		if ( ! $package->isEncrypted() ) {
			return StepResult::done();
		}
		$password = $job->secrets['password'] ?? null;
		if ( ! is_string( $password ) || '' === $password ) {
			return StepResult::needsInput( 'password', [ 'reason' => 'encrypted' ] );
		}
		try {
			PackageCodec::forPackage( $package, $password );
		} catch ( WrongPasswordException $e ) {
			return StepResult::needsInput( 'password', [ 'reason' => 'wrong' ] );
		}

		return StepResult::done( __( 'Password accepted.', 'lw-migration' ) );
	}
}

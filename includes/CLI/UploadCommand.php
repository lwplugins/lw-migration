<?php
/**
 * Uploads an existing backup to destinations.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Rest\CloudApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads a backup from the backups list to one or more destinations.
 */
final class UploadCommand {

	/**
	 * Uploads a backup to one or more destinations. Old backups there are not removed.
	 *
	 * ## OPTIONS
	 *
	 * <backup>
	 * : Backup name (see `wp lw-migration list`).
	 *
	 * --to=<ids>
	 * : Comma-separated destination IDs (see `wp lw-migration destination list`).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration upload example-com-20261012-030000-123.wpress --to=0a1b2c3d
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function __invoke( array $args, array $assoc ): void {
		$services = Services::get();
		$backup   = $services->backups()->find( (string) ( $args[0] ?? '' ) );
		if ( null === $backup ) {
			Console::error( __( 'There is no backup with this name. See wp lw-migration list.', 'lw-migration' ) );
			return;
		}
		try {
			$job = CloudApi::fromServices( $services )->upload(
				[
					'folder'       => $backup->source,
					'name'         => $backup->name,
					'destinations' => CloudPlan::idsFrom( (string) ( $assoc['to'] ?? '' ) ),
				]
			);
		} catch ( RestException | \RuntimeException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		$secrets = new MemorySecretStore();
		try {
			$done = Drive::run( $services, $job->id, $secrets );
		} catch ( \RuntimeException $e ) {
			Console::error( Drive::stop( $services, $job->id, $secrets, $e ) );
			return;
		}
		self::conclude( $done );
	}

	/**
	 * Prints what happened at each destination; an upload that did not reach
	 * every destination ends the command with an error (exit status 1).
	 *
	 * @param Job $done Finished upload job.
	 */
	public static function conclude( Job $done ): void {
		CloudLines::print( $done );
		if ( CloudOutcome::partial( $done ) ) {
			Console::error( __( 'Not every upload succeeded.', 'lw-migration' ) );
		}
	}
}

<?php
/**
 * `wp lw-migration delete`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Rest\BackupsApi;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\MoveJobs;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes a backup.
 */
final class DeleteCommand {

	/**
	 * Deletes a backup from either backups folder.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Backup name (see `wp lw-migration list`).
	 *
	 * [--yes]
	 * : Do not ask for confirmation (required when not run from a terminal).
	 *
	 * @param array<int, string>   $args  Backup name.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$name     = (string) $args[0];
		$services = Services::get();
		if ( MoveJobs::active( $services->jobs(), time() ) ) {
			Console::error( BackupsApi::movingText() );
			return;
		}
		$repo = $services->backups();
		if ( null === $repo->find( $name ) ) {
			/* translators: %s: backup name */
			Console::error( sprintf( __( 'No backup named %s.', 'lw-migration' ), $name ) );
			return;
		}
		self::confirm( $name, $assoc );
		if ( ! $repo->delete( $name ) ) {
			/* translators: %s: backup name */
			Console::error( sprintf( __( 'Could not delete %s.', 'lw-migration' ), $name ) );
			return;
		}
		Labels::remove( $name );
		/* translators: %s: backup name */
		Console::success( sprintf( __( 'Deleted %s.', 'lw-migration' ), $name ) );
	}

	/**
	 * Returns only when the deletion is confirmed; otherwise exits with an error.
	 *
	 * @param string               $name  Backup name.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	private static function confirm( string $name, array $assoc ): void {
		/* translators: %s: backup name */
		$ask      = static fn(): ?string => Prompt::line( sprintf( __( 'Delete the backup %s? [y/n]', 'lw-migration' ), $name ) );
		$decision = Confirmation::decide( $assoc, Confirmation::stdinIsInteractive(), $ask );
		if ( Confirmation::NEEDS_YES === $decision ) {
			Console::error( __( 'Pass --yes to delete without a prompt.', 'lw-migration' ) );
		}
		if ( Confirmation::DECLINED === $decision ) {
			Console::error( __( 'Nothing deleted.', 'lw-migration' ) );
		}
	}
}

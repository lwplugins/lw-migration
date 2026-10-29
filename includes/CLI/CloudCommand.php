<?php
/**
 * Backups at a destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Rest\CloudApi;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, restores and deletes backups at a destination.
 */
final class CloudCommand {

	/**
	 * Whether STDIN is a terminal: fn(): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $interactive;

	/**
	 * @param \Closure|null $interactive fn(): bool (tests; null = Confirmation::stdinIsInteractive()).
	 */
	public function __construct( ?\Closure $interactive = null ) {
		$this->interactive = $interactive ?? static fn(): bool => Confirmation::stdinIsInteractive();
	}

	/**
	 * Lists the backups at a destination, newest first.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Destination ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function listing( array $args, array $assoc ): void {
		try {
			$files = CloudApi::fromServices( Services::get() )->files( (string) ( $args[0] ?? '' ) );
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		$rows = array_map(
			static fn( array $f ): array => [
				'name' => $f['name'],
				'size' => size_format( $f['size'] ),
				'date' => $f['mtime'] > 0 ? gmdate( 'Y-m-d H:i', $f['mtime'] ) . ' UTC' : '-',
				'ours' => $f['ours'] ? 'yes' : 'no',
			],
			$files['files']
		);
		Console::items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'name', 'size', 'date', 'ours' ] );
	}

	/**
	 * Downloads a backup from a destination into the backups folder and restores this site from it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Destination ID.
	 *
	 * <file>
	 * : Backup name at the destination.
	 *
	 * [--yes]
	 * : Do not ask for confirmation (required when not run from a terminal).
	 *
	 * [--safety-backup]
	 * : Back up this site's database first (the default). Use --no-safety-backup to skip it.
	 *
	 * [--password-file=<path>]
	 * : Read the password of an encrypted backup from this file (the safest form for scripts).
	 *
	 * [--password[=<password>]]
	 * : Password of an encrypted backup. Without a value you are asked for it on a terminal, or it is read as one line from standard input; a value on the command line stays in the process list and the shell history. Not together with --password-file.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function restore( array $args, array $assoc ): void {
		if ( Confirmation::requiresYes( $assoc, ( $this->interactive )() ) ) {
			Console::error( __( 'Pass --yes to restore without a prompt.', 'lw-migration' ) );
			return;
		}
		$services      = Services::get();
		[ $id, $name ] = [ (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) ];
		try {
			$file = CloudApi::fromServices( $services )->remoteFile( $id, $name ); // Also checks the backups folder and a local backup of that name.
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		$safety  = ! array_key_exists( 'safety-backup', $assoc ) || false !== $assoc['safety-backup']; // --no-safety-backup gives false.
		$options = new ImportOptions( ImportOptions::CLOUD, $name, $safety );
		ImportCommand::run( $services, $options, $assoc, $name, [], [ 'cloudSource' => CloudJobs::source( $id, $file ) ] );
	}

	/**
	 * Deletes a backup at a destination.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Destination ID.
	 *
	 * <file>
	 * : Backup name at the destination.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function delete( array $args, array $assoc ): void {
		[ $id, $name ] = [ (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ) ];
		if ( ! BackupName::isValid( $name ) ) {
			Console::error( __( 'This is not the name of a backup file (.wpress).', 'lw-migration' ) );
			return;
		}
		try {
			DestinationsApi::fromServices( Services::get() )->find( $id ); // A wrong destination is told before anything is asked.
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		$interactive = ( $this->interactive )();
		/* translators: %s: backup file name */
		$decision = Confirmation::decide( $assoc, $interactive, static fn(): ?string => Prompt::line( sprintf( __( 'Delete %s at the destination? [y/n]', 'lw-migration' ), $name ) ) );
		if ( Confirmation::CONFIRMED !== $decision ) {
			Console::error( Confirmation::NEEDS_YES === $decision ? __( 'Without a terminal, confirm with --yes.', 'lw-migration' ) : __( 'Nothing was deleted.', 'lw-migration' ) );
			return;
		}
		try {
			CloudApi::fromServices( Services::get() )->deleteFile( $id, $name );
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		/* translators: %s: backup file name */
		Console::success( sprintf( __( 'Deleted %s at the destination.', 'lw-migration' ), $name ) );
	}
}

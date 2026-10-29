<?php
/**
 * `wp lw-migration export`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Export\ExportJobs;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a backup of this site.
 */
final class ExportCommand {

	/**
	 * Creates a .wpress backup of this site (on a multisite network: of the
	 * whole network or the chosen sites) in the backups folder.
	 *
	 * ## OPTIONS
	 *
	 * [<value>...]
	 * : With --replace: old and new values, in pairs.
	 *
	 * [--replace]
	 * : Replace values in the exported database; pairs of <old> <new> follow.
	 *
	 * [--exclude-spam-comments]
	 * : Leave out spam comments.
	 *
	 * [--exclude-post-revisions]
	 * : Leave out post revisions.
	 *
	 * [--exclude-media]
	 * : Leave out the media library files.
	 *
	 * [--exclude-themes]
	 * : Leave out all themes.
	 *
	 * [--exclude-inactive-themes]
	 * : Leave out inactive themes.
	 *
	 * [--exclude-muplugins]
	 * : Leave out must-use plugins.
	 *
	 * [--exclude-plugins]
	 * : Leave out all plugins.
	 *
	 * [--exclude-inactive-plugins]
	 * : Leave out inactive plugins.
	 *
	 * [--exclude-cache]
	 * : Leave out wp-content/cache.
	 *
	 * [--exclude-files=<paths>]
	 * : Comma-separated paths relative to wp-content.
	 *
	 * [--exclude-database]
	 * : Leave out the database.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated tables to leave out.
	 *
	 * [--include-tables=<tables>]
	 * : Comma-separated tables without the site prefix to add.
	 *
	 * [--exclude-email-replace]
	 * : Do not replace the e-mail domain when this backup is restored elsewhere.
	 *
	 * [--compression=<type>]
	 * : Compress the backup.
	 * ---
	 * options:
	 *   - gzip
	 *   - bzip2
	 * ---
	 *
	 * [--password-file=<path>]
	 * : Encrypt the backup with the password in this file (the safest form for scripts).
	 *
	 * [--password[=<password>]]
	 * : Encrypt the backup. Without a value you are asked for the password on a terminal, or it is read as one line from standard input (recommended: a value on the command line stays in the process list and the shell history). Not together with --password-file. The password protects confidentiality only: use a long, random one; a changed archive is not detected cryptographically, so keep backups on trusted storage.
	 *
	 * [--sites=<ids>]
	 * : Multisite only: comma-separated IDs of the sites to back up (see `wp site list`). Without it the whole network is backed up.
	 *
	 * [--to=<ids>]
	 * : Comma-separated destination IDs (see `wp lw-migration destination list`): the backup is uploaded to each after it is saved, and old backups there are pruned by the destination's retention.
	 *
	 * [--[no-]keep-local]
	 * : With --to: keep the local copy (default). --no-keep-local deletes it once every upload succeeded.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration export --exclude-cache --compression=gzip
	 *     wp lw-migration export --replace http://old.test https://new.test
	 *     wp lw-migration export --password-file=/root/.backup-password
	 *     printf '%s\n' "$BACKUP_PASSWORD" | wp lw-migration export --password
	 *     wp lw-migration export --password
	 *     wp lw-migration export --sites=2,5
	 *     wp lw-migration export --to=0a1b2c3d,4e5f6a7b --no-keep-local
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		try {
			$options = ExportOptions::fromCli( $args, $assoc );
		} catch ( \InvalidArgumentException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		$cloud = self::cloudPlan( $assoc );
		if ( false === $cloud ) {
			return;
		}
		$password = Prompt::password( $assoc, true );
		$services = Services::get();
		$secrets  = new MemorySecretStore();
		try {
			$services->storage()->ensure();
			$services->storage()->ensureBackups();
			$job = ExportJobs::create( $services->jobs(), $services->lock(), SiteReader::read(), $options, $password, $secrets, time(), [], $cloud );
		} catch ( FolderRefusedException $e ) {
			Console::error( FolderMessages::of( $e->reason ) );
			return;
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		try {
			$done = Drive::run( $services, $job->id, $secrets );
		} catch ( \RuntimeException $e ) { // Ctrl-C, lost lock, busy job, store errors.
			Console::error( Drive::stop( $services, $job->id, $secrets, $e ) );
			return;
		}
		$backup = (array) ( $done->state['backup'] ?? [] );
		/* translators: %s: backup file name */
		Console::log( sprintf( __( 'Backup file: %s', 'lw-migration' ), (string) ( $backup['name'] ?? '' ) ) );
		if ( false !== ( $backup['local'] ?? true ) ) { // No location for a local copy that was deleted.
			/* translators: %s: absolute path of the backup */
			Console::log( sprintf( __( 'Backup location: %s', 'lw-migration' ), (string) ( $backup['path'] ?? '' ) ) );
		} else {
			Console::log( __( 'The local copy was deleted: every upload succeeded.', 'lw-migration' ) );
		}
		CloudLines::conclude( $done );
	}

	/**
	 * The upload plan of --to, null without it.
	 *
	 * @param array<string, mixed> $assoc Flags.
	 * @return CloudPlan|null|false False after an error was printed.
	 */
	private static function cloudPlan( array $assoc ): CloudPlan|null|false {
		if ( ! isset( $assoc['to'] ) ) {
			if ( array_key_exists( 'keep-local', $assoc ) ) {
				Console::error( __( '--keep-local and --no-keep-local work only with --to.', 'lw-migration' ) );
				return false;
			}
			return null;
		}
		try {
			$plan = CloudPlan::manual( CloudPlan::idsFrom( (string) $assoc['to'] ), false !== ( $assoc['keep-local'] ?? true ) );
		} catch ( \InvalidArgumentException $e ) {
			Console::error( $e->getMessage() );
			return false;
		}
		$destinations = Destinations::fromWordPress();
		foreach ( $plan->ids() as $id ) {
			if ( null === $destinations->find( $id ) ) {
				/* translators: %s: destination ID */
				Console::error( sprintf( __( 'There is no destination %s. See wp lw-migration destination list.', 'lw-migration' ), $id ) );
				return false;
			}
		}

		return $plan;
	}
}

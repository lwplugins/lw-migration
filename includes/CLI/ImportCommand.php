<?php
/**
 * `wp lw-migration import`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Import\ImportJobs;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a backup or imports a .wpress archive into this site.
 */
final class ImportCommand {

	/**
	 * Restores a backup or imports a .wpress archive into this site. Everything in
	 * the archive replaces the matching parts of this site.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Backup name (see `wp lw-migration list`), path to a .wpress file, or an http(s) address.
	 *
	 * [--yes]
	 * : Do not ask for confirmation (required when not run from a terminal).
	 *
	 * [--password-file=<path>]
	 * : Read the password of an encrypted archive from this file (the safest form for scripts).
	 *
	 * [--password[=<password>]]
	 * : Password of an encrypted archive. Without a value you are asked for it on a terminal, or it is read as one line from standard input (recommended: a value on the command line stays in the process list and the shell history). Not together with --password-file. Without either you are asked only when the archive is encrypted, on a terminal.
	 *
	 * [--safety-backup]
	 * : Back up the current database first (the default). Use --no-safety-backup to skip it.
	 *
	 * [--site-url=<urls>]
	 * : Restoring into a multisite network: the new address of each site of the archive, comma-separated in archive order (a site archive, or a single-site archive restored as a new subsite). An address of an existing subsite replaces that subsite. Without it the proposed addresses are used with --yes, or you are asked.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration import site-20261007-101500-123.wpress --user=admin
	 *     wp lw-migration import https://example.com/site.wpress --yes --user=admin
	 *     wp lw-migration import shop.wpress --site-url=https://example.com/shop --user=admin
	 *
	 * @param array<int, string>   $args  Source.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$interactive = Confirmation::stdinIsInteractive();
		if ( Confirmation::requiresYes( $assoc, $interactive ) ) {
			Console::error( __( 'Pass --yes to import without a prompt.', 'lw-migration' ) );
			return;
		}
		$services = Services::get();
		$typed    = (string) $args[0];
		if ( array_key_exists( 'site-url', $assoc ) && ! is_multisite() ) {
			Console::error( __( '--site-url works only when restoring into a multisite network.', 'lw-migration' ) );
			return;
		}
		try {
			$siteUrls = NetworkCli::siteUrls( $assoc );
			$options  = ImportSource::options( $typed, $services->backups(), false !== ( $assoc['safety-backup'] ?? true ) );
		} catch ( \InvalidArgumentException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		self::run( $services, $options, $assoc, ImportSource::shown( $options, $typed ), $siteUrls );
	}

	/**
	 * Starts the import of a known source and drives it to its end here
	 * (password prompt, storage checks, the confirmation, the driver). Also
	 * the second half of `wp lw-migration pull`.
	 *
	 * @param Services             $services Services.
	 * @param ImportOptions        $options  The source.
	 * @param array<string, mixed> $assoc    Command flags.
	 * @param string               $shown    The source as the user may see it.
	 * @param array<int, string>   $siteUrls Restoring into a network: the new addresses (--site-url).
	 * @param array<string, mixed> $state    Extra job state (a pull's link).
	 * @return void
	 */
	public static function run( Services $services, ImportOptions $options, array $assoc, string $shown, array $siteUrls = [], array $state = [] ): void {
		$interactive = Confirmation::stdinIsInteractive();
		$password    = Prompt::password( $assoc, false );
		$secrets     = new MemorySecretStore();
		try {
			$services->storage()->ensure();
			if ( $options->safetyBackup ) {
				$services->storage()->ensureBackups();
			}
			$job = ImportJobs::create( $services->jobs(), $services->lock(), SiteReader::read(), $options, self::admin(), '' !== (string) get_option( 'permalink_structure' ), $password, $secrets, time(), $state );
		} catch ( FolderRefusedException $e ) {
			Console::error( FolderMessages::of( $e->reason ) );
			return;
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		/* translators: %s: backup name, file as typed, or address without its secrets */
		Console::log( sprintf( __( 'Importing %s', 'lw-migration' ), $shown ) );
		$answers = new ImportAnswers( $password, ! empty( $assoc['yes'] ), self::askPassword( $interactive ), self::confirm( $assoc, $interactive ), self::show(), static fn( string $warning ) => Console::warning( $warning ), $siteUrls, self::askAddress() );
		$onEnd   = \Closure::fromCallable( [ self::class, 'ended' ] );
		try {
			$done = Drive::run( $services, $job->id, $secrets, \Closure::fromCallable( [ $answers, 'answer' ] ), $onEnd );
		} catch ( \RuntimeException $e ) { // Ctrl-C, lost lock, busy job, no usable password.
			Console::error( Drive::stop( $services, $job->id, $secrets, $e, $onEnd ) );
			return;
		}
		Console::success( ImportAnswers::success( $done ) ); // The driver printed the job's final message (with the safety backup name).
	}

	/**
	 * The admin running the command (--user) stays logged in after the import.
	 *
	 * @return array{id: int, login: string}|null
	 */
	private static function admin(): ?array {
		$user = wp_get_current_user();
		if ( $user->ID > 0 ) {
			return [
				'id'    => (int) $user->ID,
				'login' => (string) $user->user_login,
			];
		}
		Console::warning( __( 'No --user given: your login is not carried over. Use --user=<admin login> to stay logged in after the import.', 'lw-migration' ) );

		return null;
	}

	/**
	 * After a failed or cancelled import: where the previous database is (its
	 * notices were printed as warnings).
	 *
	 * @param Job $job Ended job.
	 * @return void
	 */
	private static function ended( Job $job ): void {
		$backup = $job->state['safetyBackup']['name'] ?? null;
		if ( is_string( $backup ) && '' !== $backup ) {
			/* translators: %s: backup file name */
			Console::log( sprintf( __( 'Safety backup of the previous database: %s', 'lw-migration' ), $backup ) );
		}
	}

	/**
	 * @param bool $interactive Whether STDIN is a terminal.
	 * @return \Closure fn(): ?string
	 */
	private static function askPassword( bool $interactive ): \Closure {
		return static function () use ( $interactive ): ?string {
			if ( ! $interactive ) {
				throw new \RuntimeException( __( 'The archive is encrypted. Give the password with --password-file=<path>, or as one line on standard input with --password (--password=<password> also works, but stays in the process list), or run the command in a terminal to be asked for it.', 'lw-migration' ) );
			}

			return Prompt::hidden( __( 'Archive password', 'lw-migration' ) );
		};
	}

	/**
	 * @return \Closure fn( string $from, string $proposal ): ?string, null at end of input.
	 */
	private static function askAddress(): \Closure {
		return static function ( string $from, string $proposal ): ?string {
			/* translators: 1: a site's old address, 2: its proposed new address (used when nothing is typed) */
			return Prompt::line( sprintf( __( 'New address of %1$s [%2$s]:', 'lw-migration' ), $from, $proposal ) );
		};
	}

	/**
	 * Shows the summary (under its heading) and asks; never exits (the job
	 * holds the site lock). `wp lw-migration push` asks its own question.
	 *
	 * @param array<string, mixed> $assoc       Command flags.
	 * @param bool                 $interactive Whether STDIN is a terminal.
	 * @param string|null          $question    The yes/no question (default: the import's).
	 * @param string|null          $heading     A line printed before the summary.
	 * @return \Closure fn( array<int, string> $lines ): bool
	 */
	public static function confirm( array $assoc, bool $interactive, ?string $question = null, ?string $heading = null ): \Closure {
		$question ??= __( 'Import this archive into this site? [y/n]', 'lw-migration' );

		return static function ( array $lines ) use ( $assoc, $interactive, $question, $heading ): bool {
			( self::show() )( null === $heading ? $lines : array_merge( [ $heading ], $lines ) );
			$ask = static fn(): ?string => Prompt::line( $question );

			return Confirmation::CONFIRMED === Confirmation::decide( $assoc, $interactive, $ask );
		};
	}

	/**
	 * @return \Closure fn( array<int, string> $lines ): void
	 */
	public static function show(): \Closure {
		return static function ( array $lines ): void {
			foreach ( $lines as $line ) {
				Console::log( (string) $line );
			}
		};
	}
}

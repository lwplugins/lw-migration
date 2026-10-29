<?php
/**
 * `wp lw-migration push`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Link\Client;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\PushJobs;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\Rest\JobView;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Sends this site to a connected site.
 */
final class PushCommand {

	/**
	 * Sends this site to a connected site: a backup of this site is made here,
	 * sent over the connection and restored there. Everything in it replaces
	 * the matching parts of the other site.
	 *
	 * ## OPTIONS
	 *
	 * <link-id>
	 * : The connection (see `wp lw-migration link list`).
	 *
	 * [<value>...]
	 * : With --replace: old and new values, in pairs.
	 *
	 * [--replace]
	 * : Replace values in the sent database; pairs of <old> <new> follow.
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
	 * : Do not replace the e-mail domain on the other site.
	 *
	 * [--compression=<type>]
	 * : Compress what is sent.
	 * ---
	 * options:
	 *   - gzip
	 *   - bzip2
	 * ---
	 *
	 * [--safety-backup]
	 * : The other site backs up its database first (the default). Use --no-safety-backup to skip it.
	 *
	 * [--yes]
	 * : Restore on the other site without asking (required when not run from a terminal).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration push 0123456789abcdef --exclude-cache --yes
	 *
	 * @param array<int, string>   $args  Link id, then --replace values.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		LinkCommand::singleSiteOnly();
		$interactive = Confirmation::stdinIsInteractive();
		if ( Confirmation::requiresYes( $assoc, $interactive ) ) {
			Console::error( __( 'Pass --yes to push without a prompt.', 'lw-migration' ) );
			return;
		}
		$services = Services::get();
		try {
			$link    = self::link( $args );
			$options = ExportOptions::fromCli( $args, $assoc );
			$safety  = ! array_key_exists( 'safety-backup', $assoc ) || false !== $assoc['safety-backup']; // --no-safety-backup gives false.
			$services->storage()->ensure();
			$job = PushJobs::create( $services->jobs(), $services->lock(), SiteReader::read(), $options, $link, $safety, time() );
		} catch ( FolderRefusedException $e ) {
			Console::error( FolderMessages::of( $e->reason ) );
			return;
		} catch ( \RuntimeException | \InvalidArgumentException $e ) { // LinkException, SiteBusyException, ExportException, a refused flag.
			Console::error( $e instanceof SiteBusyException ? SiteBusyException::describe( $e->expiresAt ) : $e->getMessage() );
			return;
		}
		if ( Client::insecure( $link['address'] ) ) {
			LinkCommand::httpWarning();
		}
		/* translators: 1: the other site's name, 2: its address */
		Console::log( sprintf( __( 'Sending this site to %1$s (%2$s)…', 'lw-migration' ), $link['name'], $link['address'] ) );
		$secrets = new MemorySecretStore();
		try {
			$done = Drive::run( $services, $job->id, $secrets, self::answers( $assoc, $interactive, $link ) );
		} catch ( \RuntimeException $e ) { // Ctrl-C, lost lock, busy job, a refused question.
			Console::error( Drive::stop( $services, $job->id, $secrets, $e ) );
			return;
		}
		Console::success( $done->message );
	}

	/**
	 * The relayed summary of the other site, shown under its name and
	 * confirmed here (--yes, or y in a terminal); only the fields of the job
	 * view are shown, whatever the other site sent (review m15: kept apart
	 * for the method size limit).
	 *
	 * @param array<string, mixed>                             $assoc       Flags.
	 * @param bool                                             $interactive Whether STDIN is a terminal.
	 * @param array{id: string, name: string, address: string} $link        The connection.
	 * @return \Closure fn( Job $job ): ?array — the input, or null to cancel the push.
	 */
	public static function answers( array $assoc, bool $interactive, array $link ): \Closure {
		$show = ImportCommand::show();
		/* translators: 1: the other site's name, 2: its address */
		$heading = sprintf( __( 'On %1$s (%2$s):', 'lw-migration' ), $link['name'], $link['address'] );
		$answers = new ImportAnswers(
			null,
			! empty( $assoc['yes'] ),
			static function (): ?string {
				throw new \RuntimeException( __( 'The other site asked for a password; a push is never encrypted.', 'lw-migration' ) );
			},
			ImportCommand::confirm( $assoc, $interactive, __( 'Restore this site there? [y/n]', 'lw-migration' ), $heading ),
			static function ( array $lines ) use ( $show, $heading ): void {
				$show( array_merge( [ $heading ], $lines ) );
			},
			static fn( string $warning ) => Console::warning( $warning )
		);

		return static function ( Job $job ) use ( $answers ): ?array {
			$relayed = clone $job;
			if ( 'confirm' === ( $job->input['kind'] ?? null ) ) {
				$relayed->input = [
					'kind' => 'confirm',
					'data' => RemoteJobStep::plain( (array) ( JobView::of( $job, false, [] )['input']['summary'] ?? [] ) ),
				];
			}

			return $answers->answer( $relayed );
		};
	}

	/**
	 * The outgoing link of the command, when it may push. (--password and
	 * --sites are not declared, so WP-CLI refuses them before this runs.)
	 *
	 * @param array<int, string> $args Link id first (taken off), then --replace values.
	 * @return array{id: string, name: string, address: string, allow: array<int, string>}
	 * @throws \InvalidArgumentException For a link without the push right (and LinkClients::view()
	 *                                   a LinkException GONE for a link that cannot send).
	 */
	private static function link( array &$args ): array {
		$link = LinkClients::fromWordPress()->view( (string) array_shift( $args ) );
		if ( ! in_array( Links::PUSH, $link['allow'], true ) ) {
			throw new \InvalidArgumentException( __( 'This connection does not allow sending this site there.', 'lw-migration' ) );
		}

		return $link;
	}
}

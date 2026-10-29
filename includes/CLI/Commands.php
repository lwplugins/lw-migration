<?php
/**
 * Registers the `wp lw-migration …` commands.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Back up, restore and move this site with .wpress archives.
 */
final class Commands {

	/**
	 * The bare `lw-migration` registration comes first and points at this
	 * class (a command group; its docblock is what `wp help` prints).
	 * Every command runs only in the main site's context of a network.
	 */
	public static function register(): void {
		$guard = [ 'before_invoke' => [ NetworkCli::class, 'guard' ] ];
		WP_CLI::add_command( 'lw-migration', self::class );
		WP_CLI::add_command( 'lw-migration export', ExportCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration import', ImportCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration list', ListCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration browse', BrowseCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration extract', ExtractCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration delete', DeleteCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration schedule', ScheduleCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration backup-dir', BackupDirCommand::class, $guard );
		// The group first: WP-CLI adds a subcommand only to a registered parent.
		WP_CLI::add_command( 'lw-migration backup', BackupCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration backup share', ShareCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration link', LinkCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration push', PushCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration pull', PullCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration reset', ResetCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration destination', DestinationCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration upload', UploadCommand::class, $guard );
		WP_CLI::add_command( 'lw-migration cloud', CloudCommand::class, $guard );
		WP_CLI::add_hook( 'before_invoke:lw-migration destination add', [ DestinationCommand::class, 'refusePrompt' ] );
	}
}

<?php
/**
 * `wp lw-migration pull`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Link\Client;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Copies a connected site onto this site.
 */
final class PullCommand {

	/**
	 * Copies a connected site onto this site: the other site makes a backup,
	 * this site downloads it over the connection and restores it here.
	 * Everything in it replaces the matching parts of this site.
	 *
	 * ## OPTIONS
	 *
	 * <link-id>
	 * : The connection (see `wp lw-migration link list`).
	 *
	 * [--safety-backup]
	 * : Back up this site's database first (the default). Use --no-safety-backup to skip it.
	 *
	 * [--yes]
	 * : Restore without asking (required when not run from a terminal).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration pull 0123456789abcdef --yes --user=admin
	 *
	 * @param array<int, string>   $args  Link id.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		LinkCommand::singleSiteOnly();
		if ( Confirmation::requiresYes( $assoc, Confirmation::stdinIsInteractive() ) ) {
			Console::error( __( 'Pass --yes to import without a prompt.', 'lw-migration' ) );
			return;
		}
		try {
			$link = LinkClients::fromWordPress()->view( (string) ( $args[0] ?? '' ) );
		} catch ( LinkException $e ) {
			Console::error( $e->getMessage() );
			return;
		}
		if ( ! in_array( Links::PULL, $link['allow'], true ) ) {
			Console::error( __( 'This connection does not allow copying that site here.', 'lw-migration' ) );
			return;
		}
		if ( Client::insecure( $link['address'] ) ) {
			LinkCommand::httpWarning();
		}
		$safety  = false !== ( $assoc['safety-backup'] ?? true ); // --no-safety-backup gives false.
		$options = new ImportOptions( ImportOptions::LINK, $link['id'], $safety );
		/* translators: 1: the other site's name, 2: its address */
		$shown = sprintf( __( '%1$s (%2$s) over the connection', 'lw-migration' ), $link['name'], $link['address'] );
		$state = [
			'link' => [
				'id'      => $link['id'],
				'name'    => $link['name'],
				'address' => $link['address'],
			],
		];
		ImportCommand::run( Services::get(), $options, $assoc, $shown, [], $state );
	}
}

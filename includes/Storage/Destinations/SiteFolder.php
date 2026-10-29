<?php
/**
 * The folder of this site at a backup destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use LightweightPlugins\Migration\Backups\BackupName;

defined( 'ABSPATH' ) || exit;

/**
 * One folder per site: the slug its backup names start with.
 */
final class SiteFolder {

	public const PATTERN = '/^[a-z0-9][a-z0-9-]{0,99}\z/';

	public static function name(): string {
		return BackupName::siteSlug( is_multisite() ? network_home_url() : home_url() );
	}

	/**
	 * The folder pinned in the connection when it is a valid slug, else this site's name.
	 *
	 * @param array<string, mixed> $connection Profile connection.
	 */
	public static function of( array $connection ): string {
		$folder = $connection['folder'] ?? '';

		return is_string( $folder ) && 1 === preg_match( self::PATTERN, $folder ) ? $folder : self::name();
	}
}

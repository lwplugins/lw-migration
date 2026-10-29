<?php
/**
 * What the user is told about archive entries an import did not restore.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

/**
 * One summary line per ContentRules skip reason (the counts come from the
 * Content step's cursor); an unknown reason still gets a general line, so no
 * skipped file goes unmentioned. The plugins and themes a network kept
 * (SharedCode::KEPT reasons) are named in one line.
 */
final class SkipMessages {

	/**
	 * @param array<string, int> $skips Reason => count, as the Content step counted them.
	 * @return array<int, string> One line per reason, the kept plugins and themes last in one line.
	 */
	public static function all( array $skips ): array {
		$lines = [];
		$kept  = [];
		$new   = [];
		foreach ( $skips as $reason => $count ) {
			$reason = (string) $reason;
			if ( str_starts_with( $reason, SharedCode::NEW ) ) {
				$new[] = substr( $reason, strlen( SharedCode::NEW ) );
				continue;
			}
			if ( str_starts_with( $reason, SharedCode::KEPT ) ) {
				$kept[] = substr( $reason, strlen( SharedCode::KEPT ) );
				continue;
			}
			$lines[] = self::message( $reason, (int) $count );
		}
		if ( [] !== $new ) {
			/* translators: %s: comma-separated list such as plugins/shop, themes/fresh */
			$lines[] = sprintf( __( 'Not restored, because the network does not have them: %s. Install them on the network if you trust them.', 'lw-migration' ), implode( ', ', $new ) );
		}
		if ( [] !== $kept ) {
			/* translators: %s: comma-separated list such as plugins/akismet, themes/twentytwentysix */
			$lines[] = sprintf( __( 'Kept the network\'s own copies of these plugins and themes (other sites use them); the copies in the archive were not restored: %s.', 'lw-migration' ), implode( ', ', $kept ) );
		}

		return $lines;
	}

	/**
	 * @param string $reason ContentRules skip reason.
	 * @param int    $count  Number of entries skipped for it.
	 */
	public static function message( string $reason, int $count ): string {
		switch ( $reason ) {
			case ContentRules::UNSAFE:
				/* translators: %d: number of files */
				$text = _n( 'Skipped %d entry of the archive that could not be restored safely (outside the site folders or replacing a whole folder).', 'Skipped %d entries of the archive that could not be restored safely (outside the site folders or replacing a whole folder).', $count, 'lw-migration' );
				break;
			case ContentRules::DROPIN:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d drop-in file: it belongs to the old server.', 'Did not restore %d drop-in files: they belong to the old server.', $count, 'lw-migration' );
				break;
			case ContentRules::HOSTING:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d file of the must-use plugins of the old hosting company.', 'Did not restore %d files of the must-use plugins of the old hosting company.', $count, 'lw-migration' );
				break;
			case ContentRules::GENERATED:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d generated file (caches are rebuilt automatically).', 'Did not restore %d generated files (caches are rebuilt automatically).', $count, 'lw-migration' );
				break;
			case ContentRules::OWN:
				/* translators: %d: number of files */
				$text = _n( 'Kept this site\'s copy of LW Migration; %d file of the copy in the archive was not restored.', 'Kept this site\'s copy of LW Migration; %d files of the copy in the archive were not restored.', $count, 'lw-migration' );
				break;
			case ContentRules::CONFIG:
				/* translators: %d: number of files */
				$text = _n( 'Kept this site\'s wp-config.php; %d copy in the archive was not restored (it holds the old server\'s database settings).', 'Kept this site\'s wp-config.php; %d copies in the archive were not restored (they hold the old server\'s database settings).', $count, 'lw-migration' );
				break;
			case ContentRules::STORAGE:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d file into the backup and storage folders of this site.', 'Did not restore %d files into the backup and storage folders of this site.', $count, 'lw-migration' );
				break;
			case SharedCode::SHARED:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d must-use plugin or wp-content root file: on a network it would affect every site.', 'Did not restore %d must-use plugin or wp-content root files: on a network they would affect every site.', $count, 'lw-migration' );
				break;
			case SharedCode::EXISTING:
				/* translators: %d: number of files */
				$text = _n( 'Kept %d file the network already had in its shared wp-content folders; the copy in the archive was not restored.', 'Kept %d files the network already had in its shared wp-content folders; the copies in the archive were not restored.', $count, 'lw-migration' );
				break;
			case SharedCode::NEW_CODE:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d new PHP or server configuration file into the shared wp-content folders: on a network it would run for every site.', 'Did not restore %d new PHP or server configuration files into the shared wp-content folders: on a network they would run for every site.', $count, 'lw-migration' );
				break;
			case ContentRules::PROTECTED_TARGET:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d file that would have reached the must-use plugins folder from another folder.', 'Did not restore %d files that would have reached the must-use plugins folder from another folder.', $count, 'lw-migration' );
				break;
			default:
				/* translators: %d: number of files */
				$text = _n( 'Did not restore %d file of the archive.', 'Did not restore %d files of the archive.', $count, 'lw-migration' );
		}

		return sprintf( $text, $count );
	}
}

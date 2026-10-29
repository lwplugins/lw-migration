<?php
/**
 * The lines `wp lw-migration reset` prints before and after a reset.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Reset\ResetType;

defined( 'ABSPATH' ) || exit;

/**
 * Text only (the command prints the lines): from the summary, what the
 * chosen reset removes and what stays; from the job's result, what it did.
 * Never a server path (neither has one).
 */
final class ResetOutput {

	/**
	 * @param string               $type    One of ResetType::ALL.
	 * @param array<string, mixed> $summary ResetSummary::of().
	 * @return array<int, string>
	 */
	public static function summary( string $type, array $summary ): array {
		$types = (array) ( $summary['types'] ?? [] );
		$lines = [];
		if ( ResetType::PLUGINS === $type || ResetType::FULL === $type ) {
			$lines = array_merge( $lines, self::plugins( (array) ( $types['plugins'] ?? [] ), (array) ( $summary['keptPlugins'] ?? [] ), (array) ( $summary['dropIns'] ?? [] ) ) );
		}
		if ( ResetType::touchesCode( $type ) && ResetType::PLUGINS !== $type ) {
			$lines = array_merge( $lines, self::themes( (array) ( $types['themes'] ?? [] ) ) );
		}
		if ( ResetType::touchesMedia( $type ) ) {
			$attachments = $types['media']['attachments'] ?? null;
			$lines[]     = null === $attachments
				? __( 'Every media item of every site and every file in the uploads folder will be deleted.', 'lw-migration' )
				/* translators: %d: number of media items */
				: sprintf( __( 'Every media item (%d) and every file in the uploads folder will be deleted.', 'lw-migration' ), (int) $attachments );
			$lines[] = __( 'Files other plugins keep in the uploads folder (caches, form uploads, downloads) go too.', 'lw-migration' );
		}
		if ( ResetType::touchesDatabase( $type ) ) {
			$lines = array_merge( $lines, self::database( (array) ( $types['database'] ?? [] ) ) );
		}

		return $lines;
	}

	/**
	 * @param array<string, mixed> $result ResetResult::of().
	 * @return array<int, string>
	 */
	public static function result( array $result ): array {
		$lines  = [];
		$backup = $result['backup'] ?? null;
		if ( is_array( $backup ) ) {
			/* translators: %s: backup file name */
			$lines[] = sprintf( __( 'Backup before the reset: %s', 'lw-migration' ), (string) $backup['name'] );
		}
		foreach ( [ 'plugins', 'themes' ] as $kind ) {
			$removed = $result[ $kind ] ?? null;
			if ( is_array( $removed ) ) {
				$lines[] = self::removed( $kind, (int) $removed['count'], array_map( 'strval', (array) $removed['names'] ) ) . self::leftovers( (int) ( $removed['leftovers'] ?? 0 ) );
			}
		}
		$media = $result['media'] ?? null;
		if ( is_array( $media ) ) {
			$lines[] = sprintf(
				/* translators: 1: e.g. "3 items", 2: e.g. "7 files" */
				__( 'Media removed: %1$s and %2$s.', 'lw-migration' ),
				/* translators: %d: number of media items */
				sprintf( _n( '%d item', '%d items', (int) $media['attachments'], 'lw-migration' ), (int) $media['attachments'] ),
				/* translators: %d: number of files */
				sprintf( _n( '%d file', '%d files', (int) $media['files'], 'lw-migration' ), (int) $media['files'] )
			);
		}
		$database = $result['database'] ?? null;
		if ( is_array( $database ) ) {
			$retired = (int) $database['retired'];
			/* translators: 1: number of core tables, 2: number of other tables */
			$lines[] = sprintf( _n( 'Database replaced: %1$d core tables fresh, %2$d other table removed.', 'Database replaced: %1$d core tables fresh, %2$d other tables removed.', $retired, 'lw-migration' ), (int) $database['replaced'], $retired );
		}
		$dropIns = array_map( 'strval', (array) ( $result['dropIns'] ?? [] ) );
		if ( [] !== $dropIns ) {
			$lines[] = self::dropIns( $dropIns );
		}

		return $lines;
	}

	/**
	 * @param array<string, mixed>              $plugins Summary of the plugins part.
	 * @param array<int, array<string, string>> $kept    keptPlugins.
	 * @param array<int, string>                $dropIns Drop-ins present.
	 * @return array<int, string>
	 */
	private static function plugins( array $plugins, array $kept, array $dropIns ): array {
		$count = (int) ( $plugins['count'] ?? 0 );
		$lines = [
			0 === $count
				? __( 'No plugin will be deleted.', 'lw-migration' )
				/* translators: 1: number of plugins, 2: their names */
				: sprintf( _n( '%1$d plugin will be deleted: %2$s.', '%1$d plugins will be deleted: %2$s.', $count, 'lw-migration' ), $count, self::names( (array) ( $plugins['names'] ?? [] ), (int) ( $plugins['more'] ?? 0 ) ) ),
		];
		foreach ( $kept as $plugin ) {
			/* translators: 1: plugin file, 2: what loads it, e.g. db.php */
			$lines[] = sprintf( __( '%1$s stays: %2$s loads it directly.', 'lw-migration' ), (string) ( $plugin['plugin'] ?? '' ), (string) ( $plugin['by'] ?? '' ) );
		}
		if ( [] !== $dropIns ) {
			$lines[] = self::dropIns( array_map( 'strval', $dropIns ) );
		}

		return $lines;
	}

	/**
	 * @param array<string, mixed> $themes Summary of the themes part.
	 * @return array<int, string>
	 */
	private static function themes( array $themes ): array {
		$target = (array) ( $themes['target'] ?? [] );
		$count  = (int) ( $themes['count'] ?? 0 );
		/* translators: 1: theme name, 2: number of themes */
		$lines = [ sprintf( _n( 'The theme %1$s will be active; %2$d theme will be deleted.', 'The theme %1$s will be active; %2$d themes will be deleted.', $count, 'lw-migration' ), (string) ( $target['name'] ?? '' ), $count ) ];
		if ( 'download' === ( $target['source'] ?? '' ) ) {
			$lines[] = __( 'The default theme is not installed: it is downloaded from wordpress.org first.', 'lw-migration' );
		} elseif ( 'stub' === ( $target['source'] ?? '' ) ) {
			$lines[] = __( 'No default theme can be installed, so a minimal blank theme is written instead.', 'lw-migration' );
		}
		if ( $count > 0 ) {
			/* translators: %s: theme names */
			$lines[] = sprintf( __( 'Themes that go: %s.', 'lw-migration' ), self::names( (array) ( $themes['names'] ?? [] ), (int) ( $themes['more'] ?? 0 ) ) );
		}

		return $lines;
	}

	/**
	 * @param array<string, mixed> $database Summary of the database part.
	 * @return array<int, string>
	 */
	private static function database( array $database ): array {
		$tables  = (array) ( $database['tables'] ?? [] );
		$retired = (int) ( $tables['retired'] ?? 0 );
		/* translators: 1: your login, 2: number of other users, 3: number of tables that are not core tables */
		$lines = [ sprintf( _n( 'The database becomes a fresh install; you (%1$s) stay, every other user (%2$d), all content and %3$d other table go.', 'The database becomes a fresh install; you (%1$s) stay, every other user (%2$d), all content and %3$d other tables go.', $retired, 'lw-migration' ), (string) ( $database['user'] ?? '' ), (int) ( $database['otherUsers'] ?? 0 ), $retired ) ];
		if ( $retired > 0 ) {
			/* translators: %s: table names */
			$lines[] = sprintf( __( 'Tables that go: %s.', 'lw-migration' ), self::names( (array) ( $tables['retiredNames'] ?? [] ), (int) ( $tables['more'] ?? 0 ) ) );
		}
		$kept = (int) ( $tables['kept'] ?? 0 );
		if ( $kept > 0 ) {
			/* translators: %d: number of tables */
			$lines[] = sprintf( _n( '%d table of another installation in this database stays.', '%d tables of another installation in this database stay.', $kept, 'lw-migration' ), $kept );
		}
		if ( ! empty( $database['prefixWarning'] ) ) {
			$lines[] = __( 'This site\'s table prefix does not end in an underscore, so tables of other software whose names merely start with it go too.', 'lw-migration' );
		}
		$lines[]     = __( 'Your login, password, profile and sessions stay; your application passwords and other personal settings are removed.', 'lw-migration' );
		$lines[]     = __( 'LW Migration stays active with its settings and transfer links.', 'lw-migration' );
		$connections = (int) ( $database['connections'] ?? 0 );
		$schedules   = (int) ( $database['schedules'] ?? 0 );
		$lines[]     = sprintf(
			/* translators: 1: e.g. "2 connections", 2: e.g. "1 schedule" */
			__( '%1$s and %2$s stay; connections made by other admins stop working.', 'lw-migration' ),
			/* translators: %d: number of connections */
			sprintf( _n( '%d connection', '%d connections', $connections, 'lw-migration' ), $connections ),
			/* translators: %d: number of schedules */
			sprintf( _n( '%d schedule', '%d schedules', $schedules, 'lw-migration' ), $schedules )
		);

		return $lines;
	}

	/**
	 * @param string             $kind  'plugins' or 'themes'.
	 * @param int                $count How many went.
	 * @param array<int, string> $names The first names.
	 */
	private static function removed( string $kind, int $count, array $names ): string {
		$list = self::names( $names, max( 0, $count - count( $names ) ) );
		if ( 'plugins' === $kind ) {
			/* translators: 1: number of plugins, 2: their names */
			return 0 === $count ? __( 'No plugin was deleted.', 'lw-migration' ) : sprintf( _n( '%1$d plugin deleted: %2$s', '%1$d plugins deleted: %2$s', $count, 'lw-migration' ), $count, $list );
		}

		/* translators: 1: number of themes, 2: their names */
		return 0 === $count ? __( 'No theme was deleted.', 'lw-migration' ) : sprintf( _n( '%1$d theme deleted: %2$s', '%1$d themes deleted: %2$s', $count, 'lw-migration' ), $count, $list );
	}

	/**
	 * @param int $leftovers How many of the deleted plugins or themes kept files.
	 * @return string The note after the count ('' = none).
	 */
	private static function leftovers( int $leftovers ): string {
		/* translators: %d: how many of the deleted plugins or themes */
		return $leftovers > 0 ? ' ' . sprintf( __( '(%d of them kept files that could not be removed; see the warnings)', 'lw-migration' ), $leftovers ) : '';
	}

	/**
	 * @param array<int, string> $dropIns Drop-in names.
	 */
	private static function dropIns( array $dropIns ): string {
		/* translators: %s: drop-in file names, e.g. object-cache.php */
		return sprintf( __( 'Drop-ins stay: %s. If one of these belonged to a deleted plugin, remove it by hand. A page cache may still serve the old pages: empty it.', 'lw-migration' ), implode( ', ', $dropIns ) );
	}

	/**
	 * @param array<int, mixed> $names Names shown.
	 * @param int               $more  How many more there are.
	 */
	private static function names( array $names, int $more ): string {
		$list = implode( ', ', array_map( 'strval', $names ) );

		/* translators: 1: names, 2: how many more */
		return $more > 0 ? sprintf( __( '%1$s and %2$d more', 'lw-migration' ), $list, $more ) : $list;
	}
}

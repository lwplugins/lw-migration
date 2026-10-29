<?php
/**
 * The words of the reset: names, titles, backup label and refusals.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the translated texts the server sends; the client acts on the
 * reason codes, never on these texts.
 */
final class ResetTexts {

	/**
	 * @param string $type One of ResetType::ALL.
	 * @return string Lower-case name of what the reset removes.
	 */
	public static function kind( string $type ): string {
		$kinds = [
			ResetType::PLUGINS  => __( 'plugins', 'lw-migration' ),
			ResetType::THEMES   => __( 'themes', 'lw-migration' ),
			ResetType::MEDIA    => __( 'media', 'lw-migration' ),
			ResetType::DATABASE => __( 'database', 'lw-migration' ),
			ResetType::FULL     => __( 'everything', 'lw-migration' ),
		];

		return $kinds[ $type ] ?? $type;
	}

	/**
	 * @param string $type One of ResetType::ALL.
	 */
	public static function title( string $type ): string {
		$titles = [
			ResetType::PLUGINS  => __( 'Delete plugins', 'lw-migration' ),
			ResetType::THEMES   => __( 'Reset themes', 'lw-migration' ),
			ResetType::MEDIA    => __( 'Clean up media', 'lw-migration' ),
			ResetType::DATABASE => __( 'Reset the database', 'lw-migration' ),
			ResetType::FULL     => __( 'Reset everything', 'lw-migration' ),
		];

		return $titles[ $type ] ?? $type;
	}

	/**
	 * @param string $type One of ResetType::ALL.
	 */
	public static function backupLabel( string $type ): string {
		/* translators: %s: what the reset removes, e.g. "plugins" or "everything". */
		return sprintf( __( 'Before reset — %s', 'lw-migration' ), self::kind( $type ) );
	}

	/**
	 * @param string $reason     A ResetRefused reason.
	 * @param int    $retryAfter Seconds of a lock (LOCKED).
	 * @param string $detail     DATABASE_SETUP: the setup code ('sqlite', 'drop_in', 'custom_users', 'table_prefix', 'core_view:<table>'); AS_ROOT: the site owner's user name ('' = unknown).
	 */
	public static function refusal( string $reason, int $retryAfter = 0, string $detail = '' ): string {
		if ( ResetRefused::LOCKED === $reason ) {
			$minutes = max( 1, (int) ceil( $retryAfter / 60 ) );

			/* translators: %d: minutes until the next try. */
			return sprintf( _n( 'Too many wrong passwords. Try again in %d minute.', 'Too many wrong passwords. Try again in %d minutes.', $minutes, 'lw-migration' ), $minutes );
		}
		if ( ResetRefused::DATABASE_SETUP === $reason ) {
			return self::databaseSetup( $detail );
		}
		if ( ResetRefused::AS_ROOT === $reason ) {
			/* translators: %s: the site owner's user name, e.g. www-data, or the placeholder <owner> */
			return sprintf( __( 'This reset changes files, so it never runs as root. Run it as the site\'s owner, for example: sudo -u %s wp lw-migration reset ...', 'lw-migration' ), '' !== $detail ? $detail : '<owner>' );
		}
		$texts = [
			ResetRefused::PASSWORD       => __( 'This is not your password.', 'lw-migration' ),
			ResetRefused::UNRECORDED     => __( 'The password check cannot be recorded right now, so the reset did not start. Try again in a moment.', 'lw-migration' ),
			ResetRefused::NETWORK        => __( 'On a network only plugins, themes and media can be reset in this version.', 'lw-migration' ),
			ResetRefused::FILE_MODS      => __( 'This site does not allow plugins and themes to be changed (DISALLOW_FILE_MODS).', 'lw-migration' ),
			ResetRefused::NOT_WRITABLE   => __( 'The folder this reset would change cannot be written by the web server.', 'lw-migration' ),
			ResetRefused::UPLOADS_UNSAFE => __( 'The uploads folder holds the WordPress, content, plugins or themes folder, so emptying it would delete the site. Check the UPLOADS setting.', 'lw-migration' ),
			ResetRefused::NO_USER        => __( 'Log in as an administrator to reset the site.', 'lw-migration' ),
			ResetRefused::CONFIRM        => __( 'Type the site address exactly as shown.', 'lw-migration' ),
			ResetRefused::NO_ADDRESS     => __( 'This site has no address that can be typed to confirm (Settings > General), so the database cannot be reset here.', 'lw-migration' ),
		];

		return $texts[ $reason ] ?? $texts[ ResetRefused::NO_USER ];
	}

	/**
	 * Warnings for the folders a reset left inside something it deleted.
	 *
	 * @param string                                                   $name    Plugin file, theme or folder the eraser emptied.
	 * @param array{sealed: int, core: int, device: int, changed: int} $reasons From TreeEraser::skippedFor().
	 * @return array<int, string>
	 */
	public static function leftFolders( string $name, array $reasons ): array {
		$texts = [];
		if ( $reasons['sealed'] > 0 ) {
			/* translators: 1: plugin file, theme or folder, 2: number of folders */
			$texts[] = sprintf( _n( '%1$s: %2$d folder holding backups was kept.', '%1$s: %2$d folders holding backups were kept.', $reasons['sealed'], 'lw-migration' ), $name, $reasons['sealed'] );
		}
		if ( $reasons['core'] > 0 ) {
			/* translators: 1: plugin file, theme or folder, 2: number of folders */
			$texts[] = sprintf( _n( '%1$s: %2$d folder of the site itself (WordPress, content, plugins or themes) was left as it is.', '%1$s: %2$d folders of the site itself (WordPress, content, plugins or themes) were left as they are.', $reasons['core'], 'lw-migration' ), $name, $reasons['core'] );
		}
		if ( $reasons['device'] > 0 ) {
			/* translators: 1: plugin file, theme or folder, 2: number of folders */
			$texts[] = sprintf( _n( '%1$s: %2$d folder on another disk was left as it is.', '%1$s: %2$d folders on another disk were left as they are.', $reasons['device'], 'lw-migration' ), $name, $reasons['device'] );
		}
		if ( $reasons['changed'] > 0 ) {
			/* translators: 1: plugin file, theme or folder, 2: number of folders */
			$texts[] = sprintf( _n( '%1$s: %2$d folder changed while it was being deleted, so it was left as it is.', '%1$s: %2$d folders changed while they were being deleted, so they were left as they are.', $reasons['changed'], 'lw-migration' ), $name, $reasons['changed'] );
		}

		return $texts;
	}

	/**
	 * @param string $detail Setup code, 'core_view:<table>' for a view.
	 */
	private static function databaseSetup( string $detail ): string {
		[ $setup, $table ] = array_pad( explode( ':', $detail, 2 ), 2, '' );
		if ( 'sqlite' === $setup ) {
			return __( 'This site runs on SQLite; the database reset works with MySQL and MariaDB only.', 'lw-migration' );
		}
		if ( 'table_prefix' === $setup ) {
			return __( 'This site\'s table prefix is empty or shaped like the temporary tables of LW Migration, so the database cannot be reset here.', 'lw-migration' );
		}
		if ( 'custom_users' === $setup ) {
			return __( 'This site shares its users table with another installation (CUSTOM_USER_TABLE), so the database cannot be reset here.', 'lw-migration' );
		}
		if ( 'core_view' === $setup ) {
			/* translators: %s: the table name that is a view. */
			return sprintf( __( 'A core table of this site is a database view (%s), so the database cannot be reset here.', 'lw-migration' ), $table );
		}

		return __( 'A database drop-in (db.php) replaces the standard connection, so the database cannot be reset here.', 'lw-migration' );
	}
}

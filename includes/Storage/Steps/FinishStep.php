<?php
/**
 * Finish step: switches the setting to the new folder and tidies the old one.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Storage\MarkedFolder;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * The setting switches by compare-and-swap against the one the move started
 * with (a re-run that finds the end state already stored passes); never while
 * a backup is still in the old folder. Then an old chosen folder (never the
 * default one) is tidied: only a folder marked as ours, and only our own files
 * in it.
 */
final class FinishStep implements StepInterface {

	/**
	 * Who may own a chosen folder: fn(): array<int, int> (StorageDir::owners()).
	 *
	 * @var \Closure
	 */
	private \Closure $owners;

	/**
	 * @param object|null   $db     Database object (tests).
	 * @param \Closure|null $owners fn(): array<int, int>; default [] (the PHP user).
	 */
	public function __construct( private ?object $db = null, ?\Closure $owners = null ) {
		$this->owners = $owners ?? static fn(): array => [];
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$during = BackupDir::parse( (string) ( $job->options['during'] ?? '' ) );
		$after  = BackupDir::parse( (string) ( $job->options['after'] ?? '' ) );
		$from   = (string) ( $job->options['fromDir'] ?? '' );
		if ( [] !== MoveStep::listed( $from ) ) {
			throw new JobException( __( 'Some backups are still in the old folder. Start the move again.', 'lw-migration' ) );
		}
		if ( ! BackupDir::replace( $during, $after, $this->db ) ) {
			throw new JobException( self::settingChanged() );
		}
		if ( empty( $job->options['fromDefault'] ) ) {
			foreach ( $this->tidy( $from, $after->marker ) as $warning ) {
				$job->addWarning( $warning );
			}
		}
		$count = (int) ( $job->state['moved']['count'] ?? 0 );

		/* translators: %s: number of backups */
		return StepResult::done( sprintf( _n( '%s backup moved.', '%s backups moved.', $count, 'lw-migration' ), number_format_i18n( $count ) ) );
	}

	/** The setting no longer marks this move. */
	public static function settingChanged(): string {
		return __( 'The backups folder setting changed while the backups were moved. Check it in Settings.', 'lw-migration' );
	}

	/**
	 * Only a folder marked as ours, and only our own files in it: stale temps
	 * always; the deny files whose content is ours, the marker and the folder
	 * itself only when nothing else is left (otherwise it stays as it is,
	 * protected, with a warning). When the folder cannot be removed after all
	 * (a file appeared meanwhile), its deny files are created again.
	 *
	 * @param string $dir    Old chosen folder.
	 * @param string $marker This site's marker.
	 * @return array<int, string> Warnings.
	 */
	private function tidy( string $dir, string $marker ): array {
		clearstatcache( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( '' === $marker || @is_link( $dir ) || ! @is_dir( $dir ) || @realpath( $dir ) !== $dir || BackupDirRules::markerOf( $dir ) !== $marker ) {
			return [];
		}
		foreach ( self::items( $dir ) as $item ) {
			if ( TempName::is( $item ) ) {
				Files::removeFile( $dir . '/' . $item );
			}
		}
		if ( [] !== self::others( $dir ) ) {
			return [ self::kept() ];
		}
		StorageDir::unprotect( $dir );
		Files::removeFile( $dir . '/' . BackupDirRules::MARKER );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a folder that is not empty after all stays; it is protected again below.
		if ( @rmdir( $dir ) ) {
			return [];
		}

		return [ $this->protectAgain( $dir ) ];
	}

	/**
	 * @param string $dir Folder that could not be removed.
	 * @return string The warning, with the reason.
	 */
	private function protectAgain( string $dir ): string {
		if ( ! MarkedFolder::asRoot( ( $this->owners )() ) ) {
			try {
				StorageDir::protectNew( $dir );
			} catch ( FolderRefusedException $e ) {
				unset( $e ); // The warning below says the folder was kept; nothing more can be done here.
			}
		}

		return __( 'The old backups folder could not be removed (a file appeared in it, or the folder above it may not be changed), so it was kept and protected again. No backups are left in it.', 'lw-migration' );
	}

	private static function kept(): string {
		return __( 'The old backups folder still holds other files, so it was kept. No backups are left in it.', 'lw-migration' );
	}

	/**
	 * What is in the folder besides our marker and our own deny files.
	 *
	 * @param string $dir Folder.
	 * @return array<int, string>
	 */
	private static function others( string $dir ): array {
		$deny = StorageDir::chosenDenyFiles();

		return array_values(
			array_filter(
				self::items( $dir ),
				static fn( string $item ): bool => BackupDirRules::MARKER !== $item && ! ( isset( $deny[ $item ] ) && StorageDir::isOurFile( $dir . '/' . $item, $deny[ $item ] ) )
			)
		);
	}

	/**
	 * @param string $dir Folder.
	 * @return array<int, string> Names without . and ..
	 */
	private static function items( string $dir ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder reads as holding something.
		$items = @scandir( $dir );

		return false === $items ? [ '?' ] : array_values( array_diff( $items, [ '.', '..' ] ) );
	}
}

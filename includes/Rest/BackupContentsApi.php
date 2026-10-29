<?php
/**
 * What one backup holds, for the Backups tab's detail panel.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Backups\ContentsCache;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Import\ArchiveInventory;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\TimeDeadline;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. The backup is named like on every backup route (folder and
 * file name, BackupsApi::find: nothing outside the listed folders). Its
 * parts come from the entry headers (ArchiveInventory, the restore check's
 * walk: nothing is decoded or extracted), kept per file Fingerprint
 * (ContentsCache), so the next look at the same file opens nothing. One
 * request reads headers for at most BUDGET seconds; a walk that did not
 * reach the end answers `partial: true` (parts not known yet are null), is
 * kept with its position, and the next request goes on from there.
 */
final class BackupContentsApi {

	/** Seconds one request may spend reading headers. */
	public const BUDGET = 2.0;

	/**
	 * Gives the time budget of one request.
	 *
	 * @var \Closure
	 */
	private \Closure $deadline;

	/**
	 * @param BackupsApi    $backups   Finds the named backup.
	 * @param string        $ownPlugin This plugin's basename (its folder is not counted, like in the restore check).
	 * @param \Closure|null $deadline  fn(): Deadline (default: BUDGET seconds from the call).
	 */
	public function __construct( private BackupsApi $backups, private string $ownPlugin, ?\Closure $deadline = null ) {
		$this->deadline = $deadline ?? static fn(): Deadline => new TimeDeadline( self::BUDGET );
	}

	/**
	 * @param array<string, mixed> $params {folder, name}.
	 * @return array<string, mixed> {parts, encrypted, expandedSize, partial}.
	 * @throws RestException 404 when the backup is not there (any more), 422 when it is no readable archive.
	 */
	public function contents( array $params ): array {
		$backup      = $this->backups->find( $params );
		$fingerprint = Fingerprint::of( $backup->path );
		if ( '' === $fingerprint ) {
			throw RestException::backupMissing();
		}
		$cached = ContentsCache::get( $backup->source, $backup->name, $fingerprint );
		if ( null !== $cached && ! isset( $cached['walk'] ) && is_array( $cached['contents'] ?? null ) ) {
			return $cached['contents'];
		}
		try {
			$package   = PackageCodec::read( $backup->path );
			$inventory = ArchiveInventory::fromArray( is_array( $cached['walk'] ?? null ) ? $cached['walk'] : [] );
			$reader    = new Reader( $backup->path );
			try {
				$inventory->walk( $reader, ( $this->deadline )(), $this->ownPlugin );
			} finally {
				$reader->close();
			}
		} catch ( ArchiveException $e ) {
			if ( ! is_file( $backup->path ) ) {
				throw RestException::backupMissing(); // Gone while it was read.
			}
			throw RestException::notReadable();
		}
		$finished = $inventory->isFinished();
		$contents = [
			'parts'        => $inventory->parts( $package ),
			'encrypted'    => $package->isEncrypted(),
			'expandedSize' => $inventory->expandedSize( $package ),
			'partial'      => ! $finished,
		];
		ContentsCache::put( $backup->source, $backup->name, $fingerprint, [ 'contents' => $contents ] + ( $finished ? [] : [ 'walk' => $inventory->toArray() ] ) );

		return $contents;
	}
}

<?php
/**
 * Where an import comes from.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Link\Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in job options. The password is never part of it.
 */
final class ImportOptions {

	public const FILE = 'file';

	public const BACKUP = 'backup';

	public const URL = 'url';

	/** An archive the browser uploads into the job folder (the location is its display name). */
	public const UPLOAD = 'upload';

	/** The archive of a connected site (a pull; the location is the link id). */
	public const LINK = 'link';

	/** A backup at a cloud destination (the location is its name; the destination is in state.cloudSource). */
	public const CLOUD = 'cloud';

	/**
	 * @param string $source       FILE, BACKUP, URL, UPLOAD, LINK or CLOUD.
	 * @param string $location     File path, backup name, http(s) address, uploaded file name, link id or remote backup name.
	 * @param bool   $safetyBackup Whether a database backup of this site is made first.
	 * @param string $folder       For BACKUP: Backup::OWN, Backup::EXTERNAL or '' (our folder first).
	 */
	public function __construct(
		public string $source = self::FILE,
		public string $location = '',
		public bool $safetyBackup = true,
		public string $folder = ''
	) {
	}

	/**
	 * @param array<string, mixed> $a Saved options (a password key, if any, is ignored).
	 * @return self
	 * @throws \InvalidArgumentException On an unknown source or folder, an empty or non-string location, an unusable address, upload name or cloud backup name.
	 */
	public static function fromArray( array $a ): self {
		$source   = isset( $a['source'] ) && is_string( $a['source'] ) ? $a['source'] : '';
		$location = isset( $a['location'] ) && is_string( $a['location'] ) ? $a['location'] : '';
		$folder   = isset( $a['folder'] ) && is_string( $a['folder'] ) ? $a['folder'] : '';
		if ( ! in_array( $source, [ self::FILE, self::BACKUP, self::URL, self::UPLOAD, self::LINK, self::CLOUD ], true ) ) {
			/* translators: %s: source kind */
			throw new \InvalidArgumentException( sprintf( __( 'Unknown import source: %s', 'lw-migration' ), $source ) );
		}
		if ( '' === $location ) {
			throw new \InvalidArgumentException( __( 'Tell what to import: a backup name, a .wpress file or an address.', 'lw-migration' ) );
		}
		if ( self::URL === $source ) {
			self::assertUrl( $location );
		}
		if ( self::UPLOAD === $source && ! BackupName::isValid( $location ) ) {
			throw new \InvalidArgumentException( __( 'An uploaded archive needs a plain .wpress file name.', 'lw-migration' ) );
		}
		if ( self::CLOUD === $source && ! BackupName::isValid( $location ) ) {
			throw new \InvalidArgumentException( __( 'A cloud backup needs a plain .wpress file name.', 'lw-migration' ) );
		}
		if ( self::LINK === $source && 1 !== preg_match( Signer::LINK_PATTERN, $location ) ) {
			throw new \InvalidArgumentException( __( 'Unknown connection.', 'lw-migration' ) );
		}
		if ( ! in_array( $folder, [ '', Backup::OWN, Backup::EXTERNAL ], true ) ) {
			/* translators: %s: folder kind */
			throw new \InvalidArgumentException( sprintf( __( 'Unknown backup folder: %s', 'lw-migration' ), $folder ) );
		}
		$safety = isset( $a['safetyBackup'] ) ? filter_var( $a['safetyBackup'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;

		return new self( $source, $location, null === $safety ? true : (bool) $safety, self::BACKUP === $source ? $folder : '' );
	}

	/**
	 * An http(s) address with a host and no whitespace or control characters.
	 *
	 * @param string $url Address.
	 * @throws \InvalidArgumentException When it is not one.
	 */
	private static function assertUrl( string $url ): void {
		if ( 1 !== preg_match( '#^https?://#i', $url ) ) {
			throw new \InvalidArgumentException( __( 'Only http and https addresses can be imported.', 'lw-migration' ) );
		}
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing, no WordPress quirks needed.
		if ( 1 === preg_match( '/[\s\x00-\x1F\x7F]/', $url ) || ! is_string( $host ) || '' === $host ) {
			throw new \InvalidArgumentException( __( 'This address cannot be imported: it has no host name or contains spaces or control characters.', 'lw-migration' ) );
		}
	}

	/**
	 * @return array{source: string, location: string, safetyBackup: bool, folder?: string}
	 */
	public function toArray(): array {
		$data = [
			'source'       => $this->source,
			'location'     => $this->location,
			'safetyBackup' => $this->safetyBackup,
		];
		if ( '' !== $this->folder ) {
			$data['folder'] = $this->folder;
		}

		return $data;
	}
}

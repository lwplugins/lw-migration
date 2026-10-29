<?php
/**
 * Codec for reading an archive, from its package.json.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Package;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use LightweightPlugins\Migration\Archive\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Reads package.json first: it is never compressed or encrypted.
 */
final class PackageCodec {

	private const PACKAGE_MAX_BYTES = 1048576;

	/**
	 * @param string $archivePath Archive.
	 * @return PackageConfig
	 * @throws InvalidArchiveException When the archive is incomplete or does not start with package.json
	 *                                 (an unreadable archive throws its parent ArchiveException).
	 */
	public static function read( string $archivePath ): PackageConfig {
		$reader = new Reader( $archivePath );
		try {
			$reader->validate();
			$first = $reader->firstEntry();
			if ( null === $first ) {
				throw new InvalidArchiveException( 'The archive does not start with package.json.' );
			}

			return PackageConfig::fromJson( $reader->readContents( $first, self::PACKAGE_MAX_BYTES ) );
		} finally {
			$reader->close();
		}
	}

	/**
	 * @param PackageConfig $package  The archive's package.json.
	 * @param string|null   $password Password for an encrypted archive.
	 * @return ChunkCodec
	 * @throws ArchiveException Without a password for an encrypted archive.
	 * @throws WrongPasswordException When the password is wrong.
	 */
	public static function forPackage( PackageConfig $package, ?string $password ): ChunkCodec {
		$cipher = null;
		if ( $package->isEncrypted() ) {
			if ( null === $password || '' === $password ) {
				throw new ArchiveException( 'The archive is encrypted: a password is required.' );
			}
			$cipher = new Cipher( $password );
			// Without a signature a wrong password surfaces later, as a decrypt or checksum error.
			$signature = $package->encryptedSignature();
			if ( '' !== $signature && ! $cipher->verifySignature( $signature ) ) {
				throw new WrongPasswordException( 'The password is wrong.' );
			}
		}

		return new ChunkCodec( $package->compression(), $cipher );
	}
}

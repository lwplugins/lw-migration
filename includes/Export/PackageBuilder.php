<?php
/**
 * Builds package.json for a backup.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Same keys and value types as .wpress importers expect (spec 5.6). Only format
 * fields are written, never other parts of the site snapshot.
 */
final class PackageBuilder {

	/**
	 * Also throws ArchiveException when a value cannot be encoded as JSON (invalid UTF-8).
	 *
	 * @param Site          $site      Site snapshot.
	 * @param ExportOptions $options   Backup options.
	 * @param Cipher|null   $cipher    Cipher when the backup is encrypted.
	 * @param string        $htaccess  Raw .htaccess contents.
	 * @param string        $webConfig Raw web.config contents.
	 * @param string        $dbVersion Database server version.
	 * @return PackageConfig
	 * @throws ExportException When the encrypt option and the cipher disagree.
	 */
	public static function build( Site $site, ExportOptions $options, ?Cipher $cipher, string $htaccess, string $webConfig, string $dbVersion ): PackageConfig {
		if ( ( null !== $cipher ) !== $options->encrypt ) {
			throw new ExportException( $options->encrypt ? 'The backup is set to be encrypted but no password was given.' : 'A password was given but the backup is not set to be encrypted.' );
		}
		$c               = self::head( $site, $options );
		$c['Plugin']     = [ 'Version' => LW_MIGRATION_VERSION ];
		$c['WordPress']  = [
			'Version'    => $site->wpVersion,
			'Absolute'   => $site->abspath,
			'Content'    => $site->contentDir,
			'Plugins'    => $site->pluginsDir,
			'Themes'     => $site->themeRoots(),
			'Uploads'    => $site->uploadsDir,
			'UploadsURL' => $site->uploadsUrl,
		];
		$c['Database']   = self::database( $site, $options, $dbVersion );
		$c['PHP']        = [
			'Version' => PHP_VERSION,
			'System'  => PHP_OS,
			'Integer' => PHP_INT_SIZE,
		];
		$c['Plugins']    = array_values( array_diff( $site->activePlugins, [ $site->ownPlugin ] ) );
		$c['Template']   = $site->template;
		$c['Stylesheet'] = $site->stylesheet;
		$c['Uploads']    = $site->uploadPath;
		$c['UploadsURL'] = $site->uploadUrlPath;
		$c['Server']     = [
			'.htaccess'  => base64_encode( $htaccess ),
			'web.config' => base64_encode( $webConfig ),
		];
		if ( null !== $cipher ) {
			$c['Encrypted']          = true;
			$c['EncryptedSignature'] = $cipher->signature();
		}
		if ( '' !== $options->compression ) {
			$c['Compression'] = [
				'Enabled' => true,
				'Type'    => $options->compression,
			];
		}

		$package = PackageConfig::fromArray( $c );
		// Fail here, not when the step writes the file, if a value is not valid UTF-8.
		$package->toJson();

		return $package;
	}

	/**
	 * Addresses, replace pairs and the No* flags (only the ones that are set).
	 *
	 * @param Site          $site    Site snapshot.
	 * @param ExportOptions $options Backup options.
	 * @return array<string, mixed>
	 */
	private static function head( Site $site, ExportOptions $options ): array {
		$c = [
			'SiteURL' => $site->siteUrl,
			'HomeURL' => $site->homeUrl,
		];
		if ( '' !== $site->internalSiteUrl ) {
			$c['InternalSiteURL'] = $site->internalSiteUrl;
		}
		if ( '' !== $site->internalHomeUrl ) {
			$c['InternalHomeURL'] = $site->internalHomeUrl;
		}
		foreach ( $options->replace as [ $old, $new ] ) {
			$c['Replace']['OldValues'][] = $old;
			$c['Replace']['NewValues'][] = $new;
		}
		foreach ( ExportOptions::FLAGS as $key => $prop ) {
			if ( $options->$prop ) {
				$c[ $key ] = true;
			}
		}

		return $c;
	}

	/**
	 * Charset/Collate pass through as captured; 'undefined' (constant missing) is what the format writes for unknown.
	 *
	 * @param Site          $site      Site snapshot.
	 * @param ExportOptions $options   Backup options.
	 * @param string        $dbVersion Database server version.
	 * @return array<string, mixed>
	 */
	private static function database( Site $site, ExportOptions $options, string $dbVersion ): array {
		$db = [
			'Version' => $dbVersion,
			'Charset' => $site->dbCharset,
			'Collate' => $site->dbCollate,
			'Prefix'  => $site->tablePrefix,
		];
		if ( [] !== $options->excludeTables ) {
			$db['ExcludedTables'] = $options->excludeTables;
		}
		if ( [] !== $options->includeTables ) {
			$db['IncludedTables'] = $options->includeTables;
		}

		return $db;
	}
}

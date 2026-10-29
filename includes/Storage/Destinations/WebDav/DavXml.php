<?php
/**
 * Reads WebDAV PROPFIND answers.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\SafeXml;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. A 207 multistatus (RFC 4918) lists one d:response per entry; only a
 * propstat whose status line has the code 200 counts. The href may be a path or a full URL; it
 * is reduced to its decoded path. Loaded through SafeXml (no network, no
 * DOCTYPE or ENTITY, at most MAX_BYTES).
 */
final class DavXml {

	public const PROPFIND = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>';

	/** Largest answer read (WebDavClient::MAX_XML). */
	private const MAX_BYTES = 8388608;

	/**
	 * @param string $xml Answer body.
	 * @return array<int, array{path: string, name: string, size: int, mtime: int, folder: bool}>|null Null when it is not a multistatus.
	 */
	public static function entries( string $xml ): ?array {
		$doc = SafeXml::load( $xml, self::MAX_BYTES );
		if ( null === $doc || 'multistatus' !== $doc->getName() ) {
			return null;
		}
		$entries = [];
		foreach ( $doc->children( 'DAV:' )->response as $response ) {
			$entry = self::entry( $response->children( 'DAV:' ) );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param \SimpleXMLElement $dav A d:response's DAV children.
	 * @return array{path: string, name: string, size: int, mtime: int, folder: bool}|null
	 */
	private static function entry( \SimpleXMLElement $dav ): ?array {
		$path = rtrim( rawurldecode( (string) parse_url( trim( (string) $dav->href ), PHP_URL_PATH ) ), '/' );
		$prop = null;
		foreach ( $dav->propstat as $propstat ) {
			$ps = $propstat->children( 'DAV:' );
			if ( 1 === preg_match( '/^HTTP\/\d(\.\d)?\s+200(\s|$)/', trim( (string) $ps->status ) ) ) {
				$prop = $ps->prop->children( 'DAV:' );
			}
		}
		if ( null === $prop || '' === $path ) {
			return null;
		}
		$mtime = strtotime( trim( (string) $prop->getlastmodified ) );
		$type  = $prop->resourcetype; // Empty when the server left it out.

		return [
			'path'   => $path,
			'name'   => basename( $path ),
			'size'   => max( 0, (int) trim( (string) $prop->getcontentlength ) ),
			'mtime'  => false === $mtime ? 0 : max( 0, $mtime ),
			'folder' => $type instanceof \SimpleXMLElement && isset( $type->children( 'DAV:' )->collection ),
		];
	}
}

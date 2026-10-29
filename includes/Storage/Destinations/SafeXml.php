<?php
/**
 * Loads an XML answer of a storage server, hardened.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. SimpleXML without network access (LIBXML_NONET; PHP 8 never loads
 * external entities by default, so an entity stays unexpanded). Anything that
 * does not parse reads as null. A document over $maxBytes, with a DOCTYPE or
 * ENTITY, in UTF-16/32 (BOM or NUL bytes, which hide the markers from a byte
 * search) or not starting with a tag, is not loaded at all. Shared by every
 * XML-speaking destination.
 */
final class SafeXml {

	/**
	 * @param string $xml      Answer body.
	 * @param int    $maxBytes Largest document loaded.
	 */
	public static function load( string $xml, int $maxBytes ): ?\SimpleXMLElement {
		if ( '' === trim( $xml ) || strlen( $xml ) > $maxBytes || ! function_exists( 'simplexml_load_string' ) || ! self::is_plain_markup( $xml ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		try {
			$doc = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		if ( false === $doc ) {
			return null;
		}
		$dom = dom_import_simplexml( $doc );

		return null !== $dom->ownerDocument && null !== $dom->ownerDocument->doctype ? null : $doc;
	}

	/**
	 * Single-byte text starting with a tag and declaring no DOCTYPE/ENTITY.
	 *
	 * @param string $xml Answer body.
	 */
	private static function is_plain_markup( string $xml ): bool {
		if ( false !== strpos( $xml, "\0" ) || 1 === preg_match( '/^(\xFF\xFE|\xFE\xFF)/', $xml ) ) {
			return false;
		}
		$start = ltrim( 0 === strncmp( $xml, "\xEF\xBB\xBF", 3 ) ? substr( $xml, 3 ) : $xml );

		return '<' === substr( $start, 0, 1 ) && false === stripos( $xml, '<!DOCTYPE' ) && false === stripos( $xml, '<!ENTITY' );
	}
}

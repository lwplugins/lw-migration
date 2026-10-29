<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\SafeXml;
use PHPUnit\Framework\TestCase;

final class SafeXmlTest extends TestCase {

	private const ENTITY_DOC = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY a "AAAA"><!ENTITY b "&a;&a;&a;&a;">]><multistatus xmlns="DAV:"><href>&b;</href></multistatus>';

	public function test_refuses_a_utf16_body_with_an_entity_declaration(): void {
		$body = "\xFF\xFE" . mb_convert_encoding( self::ENTITY_DOC, 'UTF-16LE', 'UTF-8' );

		$this->assertNull( SafeXml::load( $body, 8388608 ) );
	}

	public function test_refuses_a_utf32_bom_and_a_nul_byte(): void {
		$this->assertNull( SafeXml::load( "\xFF\xFE\x00\x00<a/>", 1000 ) );
		$this->assertNull( SafeXml::load( "\x00\x00\xFE\xFF<a/>", 1000 ) );
		$this->assertNull( SafeXml::load( "<a>x\x00</a>", 1000 ) );
	}

	public function test_refuses_a_body_that_does_not_start_with_a_tag(): void {
		$this->assertNull( SafeXml::load( 'junk<a/>', 1000 ) );
	}

	public function test_refuses_a_doctype_in_plain_utf8(): void {
		$this->assertNull( SafeXml::load( '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "A">]><r>&a;</r>', 1000 ) );
	}

	/**
	 * A UTF-7 body hides the markers from the byte search before the parse
	 * (+ADw-!DOCTYPE...), so only the DOCTYPE check after it refuses it.
	 */
	public function test_refuses_a_doctype_only_the_parser_sees(): void {
		$body = '<?xml version="1.0" encoding="UTF-7"?>' . mb_convert_encoding( '<!DOCTYPE r [<!ENTITY a "A">]>', 'UTF-7', 'UTF-8' ) . '<r>&a;</r>';
		$this->assertFalse( stripos( $body, '<!DOCTYPE' ), 'The byte search cannot see it.' );
		$previous = libxml_use_internal_errors( true );
		$parsed   = simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$this->assertNotFalse( $parsed, 'libxml reads UTF-7 here, so the check after the parse is what is tested.' );

		$this->assertNull( SafeXml::load( $body, 1000 ) );
	}

	public function test_loads_plain_xml_with_a_utf8_bom_or_leading_whitespace(): void {
		$bom   = SafeXml::load( "\xEF\xBB\xBF<?xml version=\"1.0\"?><r><v>ok</v></r>", 1000 );
		$space = SafeXml::load( " \n<r><v>ok</v></r>", 1000 );

		$this->assertSame( 'ok', (string) $bom->v );
		$this->assertSame( 'ok', (string) $space->v );
	}
}

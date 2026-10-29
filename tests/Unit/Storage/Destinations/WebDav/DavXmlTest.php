<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\WebDav\DavXml;
use PHPUnit\Framework\TestCase;

final class DavXmlTest extends TestCase {

	/** Shaped after Nextcloud 35.0.1's answer to PROPFIND Depth 1 (Docker, 2026-10-12). */
	private const MULTISTATUS = '<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
<d:response><d:href>/remote.php/dav/files/alice/lwm/</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 12 Oct 2026 21:41:24 GMT</d:getlastmodified><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
<d:response><d:href>/remote.php/dav/files/alice/lwm/nc12.wpress</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 12 Oct 2026 21:41:10 GMT</d:getlastmodified><d:getcontentlength>12582912</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
<d:response><d:href>https://cloud.example/remote.php/dav/files/alice/lwm/my%20site-20261012-030000-123.wpress</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 12 Oct 2026 21:41:23 GMT</d:getlastmodified><d:getcontentlength>2000</d:getcontentlength><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
<d:response><d:href>/remote.php/dav/files/alice/lwm/old/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>
<d:response><d:href>/remote.php/dav/files/alice/lwm/odd.wpress</d:href><d:propstat><d:prop/><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>
</d:multistatus>';

	public function test_reads_each_entry_with_its_size_time_and_kind(): void {
		$entries = DavXml::entries( self::MULTISTATUS );

		$this->assertSame(
			[
				[ 'path' => '/remote.php/dav/files/alice/lwm', 'name' => 'lwm', 'size' => 0, 'mtime' => gmmktime( 21, 41, 24, 10, 12, 2026 ), 'folder' => true ],
				[ 'path' => '/remote.php/dav/files/alice/lwm/nc12.wpress', 'name' => 'nc12.wpress', 'size' => 12582912, 'mtime' => gmmktime( 21, 41, 10, 10, 12, 2026 ), 'folder' => false ],
				[ 'path' => '/remote.php/dav/files/alice/lwm/my site-20261012-030000-123.wpress', 'name' => 'my site-20261012-030000-123.wpress', 'size' => 2000, 'mtime' => gmmktime( 21, 41, 23, 10, 12, 2026 ), 'folder' => false ],
				[ 'path' => '/remote.php/dav/files/alice/lwm/old', 'name' => 'old', 'size' => 0, 'mtime' => 0, 'folder' => true ],
			],
			$entries,
			'An entry without a 200 propstat is left out; an absolute href is reduced to its path.'
		);
	}

	public function test_an_answer_that_is_not_a_multistatus_reads_as_none(): void {
		$this->assertNull( DavXml::entries( '' ) );
		$this->assertNull( DavXml::entries( '<html><body>Login</body></html' ) );
		$this->assertSame( [], DavXml::entries( '<d:multistatus xmlns:d="DAV:"/>' ) );
	}

	/** Preflight P-I11: the hardened loader every XML answer goes through. */
	public function test_a_multistatus_with_a_doctype_or_entity_reads_as_none(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE d:multistatus [<!ENTITY x SYSTEM "file:///etc/passwd">]><d:multistatus xmlns:d="DAV:"><d:response><d:href>/a/&x;.wpress</d:href><d:propstat><d:prop/><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

		$this->assertNull( DavXml::entries( $xml ) );
		$this->assertNull( DavXml::entries( '<d:multistatus xmlns:d="DAV:"><!ENTITY a "b"></d:multistatus>' ) );
	}

	public function test_an_answer_over_eight_mib_reads_as_none(): void {
		$entry = '<d:response><d:href>/a/x.wpress</d:href><d:propstat><d:prop><d:resourcetype/></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
		$small = '<d:multistatus xmlns:d="DAV:">' . $entry . '</d:multistatus>';
		$big   = '<d:multistatus xmlns:d="DAV:">' . $entry . str_repeat( ' ', 8 * 1024 * 1024 ) . '</d:multistatus>';

		$this->assertCount( 1, (array) DavXml::entries( $small ) );
		$this->assertNull( DavXml::entries( $big ) );
	}

	/** Task 3 m4: only a status line whose code is 200 counts, with or without a reason phrase. */
	public function test_only_a_status_line_with_code_200_counts(): void {
		$one = static fn( string $status ): string => '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/a/x.wpress</d:href><d:propstat><d:prop><d:getcontentlength>5</d:getcontentlength><d:resourcetype/></d:prop><d:status>' . $status . '</d:status></d:propstat></d:response></d:multistatus>';

		foreach ( [ 'HTTP/1.1 200 OK', 'HTTP/1.1 200', 'HTTP/2 200 OK', 'HTTP/1.0 200 Fine' ] as $status ) {
			$this->assertCount( 1, (array) DavXml::entries( $one( $status ) ), $status );
		}
		foreach ( [ 'HTTP/1.1 404 200 OK', 'HTTP/1.1 2000', 'x 200 OK', 'HTTP/1.1 403 Forbidden 200 ' ] as $status ) {
			$this->assertSame( [], DavXml::entries( $one( $status ) ), $status );
		}
	}

	public function test_the_request_body_asks_for_size_time_and_kind(): void {
		foreach ( [ 'getcontentlength', 'getlastmodified', 'resourcetype' ] as $prop ) {
			$this->assertStringContainsString( '<d:' . $prop . '/>', DavXml::PROPFIND );
		}
	}
}

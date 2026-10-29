<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\S3\S3Xml;
use PHPUnit\Framework\TestCase;

final class S3XmlTest extends TestCase {

	private const NS = 'xmlns="http://s3.amazonaws.com/doc/2006-03-01/"';

	public function test_reads_the_upload_id_of_a_new_multipart_upload(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult ' . self::NS . '><Bucket>b</Bucket><Key>k</Key><UploadId>VXBsb2FkIElE</UploadId></InitiateMultipartUploadResult>';

		$this->assertSame( 'VXBsb2FkIElE', S3Xml::uploadId( $xml ) );
		$this->assertNull( S3Xml::uploadId( '<Error><Code>AccessDenied</Code></Error>' ) );
		$this->assertNull( S3Xml::uploadId( 'not xml' ) );
	}

	public function test_reads_a_listing_page_with_its_continuation(): void {
		$xml = '<ListBucketResult ' . self::NS . '><IsTruncated>true</IsTruncated>'
			. '<Contents><Key>site/a.wpress</Key><Size>12</Size><LastModified>2026-10-12T03:00:00.000Z</LastModified></Contents>'
			. '<Contents><Key>site/b.wpress</Key><Size>7</Size><LastModified>bad</LastModified></Contents>'
			. '<NextContinuationToken>tok/en=</NextContinuationToken></ListBucketResult>';

		$page = S3Xml::listPage( $xml );

		$this->assertSame( [ 'key' => 'site/a.wpress', 'size' => 12, 'mtime' => 1791774000 ], $page['files'][0] );
		$this->assertSame( 0, $page['files'][1]['mtime'], 'An unreadable date is 0, never a failure.' );
		$this->assertSame( [ 'tok/en=', true ], [ $page['next'], $page['truncated'] ] );
		$this->assertSame( [ 'files' => [], 'next' => null, 'truncated' => false ], S3Xml::listPage( '<ListBucketResult ' . self::NS . '><IsTruncated>false</IsTruncated></ListBucketResult>' ) );
	}

	public function test_reads_an_error_answer(): void {
		$this->assertSame( [ 'code' => 'NoSuchUpload', 'message' => 'gone' ], S3Xml::error( '<Error><Code>NoSuchUpload</Code><Message>gone</Message><UploadId>x</UploadId></Error>' ) );
		$this->assertNull( S3Xml::error( '<CompleteMultipartUploadResult ' . self::NS . '/>' ) );
	}

	public function test_builds_the_complete_body_in_part_order_with_escaped_etags(): void {
		$body = S3Xml::completeBody( [ 2 => '"b2"', 1 => '"a&1"' ] );

		$this->assertSame( '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>&quot;a&amp;1&quot;</ETag></Part><Part><PartNumber>2</PartNumber><ETag>&quot;b2&quot;</ETag></Part></CompleteMultipartUpload>', $body );
		$this->assertTrue( S3Xml::isCompleted( '<CompleteMultipartUploadResult ' . self::NS . '><ETag>"x-2"</ETag></CompleteMultipartUploadResult>' ) );
		$this->assertFalse( S3Xml::isCompleted( '<Error><Code>InternalError</Code></Error>' ), 'S3 may answer 200 with an error body.' );
	}

	public function test_a_document_with_a_doctype_or_entity_is_not_loaded(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><Error><Code>&x;</Code></Error>';

		$this->assertNull( S3Xml::load( $xml ) );
		$this->assertNull( S3Xml::error( $xml ) );
		$this->assertNull( S3Xml::load( '<Error><!ENTITY a "b"></Error>' ) );
	}

	public function test_an_oversized_document_is_not_loaded(): void {
		$this->assertNull( S3Xml::load( '<Error><Code>X</Code><Message>' . str_repeat( 'a', 4 * 1024 * 1024 ) . '</Message></Error>' ) );
	}

	public function test_the_error_code_is_a_plain_token_and_the_message_is_capped(): void {
		$bad = S3Xml::error( '<Error><Code>' . str_repeat( '<b>x</b>', 20 ) . '</Code><Message>m</Message></Error>' );
		$this->assertSame( '', $bad['code'] );
		$this->assertSame( '', S3Xml::error( '<Error><Code>Bad Code</Code></Error>' )['code'] );
		$this->assertSame( 'NoSuchKey', S3Xml::error( '<Error><Code>NoSuchKey</Code></Error>' )['code'] );
		$this->assertSame( 256, strlen( S3Xml::error( '<Error><Code>X</Code><Message>' . str_repeat( 'a', 1000 ) . '</Message></Error>' )['message'] ) );
	}
}

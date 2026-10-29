<?php
/**
 * The XML of S3 answers.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\SafeXml;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. Loaded through SafeXml (no network, no DOCTYPE or ENTITY); anything
 * that does not parse reads as null. The S3 namespace is the document's default,
 * so child names work without a prefix (a document with prefixed S3 elements
 * reads as empty). A document over 4 MiB, or with a DOCTYPE or ENTITY, is not
 * loaded at all.
 */
final class S3Xml {

	private const MAX_BYTES = 4194304;

	public static function load( string $xml ): ?\SimpleXMLElement {
		return SafeXml::load( $xml, self::MAX_BYTES );
	}

	public static function uploadId( string $xml ): ?string {
		$doc = self::load( $xml );
		$id  = null === $doc || 'InitiateMultipartUploadResult' !== $doc->getName() ? '' : trim( (string) $doc->UploadId );

		return '' === $id ? null : $id;
	}

	/**
	 * The message is server text: capped, never shown to users or logged as is.
	 *
	 * @param string $xml Answer body.
	 * @return array{code: string, message: string}|null
	 */
	public static function error( string $xml ): ?array {
		$doc = self::load( $xml );
		if ( null === $doc || 'Error' !== $doc->getName() ) {
			return null;
		}

		$code = trim( (string) $doc->Code );

		return [
			'code'    => 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}\z/', $code ) ? $code : '',
			'message' => substr( trim( (string) $doc->Message ), 0, 256 ),
		];
	}

	/**
	 * @param string $xml ListObjectsV2 answer.
	 * @return array{files: array<int, array{key: string, size: int, mtime: int}>, next: string|null, truncated: bool}|null
	 */
	public static function listPage( string $xml ): ?array {
		$doc = self::load( $xml );
		if ( null === $doc || 'ListBucketResult' !== $doc->getName() ) {
			return null;
		}
		$files = [];
		foreach ( $doc->Contents as $item ) {
			$time    = strtotime( (string) $item->LastModified );
			$files[] = [
				'key'   => (string) $item->Key,
				'size'  => max( 0, (int) $item->Size ),
				'mtime' => false === $time ? 0 : max( 0, $time ),
			];
		}
		$next = trim( (string) $doc->NextContinuationToken );

		return [
			'files'     => $files,
			'next'      => '' === $next ? null : $next,
			'truncated' => 'true' === strtolower( trim( (string) $doc->IsTruncated ) ),
		];
	}

	/**
	 * @param array<int, string> $parts Part number => ETag as the server sent it.
	 */
	public static function completeBody( array $parts ): string {
		ksort( $parts, SORT_NUMERIC );
		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars( $etag, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</ETag></Part>';
		}

		return $xml . '</CompleteMultipartUpload>';
	}

	public static function isCompleted( string $xml ): bool {
		$doc = self::load( $xml );

		return null !== $doc && 'CompleteMultipartUploadResult' === $doc->getName();
	}
}

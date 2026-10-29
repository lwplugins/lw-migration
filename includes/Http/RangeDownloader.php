<?php
/**
 * Resumable downloads through the WordPress HTTP API.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Http;

use LightweightPlugins\Migration\Archive\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Every request goes through SafeTransport (issue #2): the address is checked
 * against the refused and internal ranges and pinned, and core's
 * wp_safe_remote_request still refuses internal hosts and unusual ports and
 * verifies certificates. Redirects are followed by hand (at most REDIRECTS),
 * every hop checked and pinned again. Every answer is bounded by `limit_response_size` (one byte more
 * than expected, so an oversized answer shows) and streamed to `<target>` +
 * PART_SUFFIX; a part is appended only when it is exactly the requested range
 * (checked by RangeResponse). Only passing failures are tried again, after a
 * growing pause. Error messages show addresses through AddressRedactor.
 * A signer (connections) adds fresh signature headers to every request and
 * turns redirects off.
 */
final class RangeDownloader {

	public const CHUNK = 5242880;

	/** The per-chunk file next to the target (a fixed name, deliberately not a TempName). */
	public const PART_SUFFIX = '.part';

	private const REDIRECTS = 5;

	/** A server without range support sends the whole file in one request. */
	private const WHOLE_FILE_TIMEOUT = 3600;

	/**
	 * Waits the given seconds between attempts.
	 *
	 * @var \Closure(int): void
	 */
	private \Closure $sleep;

	/**
	 * Fresh signature headers for one request: fn(): array<string, string> (null: unsigned).
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $sign;

	/**
	 * The guarded transport.
	 *
	 * @var SafeTransport
	 */
	private SafeTransport $web;

	/**
	 * @param int           $chunk   Bytes per part.
	 * @param int           $retries Attempts per part.
	 * @param int           $timeout Seconds per request.
	 * @param \Closure|null $sleep   fn( int $seconds ): void between attempts (default sleep).
	 * @param \Closure|null $sign    fn(): array<string, string> — headers merged into every request, which then never follows a redirect.
	 */
	public function __construct( private int $chunk = self::CHUNK, private int $retries = 3, private int $timeout = 60, ?\Closure $sleep = null, ?\Closure $sign = null ) {
		$this->sleep = $sleep ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
		$this->sign  = $sign;
		$this->web   = new SafeTransport();
	}

	public function chunk(): int {
		return $this->chunk;
	}

	/**
	 * First header block, range support and size in one GET with a Range header.
	 * A part without a known total counts as no range support.
	 *
	 * @param string $url Address.
	 * @return array{status: int, ranges: bool, size: int|null, head: string, contentType: string}
	 * @throws DownloadException On a network error or an unexpected answer.
	 */
	public function probe( string $url ): array {
		$response = $this->request(
			$url,
			[
				'headers'             => [ 'Range' => 'bytes=0-' . ( Format::HEADER_SIZE - 1 ) ],
				'limit_response_size' => Format::HEADER_SIZE,
			]
		);
		$status   = (int) wp_remote_retrieve_response_code( $response );
		RangeResponse::assertStatus( $status, [ 200, 206 ] );
		$size = 206 === $status ? RangeResponse::totalFromStart( RangeResponse::header( $response, 'content-range' ) ) : RangeResponse::length( $response );

		return [
			'status'      => $status,
			'ranges'      => 206 === $status && null !== $size,
			'size'        => $size,
			'head'        => substr( (string) wp_remote_retrieve_body( $response ), 0, Format::HEADER_SIZE ),
			'contentType' => strtolower( RangeResponse::header( $response, 'content-type' ) ),
		];
	}

	/**
	 * A web page of at most $maxBytes, e.g. a download confirmation form.
	 *
	 * @param string $url      Address.
	 * @param int    $maxBytes Most bytes read.
	 * @throws DownloadException On a network error or an error status.
	 */
	public function page( string $url, int $maxBytes ): string {
		$response = $this->request( $url, [ 'limit_response_size' => max( 1, $maxBytes ) ] );
		$status   = (int) wp_remote_retrieve_response_code( $response );
		RangeResponse::assertStatus( $status, range( 200, 299 ) );

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Appends bytes $from..$to (or fewer at the end of the file) to $target,
	 * which must be exactly $from bytes long.
	 *
	 * @param string   $url    Address.
	 * @param string   $target Download file.
	 * @param int      $from   First byte.
	 * @param int      $to     Last byte.
	 * @param int|null $total  File size from the probe; a part of another file is refused.
	 * @return int The number of bytes appended.
	 * @throws DownloadException After the last failed attempt.
	 */
	public function fetchRange( string $url, string $target, int $from, int $to, ?int $total = null ): int {
		if ( $from < 0 || $to < $from ) {
			throw new DownloadException( 'The requested part of the file is not valid.' );
		}
		if ( DownloadFile::size( $target ) !== $from ) {
			throw new DownloadException( 'The download file does not end where the next part starts.' );
		}

		return $this->withRetries( fn(): int => $this->tryRange( $url, $target, $from, $to, $total ) );
	}

	/**
	 * Whole file in one streamed request (a server without range support).
	 * With a known $size the answer must be exactly that long; without one it
	 * may not exceed $maxBytes (the free disk space).
	 *
	 * @param string   $url      Address.
	 * @param string   $target   Download file.
	 * @param int|null $size     Expected size, when known.
	 * @param int|null $maxBytes Most bytes that fit, when known.
	 * @throws DownloadException After the last failed attempt.
	 */
	public function fetchAll( string $url, string $target, ?int $size = null, ?int $maxBytes = null ): int {
		if ( null !== $size && null !== $maxBytes && $size > $maxBytes ) {
			throw self::tooLarge();
		}

		return $this->withRetries( fn(): int => $this->tryAll( $url, $target, $size, $maxBytes ) );
	}

	/**
	 * Prepares $target for a resume at the saved $offset: removes a stale part,
	 * cuts back bytes a dead request appended, and restarts (empties the file)
	 * when bytes are missing. Returns the offset to continue from.
	 *
	 * @param string $target Download file.
	 * @param int    $offset Saved offset.
	 * @throws DownloadException When the file cannot be opened or cut.
	 */
	public static function resumeFrom( string $target, int $offset ): int {
		DownloadFile::remove( $target . self::PART_SUFFIX );

		return DownloadFile::cutBack( $target, $offset );
	}

	private function tryRange( string $url, string $target, int $from, int $to, ?int $total ): int {
		$part = $target . self::PART_SUFFIX;
		try {
			$response = $this->request(
				$url,
				[
					'headers'             => [ 'Range' => "bytes={$from}-{$to}" ],
					'stream'              => true,
					'filename'            => $part,
					'limit_response_size' => $to - $from + 2,
				]
			);
			$status   = (int) wp_remote_retrieve_response_code( $response );
			RangeResponse::assertStatus( $status, [ 206 ] );
			$end = RangeResponse::rangeEnd( RangeResponse::header( $response, 'content-range' ), $from, $to, $total );
			if ( null === $end ) {
				throw new DownloadException( 'The server did not return the requested part of the file.' );
			}
			$expected = $end - $from + 1;
			$length   = RangeResponse::length( $response );
			if ( null !== $length && $length !== $expected ) {
				throw new DownloadException( 'The server announced a part of the wrong size.' );
			}
			RangeResponse::checkSize( DownloadFile::size( $part ), $expected );
			DownloadFile::append( $part, $target, $from );

			return $expected;
		} finally {
			DownloadFile::remove( $part );
		}
	}

	private function tryAll( string $url, string $target, ?int $size, ?int $maxBytes ): int {
		$part = $target . self::PART_SUFFIX;
		$cap  = $size ?? $maxBytes;
		$args = [
			'stream'   => true,
			'filename' => $part,
			'timeout'  => max( $this->timeout, self::WHOLE_FILE_TIMEOUT ),
		];
		if ( null !== $cap ) {
			$args['limit_response_size'] = $cap + 1;
		}
		try {
			$response = $this->request( $url, $args );
			$status   = (int) wp_remote_retrieve_response_code( $response );
			RangeResponse::assertStatus( $status, [ 200 ] );
			$bytes  = DownloadFile::size( $part );
			$length = RangeResponse::length( $response );
			if ( null === $size && null !== $maxBytes && ( $bytes > $maxBytes || (int) $length > $maxBytes ) ) {
				throw self::tooLarge();
			}
			if ( null !== $size && null !== $length && $length !== $size ) {
				throw new DownloadException( 'The file on the server changed its size.' );
			}
			$expected = $size ?? $length;
			if ( null !== $expected ) {
				RangeResponse::checkSize( $bytes, $expected );
			}
			if ( ! rename( $part, $target ) ) {
				throw new DownloadException( 'Cannot write the downloaded file.' );
			}

			return $bytes;
		} finally {
			DownloadFile::remove( $part );
		}
	}

	private static function tooLarge(): DownloadException {
		return new DownloadException( __( 'The download is larger than the free disk space allows.', 'lw-migration' ) );
	}

	/**
	 * @param \Closure $attempt One try, returns a byte count.
	 * @throws DownloadException After the last attempt, or at once for a lasting failure.
	 */
	private function withRetries( \Closure $attempt ): int {
		for ( $try = 1; ; ++$try ) {
			try {
				return $attempt();
			} catch ( DownloadException $e ) {
				if ( ! $e->isPassing() || $try >= max( 1, $this->retries ) ) {
					throw $e;
				}
				( $this->sleep )( $try );
			}
		}
	}

	/**
	 * @param string               $url  Address (http or https only).
	 * @param array<string, mixed> $args Extra request arguments.
	 * @return array<string, mixed>
	 * @throws DownloadException On a refused address or a transport error.
	 */
	private function request( string $url, array $args ): array {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === (string) parse_url( $url, PHP_URL_HOST ) ) {
			throw new DownloadException( 'Only http and https addresses can be downloaded.' );
		}
		$args = array_merge(
			[
				'method'  => 'GET',
				'timeout' => $this->timeout,
			],
			$args
		);
		if ( null !== $this->sign ) {
			// A signed request belongs to this address: fresh headers for every try, never a redirect.
			$args['headers'] = array_merge( (array) ( $args['headers'] ?? [] ), ( $this->sign )() );
			$response        = $this->web->send( $url, $args );
		} else {
			$response = $this->web->follow( $url, $args, self::REDIRECTS );
		}
		if ( is_wp_error( $response ) ) {
			$message = sprintf( 'The download failed: %s', AddressRedactor::redact( $response->get_error_message(), $url ) );
			$code    = (string) $response->get_error_code();
			if ( RangeResponse::isAddressRefusal( $code, $response->get_error_message() ) ) {
				$error = DownloadException::addressRefused( $message );
			} else {
				$error = RangeResponse::isRefusal( $code, $response->get_error_message() ) ? new DownloadException( $message ) : DownloadException::passing( $message );
			}
			throw $error;
		}

		return $response;
	}
}

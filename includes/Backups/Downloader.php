<?php
/**
 * Streams a backup to the browser.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Only after the REST permission check and a DownloadToken (Rest\Routes):
 * backups are never reachable by URL, and the link never carries the REST
 * nonce. 1 MiB pieces (never the whole file in memory), one byte range at a
 * time (a browser or download manager resumes a broken download; a strong
 * ETag and Last-Modified with If-Range keep a resume from mixing two files), an
 * attachment name that can neither break the header nor carry a path; the
 * stream stops when the client is gone. No set_time_limit(): it is often
 * disabled and does not lift web-server or FPM wall-clock limits, so a very
 * large backup on such a host ends short and is resumed with a Range request.
 * Pure: the header writer, the output stream, the flush and the abort check
 * come from the caller.
 */
final class Downloader {

	private const PIECE = 1048576;

	/** On every reply, also a 416. */
	private const PRIVATE_HEADERS = [
		'Cache-Control: no-store, private',
		'X-Content-Type-Options: nosniff',
		'Referrer-Policy: no-referrer',
		'X-Robots-Tag: noindex, nofollow',
	];

	/**
	 * Where the bytes go.
	 *
	 * @var resource
	 */
	private $out;

	/**
	 * After every piece.
	 *
	 * @var \Closure
	 */
	private \Closure $flush;

	/**
	 * Whether the client is gone: fn(): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $aborted;

	/**
	 * @param \Closure      $header  fn( string $line, int $status ): void — a status > 0 is set before the line.
	 * @param resource|null $out     Output stream (default php://output).
	 * @param \Closure|null $flush   fn(): void (default flush()).
	 * @param \Closure|null $aborted fn(): bool (default connection_aborted()).
	 * @throws \RuntimeException When the output cannot be opened.
	 */
	public function __construct( private \Closure $header, $out = null, ?\Closure $flush = null, ?\Closure $aborted = null ) {
		$out = $out ?? fopen( 'php://output', 'wb' );
		if ( ! is_resource( $out ) ) {
			throw new \RuntimeException( 'Cannot open the output.' );
		}
		$this->out     = $out;
		$this->flush   = $flush ?? static function (): void {
			flush();
		};
		$this->aborted = $aborted ?? static fn(): bool => 0 !== connection_aborted();
	}

	/**
	 * The file is opened before the first header (also for HEAD and a 416),
	 * so a file that cannot be read fails with nothing sent: the route can
	 * still answer with an error of its own.
	 *
	 * @param Backup        $backup      Backup (found through Repository::findIn()).
	 * @param string|null   $range       Range header.
	 * @param string|null   $ifRange     If-Range header: a range is sent only while it still names this file.
	 * @param bool          $headersOnly A HEAD request: the headers of the GET, no body.
	 * @param \Closure|null $onStart     fn( int $status, int $first, int $last ): void — what a 200 or 206 sends, after the open, before the first header.
	 * @return int Bytes sent.
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public function send( Backup $backup, ?string $range, ?string $ifRange = null, bool $headersOnly = false, ?\Closure $onStart = null ): int {
		clearstatcache( true, $backup->path );
		$stat = is_file( $backup->path ) ? stat( $backup->path ) : false;
		// Checked first: a failed fopen() would print a PHP warning with the absolute path.
		$in = false !== $stat && is_readable( $backup->path ) ? fopen( $backup->path, 'rb' ) : false;
		if ( false === $stat || false === $in ) {
			throw new \RuntimeException( false === $stat ? 'The backup file is gone.' : 'Cannot open the backup file.' );
		}
		try {
			$size      = (int) $stat['size'];
			$validator = self::validators( $stat );
			if ( null !== $ifRange && ! in_array( trim( $ifRange ), array_values( $validator ), true ) ) {
				$range = null;
			}
			$span = ByteRange::parse( $range, $size );
			if ( false === $span ) {
				$this->emit( array_merge( [ 'Content-Range: bytes */' . $size ], self::PRIVATE_HEADERS ), 416 );
				return 0;
			}
			[ $first, $last ] = $span ?? [ 0, $size - 1 ];
			$status           = null === $span ? 200 : 206;
			if ( null !== $onStart ) {
				$onStart( $status, $first, $last );
			}
			$this->emit( self::headers( $backup->name, $size, $first, $last, $validator, null !== $span ), $status );

			return $headersOnly ? 0 : $this->stream( $in, $first, max( 0, $last - $first + 1 ) );
		} finally {
			fclose( $in );
		}
	}

	/**
	 * @param string                            $name      Backup file name.
	 * @param int                               $size      File size.
	 * @param int                               $first     First byte sent.
	 * @param int                               $last      Last byte sent.
	 * @param array{etag: string, date: string} $validator ETag and Last-Modified.
	 * @param bool                              $partial   A 206.
	 * @return array<int, string>
	 */
	private static function headers( string $name, int $size, int $first, int $last, array $validator, bool $partial ): array {
		$headers = [
			'Content-Type: application/octet-stream',
			'Content-Disposition: ' . self::disposition( $name ),
			'Content-Length: ' . max( 0, $last - $first + 1 ),
			'Accept-Ranges: bytes',
			'ETag: ' . $validator['etag'],
			'Last-Modified: ' . $validator['date'],
		];
		if ( $partial ) {
			$headers[] = sprintf( 'Content-Range: bytes %d-%d/%d', $first, $last, $size );
		}

		return array_merge( $headers, self::PRIVATE_HEADERS );
	}

	/**
	 * Strong ETag (hash of size, mtime and inode: no server detail shown) and Last-Modified.
	 *
	 * @param array<int|string, int> $stat stat() of the file.
	 * @return array{etag: string, date: string}
	 */
	private static function validators( array $stat ): array {
		$mtime = (int) $stat['mtime'];

		return [
			'etag' => '"' . Fingerprint::fromStat( $stat ) . '"',
			'date' => gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT',
		];
	}

	/**
	 * @param array<int, string> $lines  Header lines.
	 * @param int                $status Status code, set with the first line.
	 */
	private function emit( array $lines, int $status ): void {
		foreach ( $lines as $line ) {
			( $this->header )( $line, $status );
			$status = 0;
		}
	}

	/**
	 * ASCII fallback (quotes and anything unusual replaced) plus the exact name (RFC 6266 / 5987)
	 * when it is valid UTF-8.
	 *
	 * @param string $name Backup file name.
	 */
	private static function disposition( string $name ): string {
		$ascii = preg_replace( '/[^A-Za-z0-9._ -]/u', '_', $name );
		if ( null === $ascii ) { // Not valid UTF-8: replace byte by byte.
			$ascii = (string) preg_replace( '/[^A-Za-z0-9._ -]/', '_', $name );
		}

		$header = 'attachment; filename="' . $ascii . '"';

		return 1 === preg_match( '//u', $name ) ? $header . '; filename*=UTF-8\'\'' . rawurlencode( $name ) : $header;
	}

	/**
	 * @param resource $in     The open backup file.
	 * @param int      $first  First byte.
	 * @param int      $length Bytes to send.
	 * @return int Bytes sent (fewer when the client left or the file shrank).
	 * @throws \RuntimeException When the file cannot be positioned.
	 */
	private function stream( $in, int $first, int $length ): int {
		$left = $length;
		if ( 0 !== fseek( $in, $first ) ) {
			throw new \RuntimeException( 'Cannot read the backup file.' );
		}
		while ( $left > 0 && ! ( $this->aborted )() ) {
			$piece = fread( $in, min( self::PIECE, $left ) );
			if ( false === $piece || '' === $piece || strlen( $piece ) !== fwrite( $this->out, $piece ) ) {
				break;
			}
			$left -= strlen( $piece );
			( $this->flush )();
		}

		return $length - $left;
	}
}

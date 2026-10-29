<?php
/**
 * Stream wrapper that returns at most $max bytes per read (like network wrappers).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

/**
 * Wraps a real file as lwmshort://<absolute path>.
 */
final class ShortReadStream {

	public const SCHEME = 'lwmshort';

	/** @var resource|null */
	public $context;

	/** @var resource */
	private $handle;

	public static int $max = 1000;

	/** When true, reads return '' while the stream is not at EOF (a stalled source). */
	public static bool $stall = false;

	/** When set, url_stat() reports this mtime instead of the file's own. */
	public static ?int $urlMtime = null;

	/** Bytes handed out by stream_read() since the last reset(). */
	public static int $bytesRead = 0;

	public static function reset(): void {
		self::$max       = 1000;
		self::$stall     = false;
		self::$urlMtime  = null;
		self::$bytesRead = 0;
	}

	public static function register(): void {
		if ( ! in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::SCHEME, self::class );
		}
	}

	public static function url( string $path ): string {
		return self::SCHEME . '://' . $path;
	}

	private static function real( string $url ): string {
		return substr( $url, strlen( self::SCHEME . '://' ) );
	}

	public function stream_open( string $path, string $mode, int $options, ?string &$opened ): bool {
		$handle = fopen( self::real( $path ), 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$this->handle = $handle;
		return true;
	}

	/** @return string|false */
	public function stream_read( int $count ) {
		if ( self::$stall ) {
			return '';
		}
		$bytes            = fread( $this->handle, max( 1, min( $count, self::$max ) ) );
		self::$bytesRead += false === $bytes ? 0 : strlen( $bytes );
		return $bytes;
	}

	public function stream_eof(): bool {
		return feof( $this->handle );
	}

	public function stream_seek( int $offset, int $whence ): bool {
		return 0 === fseek( $this->handle, $offset, $whence );
	}

	public function stream_tell(): int {
		return (int) ftell( $this->handle );
	}

	/** @return array<int|string, int>|false */
	public function stream_stat() {
		return fstat( $this->handle );
	}

	/** @return array<int|string, int>|false */
	public function url_stat( string $path, int $flags ) {
		$real = self::real( $path );
		$stat = file_exists( $real ) ? stat( $real ) : false;
		if ( false !== $stat && null !== self::$urlMtime ) {
			$stat['mtime'] = self::$urlMtime;
			$stat[9]       = self::$urlMtime;
		}
		return $stat;
	}

	public function stream_close(): void {
		fclose( $this->handle );
	}
}

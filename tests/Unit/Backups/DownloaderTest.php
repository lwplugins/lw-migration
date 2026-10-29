<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Downloader;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class DownloaderTest extends TestCase {

	private string $dir;

	/** @var array<int, array{0: string, 1: int}> */
	private array $headers = [];

	protected function setUp(): void {
		$this->dir     = TempDir::create();
		$this->headers = [];
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * @param string        $name     File name.
	 * @param string        $contents File contents.
	 * @param string|null   $range    Range header.
	 * @param \Closure|null $aborted  Connection-abort check.
	 * @return array{0: string, 1: int, 2: array<string, string>, 3: int} Body, status, headers by lower-case name, bytes sent.
	 */
	private function download( string $name, string $contents, ?string $range, ?\Closure $aborted = null ): array {
		file_put_contents( $this->path( $name ), $contents );

		return $this->fetch( $name, $range, null, $aborted );
	}

	/** On disk under a plain name: the file system may refuse names a Backup can carry (invalid UTF-8). */
	private function path( string $name ): string {
		return $this->dir . '/f-' . md5( $name ) . '.wpress';
	}

	/**
	 * @param string        $name    File name (already written).
	 * @param string|null   $range   Range header.
	 * @param string|null   $ifRange If-Range header.
	 * @param \Closure|null $aborted Connection-abort check.
	 * @param bool          $headersOnly A HEAD request.
	 * @return array{0: string, 1: int, 2: array<string, string>, 3: int} Body, status, headers by lower-case name, bytes sent.
	 */
	private function fetch( string $name, ?string $range, ?string $ifRange = null, ?\Closure $aborted = null, bool $headersOnly = false ): array {
		$this->headers = [];
		$out           = fopen( 'php://memory', 'w+b' );
		$downloader    = new Downloader(
			function ( string $line, int $status ): void {
				$this->headers[] = [ $line, $status ];
			},
			$out,
			static function (): void {
			},
			$aborted ?? static fn(): bool => false
		);
		$path = $this->path( $name );
		$sent = $downloader->send( new Backup( $name, $path, (int) filesize( $path ), 1, Backup::OWN ), $range, $ifRange, $headersOnly );
		rewind( $out );
		$status = 0;
		$named  = [];
		foreach ( $this->headers as [ $line, $code ] ) {
			$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $line, 'A header line never breaks.' );
			$status                      = $code > 0 ? $code : $status;
			[ $key, $value ]             = explode( ':', $line, 2 );
			$named[ strtolower( $key ) ] = trim( $value );
		}

		return [ (string) stream_get_contents( $out ), $status, $named, $sent ];
	}

	public function test_the_whole_file_as_an_attachment(): void {
		[ $body, $status, $headers ] = $this->download( 'Site ä "x".wpress', 'abcdefghijklmnopqrstuvwxyz', null );

		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz', $body );
		$this->assertSame( 200, $status );
		$this->assertSame( 'application/octet-stream', $headers['content-type'] );
		$this->assertSame( '26', $headers['content-length'] );
		$this->assertSame( 'bytes', $headers['accept-ranges'] );
		$this->assertSame( 'attachment; filename="Site _ _x_.wpress"; filename*=UTF-8\'\'Site%20%C3%A4%20%22x%22.wpress', $headers['content-disposition'] );
		$this->assertSame( 'no-store, private', $headers['cache-control'] );
		$this->assertSame( 'nosniff', $headers['x-content-type-options'] );
		$this->assertSame( 'no-referrer', $headers['referrer-policy'] );
		$this->assertArrayNotHasKey( 'content-range', $headers );
	}

	public function test_a_head_request_gets_the_headers_only(): void {
		file_put_contents( $this->path( 'a.wpress' ), 'abcdefghijklmnopqrstuvwxyz' );
		[ $get, $getStatus, $getHeaders ] = $this->fetch( 'a.wpress', 'bytes=2-4' );

		[ $body, $status, $headers, $sent ] = $this->fetch( 'a.wpress', 'bytes=2-4', null, null, true );

		$this->assertSame( 'cde', $get );
		$this->assertSame( [ '', 0 ], [ $body, $sent ] );
		$this->assertSame( [ $getStatus, $getHeaders ], [ $status, $headers ], 'The same headers as the GET.' );
	}

	public function test_one_range(): void {
		[ $body, $status, $headers ] = $this->download( 'a.wpress', 'abcdefghijklmnopqrstuvwxyz', 'bytes=2-4' );

		$this->assertSame( 'cde', $body );
		$this->assertSame( 206, $status );
		$this->assertSame( 'bytes 2-4/26', $headers['content-range'] );
		$this->assertSame( '3', $headers['content-length'] );
		$this->assertSame( 'no-referrer', $headers['referrer-policy'] );
	}

	public function test_several_ranges_send_the_whole_file(): void {
		[ $body, $status, $headers ] = $this->download( 'a.wpress', 'abcdefghijklmnopqrstuvwxyz', 'bytes=0-1,5-6' );

		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz', $body );
		$this->assertSame( 200, $status );
		$this->assertSame( '26', $headers['content-length'] );
		$this->assertArrayNotHasKey( 'content-range', $headers );
	}

	public function test_an_unsatisfiable_range(): void {
		[ $body, $status, $headers ] = $this->download( 'a.wpress', 'abc', 'bytes=30-' );

		$this->assertSame( '', $body );
		$this->assertSame( 416, $status );
		$this->assertSame( 'bytes */3', $headers['content-range'] );
		$this->assertSame( 'no-store, private', $headers['cache-control'] );
		$this->assertSame( 'nosniff', $headers['x-content-type-options'] );
		$this->assertSame( 'no-referrer', $headers['referrer-policy'] );
	}

	public function test_an_empty_file(): void {
		[ $body, $status, $headers ] = $this->download( 'a.wpress', '', null );

		$this->assertSame( '', $body );
		$this->assertSame( 200, $status );
		$this->assertSame( '0', $headers['content-length'] );
	}

	public function test_large_files_go_in_pieces_and_stop_when_the_client_is_gone(): void {
		$contents = str_repeat( 'a', 1048576 ) . str_repeat( 'b', 1048576 ) . str_repeat( 'c', 1000 );
		$checks   = 0;

		[ $body ] = $this->download(
			'big.wpress',
			$contents,
			null,
			static function () use ( &$checks ): bool {
				++$checks;
				return $checks > 1;
			}
		);

		$this->assertSame( str_repeat( 'a', 1048576 ), $body, 'One piece went out, then the abort stopped the stream.' );
	}

	public function test_all_pieces_arrive_while_the_client_stays(): void {
		$contents = str_repeat( 'a', 1048576 ) . str_repeat( 'b', 1048576 ) . str_repeat( 'c', 1000 );

		[ $body, $status, $headers ] = $this->download( 'big.wpress', $contents, 'bytes=1048570-' );

		$this->assertSame( 206, $status );
		$this->assertSame( substr( $contents, 1048570 ), $body );
		$this->assertSame( (string) ( strlen( $contents ) - 1048570 ), $headers['content-length'] );
	}

	public function test_an_unreadable_file_emits_no_header_on_get_head_or_an_unsatisfiable_range(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root reads every file.' );
		}
		file_put_contents( $this->path( 'locked.wpress' ), 'secret bytes' );
		chmod( $this->path( 'locked.wpress' ), 0000 );
		try {
			foreach ( [ [ null, false ], [ null, true ], [ 'bytes=0-3', false ], [ 'bytes=999-', false ] ] as [ $range, $head ] ) {
				$started = [];
				try {
					$this->fetchWith( 'locked.wpress', $range, $head, $started );
					$this->fail( 'An unreadable file is refused.' );
				} catch ( \RuntimeException $e ) {
					$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
				}
				$this->assertSame( [], $this->headers, 'No header goes out before the file is open: ' . (string) $range );
				$this->assertSame( [], $started );
			}
		} finally {
			chmod( $this->path( 'locked.wpress' ), 0644 );
		}
	}

	public function test_the_start_hook_names_what_is_sent_before_the_first_header(): void {
		file_put_contents( $this->path( 'a.wpress' ), str_repeat( 'x', 100 ) );
		$cases = [
			[ null, false, [ 200, 0, 99 ] ],
			[ 'bytes=0-9', false, [ 206, 0, 9 ] ],
			[ 'bytes=50-', false, [ 206, 50, 99 ] ],
			[ 'bytes=0-1,5-9', false, [ 200, 0, 99 ] ],
			[ null, true, [ 200, 0, 99 ] ],
		];
		foreach ( $cases as [ $range, $head, $expected ] ) {
			$started = [];
			$this->fetchWith( 'a.wpress', $range, $head, $started );
			$this->assertSame( [ $expected ], $started, (string) $range );
		}
		$started = [];
		$this->fetchWith( 'a.wpress', 'bytes=500-', false, $started );
		$this->assertSame( [], $started, 'A 416 sends no file.' );
	}

	/**
	 * Like fetch(), with the start hook recording [status, first, last].
	 *
	 * @param string                              $name    File name (already written).
	 * @param string|null                         $range   Range header.
	 * @param bool                                $head    A HEAD request.
	 * @param array<int, array<int, int>>         $started Filled by the hook.
	 */
	private function fetchWith( string $name, ?string $range, bool $head, array &$started ): void {
		$this->headers = [];
		$downloader    = new Downloader(
			function ( string $line, int $status ) use ( &$started ): void {
				$this->headers[] = [ $line, $status ];
				if ( in_array( $status, [ 200, 206 ], true ) ) {
					$this->assertNotSame( [], $started, 'The hook runs before the first header.' );
				}
			},
			fopen( 'php://memory', 'w+b' ),
			static function (): void {
			},
			static fn(): bool => false
		);
		$path = $this->path( $name );
		$downloader->send(
			new Backup( $name, $path, 1, 1, Backup::OWN ),
			$range,
			null,
			$head,
			static function ( int $status, int $first, int $last ) use ( &$started ): void {
				$started[] = [ $status, $first, $last ];
			}
		);
	}

	public function test_a_vanished_file_is_refused_without_its_path(): void {
		$out        = fopen( 'php://memory', 'w+b' );
		$downloader = new Downloader(
			static function (): void {
			},
			$out
		);

		try {
			$downloader->send( new Backup( 'gone.wpress', $this->dir . '/gone.wpress', 3, 1, Backup::OWN ), null );
			$this->fail( 'A missing file must be refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
		}
	}

	public function test_validators_on_full_and_partial_replies(): void {
		[ , , $full, $sent ] = $this->download( 'a.wpress', 'abcdefghijklmnopqrstuvwxyz', null );
		$path                = $this->path( 'a.wpress' );
		$stat                = (array) stat( $path );

		$this->assertSame( 26, $sent, 'Bytes sent are reported.' );
		$this->assertSame( gmdate( 'D, d M Y H:i:s', (int) filemtime( $path ) ) . ' GMT', $full['last-modified'] );
		$this->assertMatchesRegularExpression( '/^"[a-f0-9]{32}"\z/', $full['etag'], 'A strong ETag.' );
		$this->assertStringNotContainsString( (string) $stat['ino'], $full['etag'], 'The inode is hashed, not shown.' );

		[ $body, $status, $partial, $sent ] = $this->fetch( 'a.wpress', 'bytes=2-4' );
		$this->assertSame( [ 'cde', 206, 3 ], [ $body, $status, $sent ] );
		$this->assertSame( $full['etag'], $partial['etag'] );
		$this->assertSame( $full['last-modified'], $partial['last-modified'] );
	}

	public function test_if_range_resumes_only_the_same_file(): void {
		[ , , $full ] = $this->download( 'a.wpress', 'abcdefghijklmnopqrstuvwxyz', null );

		[ $body, $status ] = $this->fetch( 'a.wpress', 'bytes=2-4', $full['etag'] );
		$this->assertSame( [ 'cde', 206 ], [ $body, $status ], 'Matching ETag: the range.' );

		[ $body, $status ] = $this->fetch( 'a.wpress', 'bytes=2-4', $full['last-modified'] );
		$this->assertSame( [ 'cde', 206 ], [ $body, $status ], 'Matching date: the range.' );

		foreach ( [ '"0123456789abcdef0123456789abcdef"', 'W/' . $full['etag'], 'Thu, 01 Jan 1970 00:00:00 GMT' ] as $other ) {
			[ $body, $status, $headers ] = $this->fetch( 'a.wpress', 'bytes=2-4', $other );
			$this->assertSame( [ 'abcdefghijklmnopqrstuvwxyz', 200 ], [ $body, $status ], 'Changed file: the whole file (' . $other . ').' );
			$this->assertArrayNotHasKey( 'content-range', $headers );
		}

		[ $body, $status ] = $this->fetch( 'a.wpress', 'bytes=99-', '"0123456789abcdef0123456789abcdef"' );
		$this->assertSame( [ 'abcdefghijklmnopqrstuvwxyz', 200 ], [ $body, $status ], 'A mismatch ignores even an unsatisfiable range.' );
	}

	public function test_an_unsatisfiable_range_sends_nothing(): void {
		[ , , , $sent ] = $this->download( 'a.wpress', 'abc', 'bytes=30-' );

		$this->assertSame( 0, $sent );
	}

	public function test_a_name_with_line_breaks_cannot_break_the_header(): void {
		[ , , $headers ] = $this->download( "a\r\nX-Evil: 1.wpress", 'abc', null );

		$this->assertSame( 'attachment; filename="a__X-Evil_ 1.wpress"; filename*=UTF-8\'\'a%0D%0AX-Evil%3A%201.wpress', $headers['content-disposition'] );
		$this->assertArrayNotHasKey( 'x-evil', $headers );
	}

	public function test_an_invalid_utf8_name_has_no_encoded_name(): void {
		[ , , $headers ] = $this->download( "caf\xE9.wpress", 'abc', null );

		$this->assertSame( 'attachment; filename="caf_.wpress"', $headers['content-disposition'] );
	}
}

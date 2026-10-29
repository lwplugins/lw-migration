<?php
/**
 * Hosts that list link() or ignore_user_abort() in disable_functions.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * A disabled function does not exist in PHP 8, so a bare call is a fatal
 * error, not a failure the fallback could catch. The code runs in a child PHP
 * with both functions disabled.
 */
final class DisabledFunctionsTest extends TestCase {

	private string $base;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/lwm-disabled-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base, 0700 );
		$this->base = (string) realpath( $this->base );
	}

	protected function tearDown(): void {
		foreach ( (array) scandir( $this->base ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				unlink( $this->base . '/' . $name );
			}
		}
		rmdir( $this->base );
	}

	public function test_files_are_moved_and_published_without_hard_links(): void {
		$script = 'define( "ABSPATH", "/" ); require $argv[2] . "/vendor/autoload.php";'
			. ' use LightweightPlugins\Migration\Export\BackupPublisher;'
			. ' use LightweightPlugins\Migration\Storage\{ExclusiveFile, FileMover, FolderIdentity};'
			. ' use LightweightPlugins\Migration\Support\{HardLink, NoDeadline, RequestAbort, TempName};'
			. ' $b = $argv[1]; file_put_contents( "$b/source", "data" ); $out = [ "exists" => function_exists( "link" ) ];'
			. ' $out["link"] = HardLink::make( "$b/source", "$b/linked" );'
			. ' $out["exclusive"] = null !== ExclusiveFile::create( $b, "made.txt", "hi", (string) FolderIdentity::of( $b ) ) && "hi" === file_get_contents( "$b/made.txt" );'
			. ' $temp = TempName::name(); $cursor = []; $mover = new FileMover();'
			. ' while ( ! $mover->copy( "$b/source", "$b/$temp", $cursor, new NoDeadline() ) ) {}'
			. ' $out["copied"] = "data" === file_get_contents( "$b/$temp" );'
			. ' $out["publish"] = ( new BackupPublisher( $b ) )->publish( $temp, "final.wpress" );'
			. ' RequestAbort::ignore(); $out["abort"] = true;'
			. ' echo json_encode( $out );';
		$command = [ PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'disable_functions=link,ignore_user_abort', '-r', $script, $this->base, dirname( __DIR__, 3 ) ];
		$process = proc_open( $command, [ 1 => [ 'pipe', 'w' ], 2 => [ 'redirect', 1 ] ], $pipes );
		$this->assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		proc_close( $process );

		$this->assertSame(
			[
				'exists'    => false,
				'link'      => false,
				'exclusive' => true,
				'copied'    => true,
				'publish'   => 'reserve',
				'abort'     => true,
			],
			json_decode( $output, true ),
			$output
		);
	}
}

<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpFolder;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\FakeSftp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SftpFolderTest extends MonkeyTestCase {

	private const WHERE = 'sftp://sftp.example.com:22';

	public function test_files_are_the_backups_and_the_test_file_of_its_folder(): void {
		$server        = new FakeSftp();
		$server->files = [
			'backups/site-20261012-030000-123.wpress'        => 'abc',
			'backups/site-20261012-030000-123.wpress.part'   => 'ab',
			'backups/lw-migration-test-0123456789abcdef.txt' => 't',
			'backups/notes.txt'                              => 'n',
			'other/x.wpress'                                 => 'x',
		];

		$files = ( new SftpFolder( $server, 'backups', self::WHERE ) )->files();

		$this->assertSame( [ 'site-20261012-030000-123.wpress', 'lw-migration-test-0123456789abcdef.txt' ], array_keys( $files ) );
		$this->assertSame( 3, $files['site-20261012-030000-123.wpress']['size'] );
	}

	public function test_writes_and_reads_inside_its_folder_and_a_login_folder_has_no_prefix(): void {
		$server = new FakeSftp();

		( new SftpFolder( $server, '/var/backups', self::WHERE ) )->write( 'a.wpress.part', 'abc', 0 );
		( new SftpFolder( $server, '', self::WHERE ) )->write( 'b.wpress.part', 'xyz', 0 );

		$this->assertSame( [ '/var/backups/a.wpress.part', 'b.wpress.part' ], array_keys( $server->files ) );
		$this->assertSame( 'bc', ( new SftpFolder( $server, '/var/backups', self::WHERE ) )->read( 'a.wpress.part', 1, 2 ) );
		$this->assertSame( [ 3, -1 ], [ ( new SftpFolder( $server, '/var/backups', self::WHERE ) )->size( 'a.wpress.part' ), ( new SftpFolder( $server, '/var/backups', self::WHERE ) )->size( 'b.wpress.part' ) ] );
	}

	public function test_refusals_and_missing_files(): void {
		$server          = new FakeSftp();
		$server->files   = [ 'b/x.wpress' => 'abc' ];
		$folder          = new SftpFolder( $server, 'b', self::WHERE );
		$server->refuses = [ 'write', 'rename', 'delete' ];

		foreach ( [ static fn() => $folder->write( 'x.wpress', 'a', 0 ), static fn() => $folder->rename( 'x.wpress', 'y.wpress' ), static fn() => $folder->delete( 'x.wpress' ) ] as $call ) {
			try {
				$call();
				$this->fail( 'Refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::DENIED, $e->reason );
				$this->assertStringNotContainsString( 'x.wpress', $e->getMessage() );
			}
		}
		$this->assertFalse( $folder->delete( 'gone.wpress' ), 'A missing file is no failure.' );
		try {
			$folder->read( 'gone.wpress', 0, 1 );
			$this->fail( 'Missing.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::MISSING, $e->reason );
		}
		$server->refuses = [ 'read' ];
		try {
			$folder->read( 'x.wpress', 0, 1 );
			$this->fail( 'Not sent.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::SERVER, $e->reason );
		}
		$server->refuses = [ 'files' ];
		$this->expectException( TransientFailure::class );
		$folder->files();
	}

	public function test_closing_the_folder_closes_the_session(): void {
		$server = new FakeSftp();

		( new SftpFolder( $server, 'b', self::WHERE ) )->close();

		$this->assertTrue( $server->closed );
	}
}

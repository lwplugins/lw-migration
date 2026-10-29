<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpFolder;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpPartWriter;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\FakeFtp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FtpFolderTest extends MonkeyTestCase {

	private const WHERE = 'ftp://ftp.example.com:21';

	private static function folder( FakeFtp $ftp ): FtpFolder {
		return new FtpFolder( $ftp, FtpPartWriter::REST, self::WHERE );
	}

	public function test_files_are_backups_and_the_test_file_with_size_and_time(): void {
		$ftp                                            = new FakeFtp();
		$ftp->files                                     = [
			'site-20261012-030000-123.wpress'        => 'abc',
			'site-20261012-030000-123.wpress.part'   => 'ab',
			'notes.txt'                              => 'n',
			'lw-migration-test-0123456789abcdef.txt' => 't',
		];
		$ftp->mtimes['site-20261012-030000-123.wpress'] = 1760000000;

		$files = FtpFolder::open( $ftp, self::WHERE )->files();

		$this->assertSame(
			[
				'site-20261012-030000-123.wpress'        => [ 'size' => 3, 'mtime' => 1760000000 ],
				'lw-migration-test-0123456789abcdef.txt' => [ 'size' => 1, 'mtime' => 0 ],
			],
			$files
		);
		$this->assertNotContains( 'SIZE notes.txt', $ftp->calls, 'Other files are not asked about.' );
	}

	public function test_a_list_the_server_does_not_give_is_a_passing_failure(): void {
		$ftp          = new FakeFtp();
		$ftp->refuses = [ 'names' ];

		$this->expectException( TransientFailure::class );
		self::folder( $ftp )->files();
	}

	public function test_delete_tells_a_missing_file_from_a_refusal(): void {
		$ftp = new FakeFtp();
		$this->assertFalse( self::folder( $ftp )->delete( 'x.wpress' ) );

		$ftp->files['x.wpress'] = 'abc';
		$ftp->refuses           = [ 'delete' ];
		try {
			self::folder( $ftp )->delete( 'x.wpress' );
			$this->fail( 'Refused, the file is still there.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::DENIED, $e->reason );
		}
	}

	public function test_a_read_of_a_missing_file_is_missing_and_a_failed_one_is_passing(): void {
		try {
			self::folder( new FakeFtp() )->read( 'x.wpress', 0, 3 );
			$this->fail( 'Missing.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::MISSING, $e->reason );
		}
		$ftp                    = new FakeFtp();
		$ftp->files['x.wpress'] = 'abc';
		$ftp->refuses           = [ 'read' ];
		$this->expectException( TransientFailure::class );
		self::folder( $ftp )->read( 'x.wpress', 0, 3 );
	}

	/**
	 * Task 6 carry: size() is -1 for a missing file, read() never more than asked.
	 */
	public function test_size_of_a_missing_file_is_minus_one_and_a_read_stays_within_its_length(): void {
		$ftp                    = new FakeFtp();
		$ftp->files['x.wpress'] = 'abcdef';

		$this->assertSame( -1, self::folder( $ftp )->size( 'y.wpress' ) );
		$this->assertSame( 6, self::folder( $ftp )->size( 'x.wpress' ) );
		$this->assertSame( 'bcd', self::folder( $ftp )->read( 'x.wpress', 1, 3 ) );
	}

	public function test_a_refused_rename_is_a_permission_problem_and_close_closes_the_connection(): void {
		$ftp                         = new FakeFtp();
		$ftp->files['x.wpress.part'] = 'abc';
		$ftp->refuses                = [ 'rename' ];
		$folder                      = self::folder( $ftp );

		try {
			$folder->rename( 'x.wpress.part', 'x.wpress' );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::DENIED, $e->reason );
		}
		$folder->close();
		$this->assertTrue( $ftp->closed );
	}

	public function test_open_asks_the_server_for_its_features_once(): void {
		$ftp       = new FakeFtp();
		$ftp->rest = false;
		$folder    = FtpFolder::open( $ftp, self::WHERE );

		$folder->write( 'x.wpress.part', 'abc', 0 );
		$folder->write( 'x.wpress.part', 'def', 3 );

		$this->assertSame( 'abcdef', $ftp->files['x.wpress.part'] );
		$this->assertSame( 1, count( array_keys( $ftp->calls, 'FEAT', true ) ) );
		$this->assertContains( 'APPE x.wpress.part 3', $ftp->calls, 'Without REST STREAM in FEAT the folder appends.' );
	}
}

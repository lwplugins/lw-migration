<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\FolderDestination;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFolder;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Tests\Support\FakeRemoteFolder;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FolderDestinationTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private const MIB = 1048576;

	private float $clock = 100.0;

	private ?int $free = null;

	private function destination( FakeRemoteFolder $folder, ?\Closure $open = null ): FolderDestination {
		return new FolderDestination(
			'sftp',
			'sftp://example.com:22',
			$open ?? $folder->open(),
			static fn(): int => 1760000000,
			function (): float {
				$this->clock += 0.1;
				return $this->clock;
			},
			fn(): ?int => $this->free
		);
	}

	private static function data( int $size ): string {
		return substr( str_repeat( hash( 'sha256', 'lw', true ), (int) ceil( $size / 32 ) ), 0, $size );
	}

	public function test_uploads_in_growing_parts_to_a_part_file_and_renames_it_at_the_end(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$data   = self::data( 3 * self::MIB + 5 );

		$s = $dest->startUpload( self::NAME, strlen( $data ) );
		while ( ! $s->isComplete() ) {
			$s = $dest->uploadChunk( $s, substr( $data, $s->offset, $s->nextLength() ) );
		}
		$file = $dest->finishUpload( $s );

		$part = self::NAME . '.part';
		$this->assertSame(
			[ 'delete:' . $part, 'size:' . $part, 'write:' . $part . '@0+1048576', 'size:' . $part, 'write:' . $part . '@1048576+2097152', 'size:' . $part, 'write:' . $part . '@3145728+5', 'size:' . $part, 'size:' . self::NAME, 'rename:' . $part . '>' . self::NAME ],
			$folder->calls
		);
		$this->assertSame( $data, $folder->files[ self::NAME ] );
		$this->assertSame( [ self::NAME ], array_keys( $folder->files ) );
		$this->assertSame( [ self::NAME, strlen( $data ), self::NAME ], [ $file->name, $file->size, $file->ref ] );
		$this->assertSame( $folder->opened, $folder->closed, 'Every connection was closed.' );
	}

	public function test_a_part_sent_again_overwrites_itself(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$data   = self::data( 10 );
		$s      = $dest->startUpload( self::NAME, 10 );

		$dest->uploadChunk( $s, $data ); // The request died before the job was saved…
		$s = $dest->uploadChunk( $s, $data ); // …so the same part goes again.

		$this->assertSame( $data, $folder->files[ self::NAME . '.part' ] );
		$this->assertTrue( $s->isComplete() );
	}

	public function test_a_part_file_that_lost_bytes_starts_the_upload_over_without_a_write(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$data   = self::data( 3 * self::MIB );
		$s      = $dest->uploadChunk( $dest->startUpload( self::NAME, strlen( $data ) ), substr( $data, 0, self::MIB ) );
		$folder->truncate( self::NAME . '.part', 1000 );
		$folder->calls = [];

		try {
			$dest->uploadChunk( $s, substr( $data, $s->offset, $s->nextLength() ) );
			$this->fail( 'A hole was written.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
		$this->assertSame( [ 'size:' . self::NAME . '.part' ], $folder->calls, 'No write.' );
		$this->assertSame( $folder->opened, $folder->closed );
	}

	public function test_a_part_file_that_vanished_starts_the_upload_over_unless_nothing_was_sent(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$data   = self::data( 3 * self::MIB );
		$s      = $dest->startUpload( self::NAME, strlen( $data ) );
		$s      = $dest->uploadChunk( $s, substr( $data, 0, self::MIB ) ); // No part file yet: -1 counts as 0.
		unset( $folder->files[ self::NAME . '.part' ] );

		$this->expectException( PermanentFailure::class );
		$dest->uploadChunk( $s, substr( $data, $s->offset, $s->nextLength() ) );
	}

	public function test_a_stale_part_file_is_removed_before_a_new_upload(): void {
		$folder                                = new FakeRemoteFolder();
		$folder->files[ self::NAME . '.part' ] = str_repeat( 'x', 100 );

		$this->destination( $folder )->startUpload( self::NAME, 10 );

		$this->assertArrayNotHasKey( self::NAME . '.part', $folder->files );
	}

	public function test_only_backups_and_the_test_file_are_listed_never_a_part_file(): void {
		$folder        = new FakeRemoteFolder();
		$folder->files = [
			self::NAME                               => 'abc',
			self::NAME . '.part'                     => 'ab',
			'notes.txt'                              => 'n',
			'lw-migration-test-0123456789abcdef.txt' => 't',
		];

		$names = array_map( static fn( RemoteFile $f ): string => $f->name, $this->destination( $folder )->list() );

		$this->assertSame( [ self::NAME, 'lw-migration-test-0123456789abcdef.txt' ], $names );
	}

	public function test_finish_accepts_a_rename_that_arrived_before_its_request_died(): void {
		$folder                      = new FakeRemoteFolder();
		$dest                        = $this->destination( $folder );
		$s                           = $dest->startUpload( self::NAME, 3 );
		$folder->files[ self::NAME ] = 'abc';
		$folder->calls               = [];

		$this->assertSame( 3, $dest->finishUpload( $s->with( 3, [] ) )->size );
		$this->assertSame( [ 'size:' . self::NAME . '.part', 'size:' . self::NAME ], $folder->calls, 'Nothing renamed again.' );
	}

	public function test_a_rename_never_replaces_another_file_of_that_name(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$s      = $dest->uploadChunk( $dest->startUpload( self::NAME, 3 ), 'abc' );
		$folder->files[ self::NAME ] = 'other file';

		try {
			$dest->finishUpload( $s );
			$this->fail( 'Replaced.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( self::NAME, $e->getMessage() );
		}
		$this->assertSame( 'other file', $folder->files[ self::NAME ] );
		$this->assertNotContains( 'rename:' . self::NAME . '.part>' . self::NAME, $folder->calls );
		$this->assertSame( $folder->opened, $folder->closed );
	}

	public function test_the_same_backup_already_in_place_is_kept_and_the_part_file_removed(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$s      = $dest->uploadChunk( $dest->startUpload( self::NAME, 3 ), 'abc' );
		$folder->files[ self::NAME ] = 'abc'; // Uploaded before under its unique name.
		$folder->calls               = [];

		$file = $dest->finishUpload( $s );

		$this->assertSame( [ 'size:' . self::NAME . '.part', 'size:' . self::NAME, 'delete:' . self::NAME . '.part' ], $folder->calls );
		$this->assertSame( [ self::NAME => 'abc' ], $folder->files );
		$this->assertSame( 3, $file->size );
	}

	/**
	 * @dataProvider provide_lost_parts
	 *
	 * @param array<string, string> $files What the folder holds at the finish.
	 */
	public function test_a_part_file_of_another_size_starts_the_upload_over( array $files ): void {
		$folder        = new FakeRemoteFolder();
		$dest          = $this->destination( $folder );
		$s             = $dest->uploadChunk( $dest->startUpload( self::NAME, 3 ), 'abc' );
		$folder->files = $files;

		try {
			$dest->finishUpload( $s );
			$this->fail( 'Another size.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
		$this->assertSame( $files, $folder->files, 'Nothing renamed or replaced.' );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function provide_lost_parts(): array {
		return [
			'shorter part'            => [ [ self::NAME . '.part' => 'ab' ] ],
			'longer part'             => [ [ self::NAME . '.part' => 'abcd' ] ],
			'neither'                 => [ [] ],
			'no part, other final'    => [ [ self::NAME => 'ab' ] ],
		];
	}

	public function test_reads_ranges_deletes_and_aborts_and_a_missing_file_is_no_failure(): void {
		$folder                      = new FakeRemoteFolder();
		$folder->files[ self::NAME ] = 'abcdef';
		$dest                        = $this->destination( $folder );
		$file                        = new RemoteFile( self::NAME, 6, 1, self::NAME );

		$this->assertSame( 'cde', $dest->readRange( $file, 2, 3 ) );
		$dest->delete( $file );
		$dest->delete( $file );
		$dest->abort( $dest->startUpload( self::NAME, 6 ) );
		$this->assertSame( [], $folder->files );
		$this->expectException( TransientFailure::class );
		$folder->files[ self::NAME ] = 'ab';
		$dest->readRange( $file, 0, 6 ); // Fewer bytes than asked for.
	}

	public function test_a_read_answering_more_bytes_than_asked_is_a_programming_error(): void {
		$folder                      = new FakeRemoteFolder();
		$folder->files[ self::NAME ] = 'abcdef';
		$folder->overRead            = 1;

		$this->expectException( \LogicException::class );
		$this->destination( $folder )->readRange( new RemoteFile( self::NAME, 6, 1, self::NAME ), 0, 3 );
	}

	public function test_an_abort_deletes_the_part_file(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$s      = $dest->uploadChunk( $dest->startUpload( self::NAME, 3 ), 'abc' );

		$dest->abort( $s );

		$this->assertSame( [], $folder->files );
	}

	public function test_a_range_outside_the_file_is_a_programming_error_and_reaches_nothing(): void {
		$folder = new FakeRemoteFolder();

		try {
			$this->destination( $folder )->readRange( new RemoteFile( self::NAME, 6, 1, self::NAME ), 4, 3 );
			$this->fail( 'Read.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 0, $folder->opened );
		}
	}

	/**
	 * @dataProvider provide_foreign_files
	 */
	public function test_a_file_that_is_not_a_backup_in_the_folder_is_never_deleted_or_read( RemoteFile $file ): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );

		foreach ( [ 'delete', 'read' ] as $action ) {
			try {
				'delete' === $action ? $dest->delete( $file ) : $dest->readRange( $file, 0, 1 );
				$this->fail( $action . ' passed.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason, $action );
			}
		}
		$this->assertSame( [ 0, [] ], [ $folder->opened, $folder->calls ], 'Nothing reached the folder.' );
	}

	/**
	 * @return array<string, array{0: RemoteFile}>
	 */
	public static function provide_foreign_files(): array {
		return [
			'outside the folder' => [ new RemoteFile( self::NAME, 1, 1, '../' . self::NAME ) ],
			'parent as name'     => [ new RemoteFile( '..', 1, 1, '..' ) ],
			'part file'          => [ new RemoteFile( self::NAME . '.part', 1, 1, self::NAME . '.part' ) ],
			'other file'         => [ new RemoteFile( 'wp-config.php', 1, 1, 'wp-config.php' ) ],
			'ref of another'     => [ new RemoteFile( self::NAME, 1, 1, 'example-com-20261012-030000-124.wpress' ) ],
		];
	}

	/**
	 * @dataProvider provide_foreign_sessions
	 */
	public function test_a_stored_session_that_is_not_ours_starts_over_before_any_request( UploadSession $s, bool $whole = true ): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );

		try {
			$dest->uploadChunk( $s, str_repeat( 'x', $s->nextLength() ) );
			$this->fail( 'Part sent.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
		}
		if ( $whole ) {
			try {
				$dest->finishUpload( $s->with( $s->size, $s->extra ) );
				$this->fail( 'Finished.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::SESSION_GONE, $e->reason );
			}
		}
		$dest->abort( $s );

		$this->assertSame( [ 0, [] ], [ $folder->opened, $folder->calls ], 'Nothing reached the folder.' );
	}

	/**
	 * @return array<string, array{0: UploadSession, 1?: bool}> The flag: whether the complete session is foreign too.
	 */
	public static function provide_foreign_sessions(): array {
		$part = self::NAME . '.part';

		return [
			'another type'          => [ new UploadSession( 'ftp', self::NAME, 3 * self::MIB, 0, self::MIB, $part ) ],
			'parent as part file'   => [ new UploadSession( 'sftp', self::NAME, 3 * self::MIB, 0, self::MIB, '..' ) ],
			'the backup as part'    => [ new UploadSession( 'sftp', self::NAME, 3 * self::MIB, 0, self::MIB, self::NAME ) ],
			'not a backup name'     => [ new UploadSession( 'sftp', 'wp-config.php', 3 * self::MIB, 0, self::MIB, 'wp-config.php.part' ) ],
			'part of no multiple'   => [ new UploadSession( 'sftp', self::NAME, 3 * self::MIB, 0, 100000, $part ) ],
			'part below a multiple' => [ new UploadSession( 'sftp', self::NAME, 3 * self::MIB, 0, 1, $part ) ],
			'part above 32 MiB'     => [ new UploadSession( 'sftp', self::NAME, 100 * self::MIB, 0, 64 * self::MIB, $part ) ],
			'offset off a boundary' => [ new UploadSession( 'sftp', self::NAME, 3 * self::MIB, 1000, self::MIB, $part ), false ],
			'provider extras'       => [ new UploadSession( 'sftp', self::NAME, 3 * self::MIB, 0, self::MIB, $part, [ 'path' => '/etc' ] ) ],
		];
	}

	public function test_only_backups_and_the_test_file_are_uploaded_and_nothing_is_sent_for_another_name(): void {
		$folder = new FakeRemoteFolder();

		try {
			$this->destination( $folder )->startUpload( '../' . self::NAME, 3 );
			$this->fail( 'Started.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 0, $folder->opened );
		}
	}

	public function test_the_connection_test_leaves_nothing_behind(): void {
		$folder = new FakeRemoteFolder();

		$this->destination( $folder )->test();

		$this->assertSame( [], $folder->files );
		$this->assertContains( 'read:', array_map( static fn( string $c ): string => substr( $c, 0, 5 ), $folder->calls ) );
		$this->assertSame( $folder->opened, $folder->closed );
	}

	public function test_a_connection_test_whose_rename_fails_leaves_nothing_behind(): void {
		$folder = new FakeRemoteFolder();
		$folder->failNext( 'rename', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		try {
			$this->destination( $folder )->test();
			$this->fail( 'Passed.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( [], $folder->files );
		}
	}

	public function test_the_folder_is_closed_after_every_call_even_on_failure(): void {
		$folder = new FakeRemoteFolder();
		$dest   = $this->destination( $folder );
		$s      = $dest->startUpload( self::NAME, 3 );
		$folder->failNext( 'write', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		try {
			$dest->uploadChunk( $s, 'abc' );
			$this->fail( 'Passed on.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( $folder->opened, $folder->closed );
		}
	}

	public function test_parts_stay_within_a_quarter_of_free_memory(): void {
		$this->free = 4 * self::MIB;
		$folder     = new FakeRemoteFolder();
		$dest       = $this->destination( $folder );

		$s = $dest->uploadChunk( $dest->startUpload( self::NAME, 3 * self::MIB ), self::data( self::MIB ) );

		$this->assertSame( self::MIB, $s->chunk, 'Fast, but a quarter of 4 MiB is the ceiling.' );
	}

	public function test_only_the_write_counts_towards_the_next_part_size_not_the_login(): void {
		$folder = new FakeRemoteFolder();
		$open   = $folder->open();
		$dest   = $this->destination(
			$folder,
			function () use ( $open ): RemoteFolder {
				$this->clock += 30.0; // A slow login.
				return $open();
			}
		);

		$s = $dest->uploadChunk( $dest->startUpload( self::NAME, 3 * self::MIB ), self::data( self::MIB ) );

		$this->assertSame( 2 * self::MIB, $s->chunk );
	}
}

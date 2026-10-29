<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Tests\Support\FakeDestination;
use LightweightPlugins\Migration\Tests\Support\FakeDestinationProxy;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ProbeFileTest extends MonkeyTestCase {

	public function test_writes_reads_back_and_deletes_a_small_file(): void {
		$destination = new FakeDestination( 1024 );

		ProbeFile::check( $destination, 'lw-migration-test-0123456789abcdef.txt' );

		$this->assertSame( [], $destination->files, 'The test file is gone afterwards.' );
		$this->assertSame( [ 'start', 'part', 'finish', 'read', 'delete' ], array_map( static fn( string $c ): string => strstr( $c, ':', true ), $destination->calls ) );
	}

	/**
	 * Backups are read back in ranges: the test reads from inside the file,
	 * so a server that cannot send part of a file (FTP without REST, WebDAV
	 * ignoring Range) fails here instead of at the first restore.
	 */
	public function test_it_reads_back_from_inside_the_file_so_a_server_sending_only_whole_files_fails(): void {
		$d = new FakeDestination( 1024 );

		ProbeFile::check( $d, 'lw-migration-test-0123456789abcdef.txt' );

		$this->assertMatchesRegularExpression( '/^read:lw-migration-test-0123456789abcdef\.txt@1\+\d+$/', $d->calls[3] );

		$inner = new FakeDestination( 1024 );
		$whole = new class( $inner ) extends FakeDestinationProxy {
			public function readRange( RemoteFile $f, int $offset, int $length ): string {
				return parent::readRange( $f, 0, $length ); // Ignores the offset.
			}
		};
		try {
			ProbeFile::check( $whole );
			$this->fail( 'A server that sends only from the start fails the test.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $inner->files );
	}

	public function test_its_name_is_never_a_backup_name(): void {
		$name = ProbeFile::name();

		$this->assertMatchesRegularExpression( '/^lw-migration-test-[a-f0-9]{16}\.txt\z/', $name );
		$this->assertTrue( ProbeFile::is( $name ) );
		$this->assertFalse( BackupName::isValid( $name ) );
	}

	public function test_a_failed_part_aborts_the_upload_and_passes_the_failure_on(): void {
		$destination = new FakeDestination( 1024 );
		$destination->failNext( 'part', new PermanentFailure( 'Denied.', PermanentFailure::DENIED, 403 ) );

		try {
			ProbeFile::check( $destination );
			$this->fail( 'The failure reaches the caller.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::DENIED, $e->reason );
		}
		$this->assertContains( 'abort', array_map( static fn( string $c ): string => strstr( $c, ':', true ), $destination->calls ) );
		$this->assertSame( [], $destination->sessions );
	}

	public function test_a_failed_read_still_deletes_the_file(): void {
		$destination = new FakeDestination( 1024 );
		$destination->failNext( 'read', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->expectException( TransientFailure::class );
		try {
			ProbeFile::check( $destination );
		} finally {
			$this->assertSame( [], $destination->files );
		}
	}

	public function test_different_bytes_read_back_are_an_invalid_destination_and_the_file_still_goes(): void {
		$inner   = new FakeDestination( 1024 );
		$garbled = new class( $inner ) implements DestinationInterface {
			public function __construct( private FakeDestination $d ) {
			}
			public function test(): void {
			}
			public function startUpload( string $name, int $size ): UploadSession {
				return $this->d->startUpload( $name, $size );
			}
			public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
				return $this->d->uploadChunk( $s, $bytes );
			}
			public function finishUpload( UploadSession $s ): RemoteFile {
				return $this->d->finishUpload( $s );
			}
			public function list(): array {
				return $this->d->list();
			}
			public function delete( RemoteFile $f ): void {
				$this->d->delete( $f );
			}
			public function readRange( RemoteFile $f, int $offset, int $length ): string {
				return str_repeat( 'x', $length );
			}
			public function abort( UploadSession $s ): void {
				$this->d->abort( $s );
			}
		};

		try {
			ProbeFile::check( $garbled );
			$this->fail( 'Different bytes are a failure.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $inner->files );
	}

	private static function methods( FakeDestination $d ): array {
		return array_map( static fn( string $c ): string => strstr( $c, ':', true ), $d->calls );
	}

	public function test_a_failed_start_is_passed_on(): void {
		$d = new FakeDestination( 1024 );
		$d->failNext( 'start', new PermanentFailure( 'Denied.', PermanentFailure::DENIED, 403 ) );

		try {
			ProbeFile::check( $d );
			$this->fail( 'The failure reaches the caller.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::DENIED, $e->reason );
		}
		$this->assertSame( [ 'start' ], self::methods( $d ) );
	}

	public function test_a_failed_finish_removes_an_object_the_provider_may_have_assembled(): void {
		$d     = new FakeDestination( 1024 );
		$other = 'backup-keep.zip';
		$d->files[ $other ] = 'keep';
		$name  = 'lw-migration-test-0123456789abcdef.txt';
		$wrap  = new class( $d ) extends FakeDestinationProxy {
			public function finishUpload( UploadSession $s ): RemoteFile {
				$this->d->finishUpload( $s );
				throw new TransientFailure( 'Lost the answer.', TransientFailure::NETWORK );
			}
		};

		try {
			ProbeFile::check( $wrap, $name );
			$this->fail( 'The failure reaches the caller.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
		}
		$this->assertSame( [ $other => 'keep' ], $d->files );
		$this->assertContains( 'list', self::methods( $d ) );
	}

	public function test_a_failed_abort_is_swallowed_and_the_first_failure_wins(): void {
		$d = new FakeDestination( 1024 );
		$d->failNext( 'part', new PermanentFailure( 'Denied.', PermanentFailure::DENIED, 403 ) );
		$d->failNext( 'abort', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->expectException( PermanentFailure::class );
		ProbeFile::check( $d );
	}

	public function test_a_failing_cleanup_listing_does_not_replace_the_finish_failure(): void {
		$d = new FakeDestination( 1024 );
		$d->failNext( 'finish', new PermanentFailure( 'Denied.', PermanentFailure::DENIED, 403 ) );
		$d->failNext( 'list', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->expectException( PermanentFailure::class );
		ProbeFile::check( $d );
	}

	public function test_a_failed_delete_alone_is_reported(): void {
		$d = new FakeDestination( 1024 );
		$d->failNext( 'delete', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->expectException( TransientFailure::class );
		ProbeFile::check( $d );
	}

	public function test_a_failed_delete_after_a_failed_read_keeps_the_read_failure(): void {
		$d = new FakeDestination( 1024 );
		$d->failNext( 'read', new PermanentFailure( 'Denied.', PermanentFailure::DENIED, 403 ) );
		$d->failNext( 'delete', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->expectException( PermanentFailure::class );
		ProbeFile::check( $d );
	}

	public function test_a_failed_delete_after_different_bytes_keeps_the_invalid_failure(): void {
		$inner  = new FakeDestination( 1024 );
		$inner->failNext( 'delete', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );
		$garbled = new class( $inner ) extends FakeDestinationProxy {
			public function readRange( RemoteFile $f, int $offset, int $length ): string {
				return str_repeat( 'x', $length );
			}
		};

		try {
			ProbeFile::check( $garbled );
			$this->fail( 'Different bytes are a failure.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
	}

	public function test_check_without_a_name_uses_a_probe_name(): void {
		$d = new FakeDestination( 1024 );

		ProbeFile::check( $d );

		$this->assertTrue( ProbeFile::is( substr( $d->calls[0], strlen( 'start:' ) ) ) );
	}
}

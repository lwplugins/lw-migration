<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\DeleteCommand;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class DeleteCommandTest extends MonkeyTestCase {

	#[RunInSeparateProcess]
	public function test_a_delete_is_refused_while_backups_are_moved(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		FakeOptions::install();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$storage = Services::get()->storage();
		try {
			$storage->ensure();
			file_put_contents( $storage->backupsDir() . '/a.wpress', 'backup' );
			Services::get()->jobs()->create( MoveJobs::TYPE, MoveJobs::STEPS, [], time() );

			try {
				( new DeleteCommand() )( [ 'a.wpress' ], [ 'yes' => true ] );
				$this->fail( 'A delete during a move is refused.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( [ 'The backups are being moved. Delete this one when the move has finished.' ], \WP_CLI::$errors );
			}
			$this->assertSame( 'backup', file_get_contents( $storage->backupsDir() . '/a.wpress' ) );
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}
	}
}

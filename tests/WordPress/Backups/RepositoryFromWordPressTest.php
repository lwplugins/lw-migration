<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class RepositoryFromWordPressTest extends MonkeyTestCase {

	private const ROOT = 'lw-migration-abcdefabcdef';

	private bool $createdContentDir = false;

	protected function setUp(): void {
		parent::setUp();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
			$this->createdContentDir = true;
		}
	}

	protected function tearDown(): void {
		TempDir::remove( WP_CONTENT_DIR . '/' . self::ROOT );
		TempDir::remove( WP_CONTENT_DIR . '/' . Repository::EXTERNAL_DIR );
		if ( $this->createdContentDir ) {
			TempDir::remove( WP_CONTENT_DIR );
		}
		parent::tearDown();
	}

	public function test_resolves_our_backups_folder_and_the_external_folder(): void {
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		mkdir( WP_CONTENT_DIR . '/' . self::ROOT . '/backups', 0755, true );
		mkdir( WP_CONTENT_DIR . '/' . Repository::EXTERNAL_DIR );
		file_put_contents( WP_CONTENT_DIR . '/' . self::ROOT . '/backups/ours.wpress', 'a' );
		touch( WP_CONTENT_DIR . '/' . self::ROOT . '/backups/ours.wpress', 2000 );
		file_put_contents( WP_CONTENT_DIR . '/' . Repository::EXTERNAL_DIR . '/theirs.wpress', 'b' );
		touch( WP_CONTENT_DIR . '/' . Repository::EXTERNAL_DIR . '/theirs.wpress', 1000 );

		$all = Repository::fromWordPress( StorageDir::fromWordPress() )->all();

		$this->assertSame(
			[
				[ 'ours.wpress', Backup::OWN, WP_CONTENT_DIR . '/' . self::ROOT . '/backups/ours.wpress' ],
				[ 'theirs.wpress', Backup::EXTERNAL, WP_CONTENT_DIR . '/' . Repository::EXTERNAL_DIR . '/theirs.wpress' ],
			],
			array_map( static fn( Backup $b ): array => [ $b->name, $b->source, $b->path ], $all )
		);
	}

	public function test_from_wordpress_uses_the_chosen_and_moving_folder(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/chosen' );
			mkdir( WP_CONTENT_DIR . '/' . self::ROOT . '/backups', 0755, true );
			file_put_contents( $dir . '/chosen/chosen.wpress', 'a' );
			file_put_contents( WP_CONTENT_DIR . '/' . self::ROOT . '/backups/default.wpress', 'b' );
			file_put_contents( WP_CONTENT_DIR . '/' . self::ROOT . '/backups/both.wpress', 'c' );
			file_put_contents( $dir . '/chosen/both.wpress', 'c' );
			FakeWpdb::install(
				[
					Options::STORAGE_DIR => self::ROOT,
					Options::BACKUP_DIR  => (string) json_encode( [ 'path' => $dir . '/chosen', 'moving' => '', 'marker' => '0123456789abcdef' ], JSON_UNESCAPED_SLASHES ),
				]
			);

			$repo = Repository::fromWordPress( StorageDir::fromWordPress() );
			$all  = [];
			foreach ( $repo->all() as $backup ) {
				$all[ $backup->name ] = [ $backup->source, $backup->path ];
			}
			ksort( $all );

			$this->assertSame(
				[
					'both.wpress'    => [ Backup::OWN, $dir . '/chosen/both.wpress' ],
					'chosen.wpress'  => [ Backup::OWN, $dir . '/chosen/chosen.wpress' ],
					'default.wpress' => [ Backup::OWN, WP_CONTENT_DIR . '/' . self::ROOT . '/backups/default.wpress' ],
				],
				$all
			);
		} finally {
			TempDir::remove( $dir );
		}
	}
}

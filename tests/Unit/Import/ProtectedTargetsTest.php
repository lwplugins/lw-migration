<?php
/**
 * Tests for ProtectedTargets.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Import\ProtectedTargets;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\WordPress\Site;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtectedTargetsTest extends TestCase {

	/** @return array<string, array{0: string, 1: string|null}> */
	public static function targets(): array {
		return [
			'mu-plugins file'       => [ '/w/wp-content/mu-plugins/a.php', 'protected' ],
			'mu-plugins folder'     => [ '/w/wp-content/mu-plugins', 'protected' ],
			'storage root'          => [ '/w/wp-content/lw-migration-0123456789ab/jobs/x', 'storage' ],
			'other storage folder'  => [ '/w/wp-content/LW-Migration-ABCDEF123456/x', 'storage' ],
			'storage-like folder'   => [ '/w/wp-content/lw-migration-0123456789abc/x', null ],
			'legacy folder'         => [ '/w/wp-content/lw-migration/x', 'storage' ],
			'other tool backups'    => [ '/w/wp-content/ai1wm-backups/a.wpress', 'storage' ],
			'custom backups'        => [ '/srv/backups/a.wpress', 'storage' ],
			'custom backups case'   => [ '/SRV/Backups/a.wpress', 'storage' ],
			'wp-config'             => [ '/w/wp-config.php', 'config' ],
			'wp-config above'       => [ '/wp-config.php', 'config' ],
			'own plugin'            => [ '/w/wp-content/plugins/lw-migration/x.php', 'own' ],
			'own plugin normalised' => [ '/w//wp-content/./plugins/x/../LW-Migration', 'own' ],
			'similar plugin'        => [ '/w/wp-content/plugins/lw-migration-extra/x.php', null ],
			'a base'                => [ '/w/wp-content/plugins', 'unsafe' ],
			'above a base'          => [ '/w/wp-content', 'unsafe' ],
			'theme root'            => [ '/w/wp-content/themes/', 'unsafe' ],
			'plain file'            => [ '/w/wp-content/uploads/2026/a.jpg', null ],
		];
	}

	#[DataProvider( 'targets' )]
	public function test_covers( string $target, ?string $expected ): void {
		$this->assertSame( $expected, ProtectedTargets::forSite( self::site(), '/w/wp-content/lw-migration-0123456789ab' )->covers( $target ) );
	}

	public function test_with_adds_paths_and_roots_without_changing_the_original(): void {
		$targets = ProtectedTargets::forSite( self::site(), '/s' );
		$more    = $targets->with( [ '/w/wp-content/object-cache.php' => 'dropin' ], [ '/job/mu' ] );

		$this->assertSame( 'dropin', $more->covers( '/w/wp-content/OBJECT-CACHE.php' ) );
		$this->assertSame( 'unsafe', $more->covers( '/job' ) );
		$this->assertNull( $targets->covers( '/w/wp-content/object-cache.php' ) );
	}

	public function test_empty_site_paths_are_not_protected(): void {
		$targets = ProtectedTargets::forSite( Site::fromArray( [ 'abspath' => '/w/', 'contentDir' => '/w/c' ] ), '/s' );

		$this->assertNull( $targets->covers( '/w/c/x.php' ) );
	}

	public function test_chosen_backup_folders_are_protected(): void {
		$dir = TempDir::create();
		try {
			$chosen = $dir . '/outer/chosen';
			mkdir( $chosen, 0755, true );
			mkdir( $dir . '/moving' );
			symlink( $dir . '/outer', $dir . '/alias' );
			$site    = Site::fromArray(
				[
					'abspath'    => $dir . '/site/',
					'contentDir' => $dir . '/site/wp-content',
					'backupDirs' => [ $chosen, $dir . '/moving' ],
				]
			);
			$targets = ProtectedTargets::forSite( $site, $dir . '/site/wp-content/lw-migration-0123456789ab' );

			$this->assertSame( 'storage', $targets->covers( $chosen . '/x.wpress' ) );
			$this->assertSame( 'storage', $targets->covers( $chosen ) );
			$this->assertSame( 'storage', $targets->covers( $dir . '/moving/sub/x.wpress' ) );
			$this->assertSame( 'storage', $targets->covers( $dir . '/alias/chosen/x.wpress' ), 'Through a link above it: by identity.' );
			$this->assertSame( 'storage', $targets->covers( $dir . '/site/wp-content/lw-migration-backups-0123456789ab/x' ), 'By name, also one not in backupDirs.' );
			$this->assertNull( $targets->covers( $dir . '/outer/other.txt' ) );
			$this->assertNull( $targets->covers( $dir . '/site/wp-content/lw-migration-backups-0123456789a/x' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_a_chosen_folder_is_protected_under_every_spelling_the_file_system_accepts(): void {
		$dir = TempDir::create();
		try {
			$chosen = $dir . "/backup\u{00e9}";
			mkdir( $chosen );
			$variants = array_filter( [ $dir . "/BACKUP\u{00c9}", $dir . "/backupe\u{0301}" ], 'is_dir' );
			if ( [] === $variants ) {
				$this->markTestSkipped( 'Needs a case- or normalisation-insensitive file system.' );
			}
			$targets = ProtectedTargets::forSite( Site::fromArray( [ 'contentDir' => $dir . '/c', 'backupDirs' => [ $chosen ] ] ), $dir . '/c/lw-migration-0123456789ab' );

			foreach ( $variants as $variant ) {
				$this->assertSame( 'storage', $targets->covers( $variant . '/x.wpress' ), $variant );
			}
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_the_external_folder_and_leftover_folders_are_protected_under_every_spelling(): void {
		$dir = TempDir::create();
		try {
			$content = $dir . '/c';
			foreach ( [ 'ai1wm-backups', 'lw-migration-backups-0123456789ab', 'lw-migration-aaaaaaaaaaaa', 'custom' ] as $folder ) {
				mkdir( $content . '/' . $folder, 0755, true );
			}
			mkdir( $dir . '/outside' );
			symlink( $dir, $dir . '/alias' );
			$targets  = ProtectedTargets::forSite( Site::fromArray( [ 'contentDir' => $content, 'externalBackupsPath' => $dir . '/outside' ] ), $content . '/lw-migration-0123456789ab' );
			$variants = array_filter(
				[
					$content . "/lw-migration-backup\u{017f}-0123456789ab/x",
					$content . "/lw-migration-aaaaaaaaaaaa\u{0301}/x",
					$content . '/AI1WM-BACKUPS/x',
				],
				static fn( string $p ): bool => is_dir( dirname( $p ) )
			);

			$this->assertSame( 'storage', $targets->covers( $dir . '/alias/c/ai1wm-backups/x.wpress' ), 'Through a link above it.' );
			$this->assertSame( 'storage', $targets->covers( $dir . '/alias/outside/x.wpress' ) );
			$this->assertSame( 'storage', $targets->covers( $dir . '/alias/c/lw-migration-backups-0123456789ab/x' ) );
			foreach ( $variants as $variant ) {
				$this->assertSame( 'storage', $targets->covers( $variant ), $variant );
			}
			$this->assertNull( $targets->covers( $dir . '/alias/c/custom/x' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_a_backups_folder_above_an_extraction_base_seals_nothing(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/c/plugins', 0755, true );
			$targets = new ProtectedTargets( $dir . '/c', [], [ $dir . '/c/plugins' ], [ $dir, $dir . '/c/plugins' ] );

			$this->assertNull( $targets->covers( $dir . '/c/plugins/a/a.php' ), 'Sealing it would seal the base.' );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_a_relative_path_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		new ProtectedTargets( '/w/c', [ 'wp-config.php' => 'config' ], [] );
	}

	public function test_an_unsafe_plugin_folder_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProtectedTargets::forSite( Site::fromArray( [ 'contentDir' => '/w/c', 'ownPlugin' => '../lw-migration.php' ] ), '/s' );
	}

	private static function site(): Site {
		return Site::fromArray(
			[
				'abspath'             => '/w/',
				'contentDir'          => '/w/wp-content',
				'pluginsDir'          => '/w/wp-content/plugins',
				'muPluginsDir'        => '/w/wp-content/mu-plugins',
				'themeRoot'           => '/w/wp-content/themes',
				'uploadsDir'          => '/w/wp-content/uploads',
				'ownPlugin'           => 'lw-migration/lw-migration.php',
				'externalBackupsPath' => '/srv/backups',
			]
		);
	}
}

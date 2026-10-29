<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Backups;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LabelsTest extends MonkeyTestCase {

	private const ROOT = 'lw-migration-bcdefabcdefa';

	private bool $createdContentDir = false;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
	}

	protected function tearDown(): void {
		TempDir::remove( WP_CONTENT_DIR . '/' . self::ROOT );
		if ( $this->createdContentDir ) {
			TempDir::remove( WP_CONTENT_DIR );
		}
		parent::tearDown();
	}

	public function test_set_get_remove(): void {
		Labels::set( 'a.wpress', ' <b>Before launch</b> ' );
		$this->assertSame( 'Before launch', Labels::get( 'a.wpress' ) );
		$this->assertSame( [ 'a.wpress' => 'Before launch' ], FakeOptions::$values[ Options::LABELS ] );
		Labels::set( 'a.wpress', '' );
		$this->assertSame( '', Labels::get( 'a.wpress' ) );
		Labels::set( 'b.wpress', 'x' );
		Labels::remove( 'b.wpress' );
		$this->assertSame( [], Labels::all() );
	}

	public function test_an_invalid_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Labels::set( '../a.wpress', 'x' );
	}

	public function test_a_label_is_clamped_to_200_characters(): void {
		Labels::set( 'a.wpress', str_repeat( 'é', 250 ) );
		$this->assertSame( str_repeat( 'é', 200 ), Labels::get( 'a.wpress' ) );
	}

	public function test_control_characters_are_removed_before_the_cut(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => (string) $s );

		Labels::set( 'a.wpress', "A\x00b\x07c\x7Fd\u{0085}e\u{009F}f" );
		$this->assertSame( 'Abcdef', Labels::get( 'a.wpress' ) );

		Labels::set( 'a.wpress', str_repeat( "x\x07", 250 ) );
		$this->assertSame( str_repeat( 'x', 200 ), Labels::get( 'a.wpress' ) );
	}

	public function test_a_label_of_control_characters_only_removes_the_label(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => (string) $s );
		Labels::set( 'a.wpress', 'Before launch' );

		Labels::set( 'a.wpress', " \x07\x00\u{0085} " );

		$this->assertSame( '', Labels::get( 'a.wpress' ) );
		$this->assertSame( [], Labels::all() );
	}

	public function test_the_cap_drops_labels_of_gone_backups_first(): void {
		$labels = [];
		for ( $i = 0; $i < Labels::MAX_ENTRIES; $i++ ) {
			$labels[ "b{$i}.wpress" ] = "l{$i}";
		}
		FakeOptions::install( [ Options::LABELS => $labels ] );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => (string) $s );
		$exists = static fn( string $name ): bool => 'b3.wpress' !== $name;

		Labels::set( 'new.wpress', 'n', $exists );

		$all = Labels::all();
		$this->assertCount( Labels::MAX_ENTRIES, $all );
		$this->assertArrayNotHasKey( 'b3.wpress', $all );
		$this->assertSame( 'l0', $all['b0.wpress'] );
		$this->assertSame( 'n', $all['new.wpress'] );
	}

	public function test_the_cap_then_drops_the_oldest_labels(): void {
		$labels = [];
		for ( $i = 0; $i < Labels::MAX_ENTRIES; $i++ ) {
			$labels[ "b{$i}.wpress" ] = "l{$i}";
		}
		FakeOptions::install( [ Options::LABELS => $labels ] );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => (string) $s );

		Labels::set( 'new.wpress', 'n', static fn(): bool => true );

		$all = Labels::all();
		$this->assertCount( Labels::MAX_ENTRIES, $all );
		$this->assertArrayNotHasKey( 'b0.wpress', $all );
		$this->assertSame( 'n', $all['new.wpress'] );
	}

	public function test_by_default_the_cap_checks_the_backup_folders(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
			$this->createdContentDir = true;
		}
		mkdir( WP_CONTENT_DIR . '/' . self::ROOT . '/backups', 0755, true );
		file_put_contents( WP_CONTENT_DIR . '/' . self::ROOT . '/backups/b0.wpress', 'x' );
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$labels = [];
		for ( $i = 0; $i < Labels::MAX_ENTRIES; $i++ ) {
			$labels[ "b{$i}.wpress" ] = "l{$i}";
		}
		FakeOptions::install( [ Options::LABELS => $labels ] );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => (string) $s );

		Labels::set( 'new.wpress', 'n' );

		$this->assertSame( [ 'b0.wpress' => 'l0', 'new.wpress' => 'n' ], Labels::all() );
	}
}

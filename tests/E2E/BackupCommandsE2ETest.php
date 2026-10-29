<?php
/**
 * The backup commands (extract, delete) and their guards on a real WordPress site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Tests\Support\TempDir;

final class BackupCommandsE2ETest extends E2ETestCase {

	public function test_extract_refuses_a_target_inside_the_site_unless_allowed(): void {
		[ $name ] = self::export( 'b', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		$inside   = WpSites::path( 'b' ) . '/lwm-e2e-extract';

		[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'extract', $name, '--dir=' . $inside );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Refusing to extract inside the WordPress folder', $err );
		$this->assertDirectoryDoesNotExist( $inside );

		// A spelling with .. that resolves into the site is refused as well.
		[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'extract', $name, '--dir=' . WpSites::path( 'b' ) . '/wp-content/x/../lwm-e2e' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Refusing to extract', $err );

		try {
			WpSites::ok( 'b', 'lw-migration', 'extract', $name, '--dir=' . $inside, '--allow-web-root' );
			$this->assertFileExists( $inside . '/database.sql' );
			$this->assertFileExists( $inside . '/package.json' );
		} finally {
			TempDir::remove( $inside );
		}
	}

	public function test_extract_with_a_wrong_or_missing_password_fails_cleanly(): void {
		[ $name ] = self::export( 'b', '--exclude-media', '--exclude-plugins', '--exclude-themes', '--password=right-one' );
		$dir      = TempDir::create();
		try {
			[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'extract', $name, '--dir=' . $dir . '/out', '--password=wrong-one' );
			$this->assertSame( 1, $code );
			$this->assertStringContainsString( 'The password is wrong.', $err );
			$this->assertFileDoesNotExist( $dir . '/out/database.sql' );

			// No terminal and no value: refused before any prompt.
			[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'extract', $name, '--dir=' . $dir . '/out' );
			$this->assertSame( 1, $code );
			$this->assertStringContainsString( 'A password prompt needs a terminal; pass --password=<password>.', $err );
			$this->assertFileDoesNotExist( $dir . '/out/database.sql' );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_extract_keeps_existing_files_unless_forced(): void {
		[ $name ] = self::export( 'b', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		$dir      = TempDir::create();
		try {
			WpSites::ok( 'b', 'lw-migration', 'extract', $name, '--dir=' . $dir . '/out' );
			$dump = (string) file_get_contents( $dir . '/out/database.sql' );
			file_put_contents( $dir . '/out/database.sql', 'mine' );

			[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'extract', $name, '--dir=' . $dir . '/out' );
			$this->assertSame( 0, $code, $err );
			$this->assertStringContainsString( 'Kept existing file database.sql (use --force to overwrite)', $err );
			$this->assertSame( 'mine', file_get_contents( $dir . '/out/database.sql' ) );

			WpSites::ok( 'b', 'lw-migration', 'extract', $name, '--dir=' . $dir . '/out', '--force' );
			$this->assertSame( $dump, file_get_contents( $dir . '/out/database.sql' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_delete_without_a_terminal_needs_yes(): void {
		[ $name ] = self::export( 'b', '--exclude-media', '--exclude-plugins', '--exclude-themes' );

		[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'delete', $name );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Pass --yes to delete without a prompt.', $err );
		$this->assertContains( $name, self::backups( 'b' ) );

		[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'delete', 'no-such-backup.wpress', '--yes' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'No backup named', $err );

		WpSites::ok( 'b', 'lw-migration', 'delete', $name, '--yes' );
		$this->assertNotContains( $name, self::backups( 'b' ) );
	}
}

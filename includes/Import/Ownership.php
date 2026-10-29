<?php
/**
 * File owners when the import runs as root.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI under sudo would leave root-owned files the web server cannot
 * update; files and folders we created as root get the owner and group of the
 * folder they are restored into. Paths owned by anyone else (existing site
 * folders and files) keep their owner. Links are never followed: a link inside
 * the base changes only itself.
 *
 * Callers pass the real path of the base (what PathGuard::resolve() builds its
 * targets from); forBase() refuses anything else.
 */
final class Ownership {

	public function __construct( private int $uid, private int $gid ) {
	}

	/**
	 * @param string $base Folder the files are restored into.
	 * @return self|null The base's owner when this process runs as root, otherwise null.
	 * @throws \InvalidArgumentException When $base is not its own real path.
	 */
	public static function forBase( string $base ): ?self {
		if ( realpath( $base ) !== $base ) {
			throw new \InvalidArgumentException( 'Pass the real path of the folder files are restored into.' );
		}
		if ( ! function_exists( 'posix_geteuid' ) || 0 !== posix_geteuid() ) {
			return null;
		}
		$uid = fileowner( $base );
		$gid = filegroup( $base );

		return false === $uid || false === $gid ? null : new self( $uid, $gid );
	}

	/**
	 * The file and every folder between the base and it that root owns; paths outside the base are left alone.
	 *
	 * @param string $base Folder the file was restored into.
	 * @param string $path Restored file.
	 * @return bool False when an owner or group could not be changed.
	 */
	public function apply( string $base, string $path ): bool {
		$base    = rtrim( $base, '/' );
		$ok      = true;
		$current = $path;
		while ( str_starts_with( $current, $base . '/' ) ) {
			$ok      = $this->change( $current ) && $ok;
			$current = dirname( $current );
		}

		return $ok;
	}

	/**
	 * @param string $path File, folder or link (the link itself, never its target).
	 * @return bool False when the path is gone or its owner or group could not be changed.
	 */
	private function change( string $path ): bool {
		$stat = file_exists( $path ) || is_link( $path ) ? lstat( $path ) : false;
		if ( false === $stat ) {
			return false;
		}
		if ( 0 !== $stat['uid'] ) {
			return true;
		}
		$ok = true;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is reported to the caller, which warns once.
		if ( $stat['uid'] !== $this->uid && ! @lchown( $path, $this->uid ) ) {
			$ok = false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is reported to the caller, which warns once.
		if ( $stat['gid'] !== $this->gid && ! @lchgrp( $path, $this->gid ) ) {
			$ok = false;
		}

		return $ok;
	}
}

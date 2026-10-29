<?php
/**
 * Whether a root process may change a folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

defined( 'ABSPATH' ) || exit;

/**
 * File resets (plugins, themes, media, and so a full reset) never run as
 * root: a tree another user can reach may change between any two checks, and
 * root would then delete what root may delete anywhere. Path and mode checks
 * keep leaving such races, so as root the reset is refused outright. An
 * unknown process user counts as root (fail closed); a system without POSIX
 * users (Windows) has none. WordPress-free.
 */
final class RootGuard {

	/** The effective user on a system without POSIX users: never root. */
	public const NO_USERS = -1;

	/**
	 * @param int|null $euid This process's user (null: unknown, treated as root; NO_USERS: never root).
	 */
	public function __construct( private ?int $euid ) {
	}

	public static function current(): self {
		$posix = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Linux without the POSIX extension; elsewhere there is no such file.
		$status  = null === $posix ? (string) @file_get_contents( '/proc/self/status' ) : '';
		$windows = 'Windows' === PHP_OS_FAMILY;

		return new self( self::euidOf( $posix, $status, null === $posix && '' === $status && ! $windows ? self::tmpOwner() : null, $windows ) );
	}

	/**
	 * This process's effective user: POSIX, then /proc, then the owner of a
	 * file it created; none of them = unknown (null, treated as root).
	 *
	 * @param int|null $posix    posix_geteuid(), or null without it.
	 * @param string   $status   /proc/self/status ('' = none).
	 * @param int|null $tmpOwner Owner of a temporary file this process created, or null.
	 * @param bool     $windows  Whether this is Windows (no POSIX users; its files report owner 0).
	 */
	public static function euidOf( ?int $posix, string $status, ?int $tmpOwner, bool $windows ): ?int {
		if ( null !== $posix ) {
			return $posix;
		}
		$proc = self::euidFrom( $status );
		if ( null !== $proc ) {
			return $proc;
		}

		return $windows ? self::NO_USERS : $tmpOwner;
	}

	/**
	 * The effective user of a /proc/self/status text (its Uid line: real,
	 * effective, saved, file system).
	 *
	 * @param string $status The file's text.
	 * @return int|null Null when it names none.
	 */
	public static function euidFrom( string $status ): ?int {
		return 1 === preg_match( '/^Uid:\s+\d+\s+(\d+)/m', $status, $m ) ? (int) $m[1] : null;
	}

	/**
	 * @return bool Whether this process is root, or its user is unknown.
	 */
	public function isRoot(): bool {
		return null === $this->euid || 0 === $this->euid;
	}

	/**
	 * @return int|null The owner of a temporary file this process creates (it is this process's user).
	 */
	private static function tmpOwner(): ?int {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failing temp folder must print no warning with its path; null is the answer.
		$handle = @tmpfile();
		$stat   = false === $handle ? false : fstat( $handle );
		if ( false !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return false === $stat ? null : (int) $stat['uid'];
	}
}

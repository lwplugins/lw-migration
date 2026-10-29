<?php
/**
 * `wp lw-migration backup share`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\SharesApi;
use LightweightPlugins\Migration\Rest\ShareRoutes;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Transfer links: a download of one backup without login, until it expires.
 */
final class ShareCommand {

	private const UNITS = [
		'm' => 60,
		'h' => 3600,
		'd' => 86400,
	];

	/**
	 * Creates a transfer link for a backup, lists the links, or revokes one.
	 *
	 * Anyone who has a transfer link can download the backup without logging
	 * in until the link expires, so another site can restore it
	 * (`wp lw-migration import '<link>'`). The link is shown only when it is
	 * created; a lost link means a new one.
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : Backup name (see `wp lw-migration list`).
	 *
	 * [--expires=<duration>]
	 * : How long the link works, from 5m to 7d (m = minutes, h = hours, d = days). Default: 24h.
	 *
	 * [--list]
	 * : List the transfer links instead.
	 *
	 * [--revoke=<id>]
	 * : Revoke the transfer link with this id instead (see --list).
	 *
	 * [--format=<format>]
	 * : Output format of --list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration backup share site-20261009-120000-001.wpress --expires=2h
	 *     wp lw-migration backup share --list
	 *     wp lw-migration backup share --revoke=0a1b2c3d
	 *
	 * @param array<int, string>   $args  Backup name.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$name   = $args[0] ?? null;
		$list   = isset( $assoc['list'] ) && false !== $assoc['list'];
		$revoke = $assoc['revoke'] ?? null;
		if ( 1 !== count( array_filter( [ null !== $name, $list, null !== $revoke ] ) ) ) {
			Console::error( __( 'Give a backup name, --list or --revoke=<id>.', 'lw-migration' ) );
			return;
		}
		$api = SharesApi::fromServices( Services::get() );
		if ( $list ) {
			self::listLinks( $api, (string) ( $assoc['format'] ?? 'table' ) );
		} elseif ( null !== $revoke ) {
			self::revoke( $api, (string) $revoke );
		} else {
			self::create( $api, (string) $name, $assoc['expires'] ?? null );
		}
	}

	/**
	 * @param SharesApi $api     Transfer links.
	 * @param string    $name    Backup name.
	 * @param mixed     $expires --expires value (null = the default).
	 */
	private static function create( SharesApi $api, string $name, $expires ): void {
		$backup = Services::get()->backups()->find( $name );
		if ( null === $backup ) {
			/* translators: %s: backup name */
			Console::error( sprintf( __( 'No backup named %s.', 'lw-migration' ), $name ) );
			return;
		}
		$body = [
			'folder' => $backup->source,
			'name'   => $backup->name,
		];
		if ( null !== $expires ) {
			$body['expires'] = self::seconds( $expires );
		}
		try {
			$created = $api->create( $body, get_current_user_id(), time(), ShareRoutes::address() );
		} catch ( RestException $e ) {
			Console::error( $e->getMessage() );
			return;
		} catch ( \RuntimeException $e ) {
			Console::error( __( 'The transfer link could not be saved. Check the database connection and try again.', 'lw-migration' ) );
			return;
		}
		Console::log( __( 'Transfer link (shown only now; anyone who has it can download this backup):', 'lw-migration' ) );
		Console::log( $created['url'] );
		/* translators: %s: date and time the link stops working */
		Console::log( sprintf( __( 'Expires: %s', 'lw-migration' ), wp_date( 'Y-m-d H:i', $created['expiresAt'] ) ) );
	}

	/**
	 * Seconds of a --expires value; any other value stops the command.
	 *
	 * @param mixed $value --expires value, e.g. 30m, 24h or 7d.
	 */
	private static function seconds( $value ): int {
		$seconds = is_string( $value ) && 1 === preg_match( '/^(\d{1,7})([mhd])\z/', $value, $m ) ? (int) $m[1] * self::UNITS[ $m[2] ] : 0;
		if ( $seconds < Shares::MIN_TTL || $seconds > Shares::MAX_TTL ) {
			Console::error( __( 'Give a time from 5m to 7d, for example 30m, 24h or 7d.', 'lw-migration' ) );
		}

		return $seconds;
	}

	/**
	 * @param SharesApi $api    Transfer links.
	 * @param string    $format table, json or csv.
	 */
	private static function listLinks( SharesApi $api, string $format ): void {
		$items = [];
		foreach ( $api->all( time() )['items'] as $item ) {
			$state   = $item['expired'] ? 'expired' : ( $item['available'] ? 'live' : 'missing' );
			$items[] = [
				'id'        => $item['id'],
				'backup'    => $item['name'],
				'folder'    => $item['folder'],
				'expires'   => wp_date( 'Y-m-d H:i', $item['expiresAt'] ),
				'uses'      => $item['uses'],
				'last_used' => $item['lastUsedAt'] > 0 ? wp_date( 'Y-m-d H:i', $item['lastUsedAt'] ) : '',
				'state'     => $state,
			];
		}
		Console::items( $format, $items, [ 'id', 'backup', 'folder', 'expires', 'uses', 'last_used', 'state' ] );
	}

	/**
	 * @param SharesApi $api Transfer links.
	 * @param string    $id  Link id.
	 */
	private static function revoke( SharesApi $api, string $id ): void {
		$revoked = false;
		if ( 1 === preg_match( Shares::ID_PATTERN, $id ) ) {
			try {
				$revoked = (bool) $api->revoke( $id )['revoked'];
			} catch ( RestException $e ) {
				$revoked = false; // No such link: the same answer as a malformed id.
			}
		}
		if ( ! $revoked ) {
			Console::error( __( 'No transfer link with this id.', 'lw-migration' ) );
			return;
		}
		Console::success( __( 'Revoked.', 'lw-migration' ) );
	}
}

<?php
/**
 * The shape of one stored connection.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

defined( 'ABSPATH' ) || exit;

/**
 * What a row of lw_migration_links must look like (Links stores only rows that
 * pass isRow()) and which rows are alive. Pure: no WordPress calls.
 */
final class LinkRow {

	public const MAX_LOGIN = 60;

	private const MAX_IP = 64;

	/**
	 * Unconfirmed links older than PENDING_TTL are gone for everyone, before they are pruned.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @param int                              $now  Unix time.
	 * @return array<int, array<string, mixed>>
	 */
	public static function live( array $rows, int $now ): array {
		return array_values( array_filter( $rows, static fn( array $row ): bool => $row['confirmed'] || $now - $row['createdAt'] < Links::PENDING_TTL ) );
	}

	/**
	 * The owner in its stored key order; anything else is returned as it is, for isRow() to refuse.
	 *
	 * @param mixed $owner Owner.
	 * @return mixed
	 */
	public static function owner( $owner ) {
		if ( is_array( $owner ) && isset( $owner['id'], $owner['login'] ) ) {
			return [
				'id'    => $owner['id'],
				'login' => $owner['login'],
			];
		}

		return $owner;
	}

	/**
	 * @param mixed $owner Owner.
	 * @phpstan-assert-if-true array{id: int, login: string} $owner
	 */
	public static function isOwner( $owner ): bool {
		return is_array( $owner ) && is_int( $owner['id'] ?? null ) && $owner['id'] > 0
			&& is_string( $owner['login'] ?? null ) && '' !== $owner['login'] && strlen( $owner['login'] ) <= self::MAX_LOGIN && self::utf8( $owner['login'] );
	}

	/**
	 * @param mixed $row Decoded row.
	 * @phpstan-assert-if-true array{id: string, direction: string, address: string, name: string, allow: array<int, string>, secret: string, createdAt: int, createdBy: array{id: int, login: string}, confirmed: bool, lastUsedAt: int, pairedFrom: string} $row
	 */
	public static function isRow( $row ): bool {
		if ( ! is_array( $row ) ) {
			return false;
		}
		foreach ( [ 'id', 'direction', 'address', 'name', 'secret', 'pairedFrom' ] as $key ) {
			if ( ! is_string( $row[ $key ] ?? null ) ) {
				return false;
			}
		}
		foreach ( [ 'createdAt', 'lastUsedAt' ] as $key ) {
			if ( ! is_int( $row[ $key ] ?? null ) ) {
				return false;
			}
		}

		return 1 === preg_match( Signer::LINK_PATTERN, $row['id'] )
			&& in_array( $row['direction'], [ Links::IN, Links::OUT ], true )
			&& strlen( $row['address'] ) <= Links::MAX_ADDRESS && 1 === preg_match( '#^https?://#', $row['address'] ) && 1 !== preg_match( '/[\x00-\x1f\x7f]/', $row['address'] )
			&& strlen( $row['name'] ) <= Links::MAX_TEXT
			&& self::utf8( $row['name'] ) && self::utf8( $row['address'] ) && self::utf8( $row['pairedFrom'] )
			&& is_array( $row['allow'] ?? null ) && [] !== $row['allow'] && Links::allow( $row['allow'] ) === $row['allow']
			&& self::isOwner( $row['createdBy'] ?? null )
			&& is_bool( $row['confirmed'] ?? null )
			&& strlen( $row['pairedFrom'] ) <= self::MAX_IP;
	}

	/**
	 * Whether a text survives json_encode (a failed encode must never become a stored empty value).
	 *
	 * @param string $text Text.
	 */
	public static function utf8( string $text ): bool {
		return 1 === preg_match( '//u', $text );
	}

	/**
	 * The stored list with the incoming links of the admin {$from, $login}
	 * pointed at $id: an import keeps the importing admin by login, usually
	 * under another id (ruling I3, Task 5 fix rounds 1–2). Only valid rows
	 * (isRow()) whose owner is exactly that admin move; a row of another
	 * user with the same login (a deleted admin's, cut off on purpose) and
	 * every other row stay as decoded.
	 *
	 * @param string $json  Stored value of lw_migration_links.
	 * @param int    $from  The admin's id before the import.
	 * @param string $login The admin's login (exact match).
	 * @param int    $id    Its id after the import.
	 * @return string|null The new value; null when nothing changes or the value is not a list.
	 */
	public static function reowned( string $json, int $from, string $login, int $id ): ?string {
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) || array_values( $rows ) !== $rows || '' === $login || $from <= 0 || $id <= 0 ) {
			return null;
		}
		$changed = false;
		foreach ( $rows as $i => $row ) {
			if ( self::isRow( $row ) && Links::IN === $row['direction'] && $from === $row['createdBy']['id'] && $login === $row['createdBy']['login'] && $from !== $id ) {
				$rows[ $i ]['createdBy']['id'] = $id;
				$changed                       = true;
			}
		}
		$value = $changed ? json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : false;

		return false === $value ? null : $value;
	}
}

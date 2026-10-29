<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Link;

use LightweightPlugins\Migration\Link\LinkRow;
use PHPUnit\Framework\TestCase;

/**
 * Fix rounds 1 and 2 of Task 5: an import keeps the importing admin by login,
 * usually under another id; exactly the incoming links that admin made
 * (its old id and its login) follow, nothing else.
 */
final class LinkRowTest extends TestCase {

	private static function row( string $id, string $direction, int $owner, string $login ): array {
		return [
			'id'         => $id,
			'direction'  => $direction,
			'address'    => 'https://a.example/ü',
			'name'       => 'A',
			'allow'      => [ 'push' ],
			'secret'     => 'sealed',
			'createdAt'  => 1,
			'createdBy'  => [
				'id'    => $owner,
				'login' => $login,
			],
			'confirmed'  => true,
			'lastUsedAt' => 0,
			'pairedFrom' => '',
		];
	}

	public function test_only_the_kept_admins_own_incoming_links_get_its_new_id(): void {
		$malformed                = self::row( '0000000000000005', 'in', 3, 'boss' );
		$malformed['allow']       = 'push';
		$rows = [
			self::row( '0000000000000001', 'in', 3, 'boss' ),
			self::row( '0000000000000002', 'in', 4, 'other' ),
			self::row( '0000000000000003', 'out', 3, 'boss' ),
			self::row( '0000000000000004', 'in', 99, 'boss' ),
			$malformed,
			[ 'broken' => true ],
		];

		$json = LinkRow::reowned( (string) json_encode( $rows ), 3, 'boss', 2 );

		$this->assertIsString( $json );
		$after = json_decode( $json, true );
		$rows[0]['createdBy']['id'] = 2;
		$this->assertSame( $rows, $after, 'Only {3, boss} in-link moves: not another login, not an out-link, not a stale {99, boss} row of a deleted admin, not a malformed row.' );
		$this->assertStringContainsString( 'https://a.example/ü', $json );
	}

	public function test_nothing_to_change_is_null(): void {
		$rows = [ self::row( '0000000000000001', 'in', 2, 'boss' ), self::row( '0000000000000002', 'in', 3, 'Boss' ), self::row( '0000000000000003', 'in', 99, 'boss' ) ];

		$this->assertNull( LinkRow::reowned( (string) json_encode( $rows ), 3, 'boss', 2 ), 'Logins match exactly; a stale id never moves.' );
		$this->assertNull( LinkRow::reowned( (string) json_encode( [ self::row( '0000000000000001', 'in', 3, 'boss' ) ] ), 3, 'boss', 3 ), 'Same id.' );
		$this->assertNull( LinkRow::reowned( (string) json_encode( [ self::row( '0000000000000001', 'in', 3, 'boss' ) ] ), 3, 'boss', 0 ) );
		$this->assertNull( LinkRow::reowned( 'not json', 3, 'boss', 2 ) );
		$this->assertNull( LinkRow::reowned( '{"a":1}', 3, 'boss', 2 ) );
		$this->assertNull( LinkRow::reowned( (string) json_encode( $rows ), 3, '', 2 ) );
	}
}

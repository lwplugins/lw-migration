<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\SiteRoles;
use PHPUnit\Framework\TestCase;

final class SiteRolesTest extends TestCase {

	public function test_network_rights_are_stripped_from_every_role(): void {
		$roles = serialize(
			[
				'administrator' => [
					'name'         => 'Administrator',
					'capabilities' => [
						'edit_users'           => true,
						'manage_network_users' => true,
						'Manage_Network'       => true,
						'create_sites'         => true,
					],
				],
				'shop'          => [
					'name'         => 'Shop',
					'capabilities' => [ 'upgrade_network' => true, 'read' => true ],
				],
			]
		);

		[ $clean, $changed ] = SiteRoles::clean( $roles );

		$this->assertTrue( $changed );
		$this->assertSame(
			[
				'administrator' => [
					'name'         => 'Administrator',
					'capabilities' => [ 'edit_users' => true ],
				],
				'shop'          => [
					'name'         => 'Shop',
					'capabilities' => [ 'read' => true ],
				],
			],
			unserialize( $clean )
		);
	}

	public function test_clean_roles_are_kept_as_they_are(): void {
		$this->assertSame( [ SiteRoles::CORE_DEFAULT, false ], SiteRoles::clean( SiteRoles::CORE_DEFAULT ) );
	}

	public function test_the_default_roles_are_wordpress_s_five_without_network_rights(): void {
		$roles = unserialize( SiteRoles::CORE_DEFAULT, [ 'allowed_classes' => false ] );

		$this->assertSame( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], array_keys( $roles ) );
		$this->assertCount( 61, $roles['administrator']['capabilities'] );
		$this->assertSame( [], array_intersect( SiteRoles::NETWORK_CAPS, array_keys( $roles['administrator']['capabilities'] ) ) );
	}

	public function test_unreadable_roles_become_the_default_roles(): void {
		foreach ( [ '', 'not serialized', 'O:8:"stdClass":0:{}', 's:3:"abc";', 'a:1:{', 'b:1;' ] as $value ) {
			$this->assertSame( [ SiteRoles::CORE_DEFAULT, true ], SiteRoles::clean( $value ), $value );
		}
	}

	public function test_objects_and_broken_roles_never_survive(): void {
		$value = 'a:3:{s:6:"editor";a:2:{s:4:"name";s:6:"Editor";s:12:"capabilities";a:2:{s:4:"read";b:1;s:1:"x";O:8:"stdClass":0:{}}}s:3:"bad";O:8:"stdClass":0:{}s:5:"empty";a:1:{s:4:"name";s:1:"E";}}';

		[ $clean, $changed ] = SiteRoles::clean( $value );

		$this->assertTrue( $changed );
		$this->assertStringNotContainsString( 'O:', $clean );
		$this->assertSame( [ 'editor' ], SiteRoles::names( $clean ) );
	}

	public function test_a_role_named_after_a_network_right_or_with_an_odd_key_is_dropped(): void {
		$value = serialize(
			[
				'manage_network_users' => [ 'name' => 'Sneaky', 'capabilities' => [ 'read' => true ] ],
				'Manage_Sites'         => [ 'name' => 'Sneaky', 'capabilities' => [ 'read' => true ] ],
				'bad key'              => [ 'name' => 'Odd', 'capabilities' => [ 'read' => true ] ],
				str_repeat( 'a', 65 )  => [ 'name' => 'Long', 'capabilities' => [ 'read' => true ] ],
				'shop-manager_2'       => [ 'name' => 'Shop', 'capabilities' => [ 'read' => true ] ],
			]
		);

		[ $clean ] = SiteRoles::clean( $value );

		$this->assertSame( [ 'shop-manager_2' ], SiteRoles::names( $clean ) );
	}

	public function test_a_network_right_is_never_a_granted_role(): void {
		$this->assertFalse( SiteRoles::grantsOnly( 'a:1:{s:20:"manage_network_users";b:1;}', [ 'manage_network_users' ] ) );
		$this->assertFalse( SiteRoles::grantsOnly( 'a:1:{s:14:"MANAGE_NETWORK";b:1;}', [ 'MANAGE_NETWORK' ] ) );
	}

	public function test_exactly_one_stored_row_is_read_any_other_count_is_the_default(): void {
		$editor = serialize( [ 'editor' => [ 'name' => 'Editor', 'capabilities' => [ 'read' => true ] ] ] );

		$this->assertSame( [ SiteRoles::CORE_DEFAULT, true ], SiteRoles::fromRows( [] ), 'Missing roles.' );
		$this->assertSame( [ SiteRoles::CORE_DEFAULT, true ], SiteRoles::fromRows( [ $editor, $editor ] ), 'Two rows: WordPress would read the last one.' );
		$this->assertSame( [ $editor, false ], SiteRoles::fromRows( [ $editor ] ) );
	}

	public function test_a_users_roles_may_only_name_granted_roles_of_the_site(): void {
		$site = [ 'administrator', 'editor' ];

		$this->assertTrue( SiteRoles::grantsOnly( 'a:1:{s:6:"editor";b:1;}', $site ) );
		$this->assertTrue( SiteRoles::grantsOnly( 'a:0:{}', $site ) );
		$this->assertFalse( SiteRoles::grantsOnly( 'a:1:{s:20:"manage_network_users";b:1;}', $site ), 'A raw capability.' );
		$this->assertFalse( SiteRoles::grantsOnly( 'a:2:{s:13:"administrator";b:1;s:10:"edit_users";b:1;}', $site ), 'A role plus a raw capability.' );
		$this->assertFalse( SiteRoles::grantsOnly( 'a:1:{s:6:"author";b:1;}', $site ), 'Not a role of the site.' );
		$this->assertFalse( SiteRoles::grantsOnly( 'a:1:{s:6:"editor";i:1;}', $site ), 'Only true grants.' );
		$this->assertFalse( SiteRoles::grantsOnly( 'a:1:{s:6:"editor";O:8:"stdClass":0:{}}', $site ), 'An object is refused.' );
		$this->assertFalse( SiteRoles::grantsOnly( 'O:8:"stdClass":1:{s:6:"editor";b:1;}', $site ) );
		$this->assertFalse( SiteRoles::grantsOnly( 'garbage', $site ) );
	}

	public function test_role_names_of_an_unreadable_value_are_none(): void {
		$this->assertSame( [], SiteRoles::names( 'x' ) );
		$this->assertSame( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ], SiteRoles::names( SiteRoles::CORE_DEFAULT ) );
	}
}

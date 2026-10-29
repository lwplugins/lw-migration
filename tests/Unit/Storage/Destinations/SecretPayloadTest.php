<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\SecretPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretPayloadTest extends TestCase {

	private const BINDING = 'aa11bb22';

	public function test_a_bound_value_reads_back_for_its_binding_or_an_unchecked_caller(): void {
		$payload = SecretPayload::wrap( 'abcd1234', 'password', self::BINDING, "p\x01ss" );

		$this->assertSame( "p\x01ss", SecretPayload::unwrap( 'abcd1234', 'password', self::BINDING, $payload ) );
		$this->assertSame( "p\x01ss", SecretPayload::unwrap( 'abcd1234', 'password', null, $payload ) );
		$this->assertNull( SecretPayload::unwrap( 'abcd1234', 'password', 'cc33dd44', $payload ) );
	}

	public function test_an_unbound_value_has_the_layout_values_had_before_bindings_and_reads_for_any_binding(): void {
		$payload = SecretPayload::wrap( 'abcd1234', 'password', null, 'pass' );

		$this->assertSame( "abcd1234\0password\0pass", $payload );
		$this->assertSame( 'pass', SecretPayload::unwrap( 'abcd1234', 'password', self::BINDING, $payload ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function foreign(): array {
		return [
			'another profile'          => [ SecretPayload::wrap( 'ffff0000', 'password', self::BINDING, 'pass' ) ],
			'another field'            => [ SecretPayload::wrap( 'abcd1234', 'passphrase', self::BINDING, 'pass' ) ],
			'a field the name extends' => [ SecretPayload::wrap( 'abcd1234', 'password2', null, 'pass' ) ],
			'a binding without an end' => [ "abcd1234\0password\x01" . self::BINDING ],
			'an unknown marker'        => [ "abcd1234\0password\x02pass" ],
		];
	}

	#[DataProvider( 'foreign' )]
	public function test_a_value_of_another_profile_or_field_or_a_malformed_one_does_not_read( string $payload ): void {
		$this->assertNull( SecretPayload::unwrap( 'abcd1234', 'password', self::BINDING, $payload ) );
	}
}

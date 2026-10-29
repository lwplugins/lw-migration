<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use LightweightPlugins\Migration\Rest\DestinationInput;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Tests\Support\FakeKeyedType;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DestinationInputTest extends MonkeyTestCase {

	private static function registry(): DestinationRegistry {
		return new DestinationRegistry( [ new S3Type() ] );
	}

	private static function field( ?Profile $current, array $body ): string {
		try {
			DestinationInput::apply( $current, self::registry(), $body );
		} catch ( RestException $e ) {
			return (string) ( $e->data['field'] ?? '' );
		}
		return 'accepted';
	}

	public function test_unknown_keys_and_wrong_types_name_their_field(): void {
		$current = new Profile( 'abcd1234', 's3', 'P', [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'bkt', 'accessKey' => 'AKIAEXAMPLE1' ] );

		$this->assertSame( 'password', self::field( $current, [ 'password' => 'x' ] ) );
		$this->assertSame( 'type', self::field( $current, [ 'type' => 'ftp' ] ), 'The type never changes.' );
		$this->assertSame( 'settings', self::field( $current, [ 'settings' => 'x' ] ) );
		$this->assertSame( 'secretKey', self::field( $current, [ 'secrets' => [ 'secretKey' => [ 'x' ] ] ] ) );
		$this->assertSame( 'accessKey', self::field( $current, [ 'secrets' => [ 'accessKey' => 'x' ] ] ), 'Only secret fields go to the vault.' );
		$this->assertSame( 'count', self::field( $current, [ 'retention' => [ 'count' => -1 ] ] ), 'ScheduleInput::retention() names the number.' );
		$this->assertSame( 'retention', self::field( $current, [ 'retention' => [ 'weeks' => 1 ] ] ) );
		$this->assertSame( 'secretKey', self::field( $current, [ 'secrets' => [ 'secretKey' => str_repeat( 'x', 16385 ) ] ] ) );
	}

	public function test_an_update_keeps_what_it_does_not_send(): void {
		$current = new Profile( 'abcd1234', 's3', 'P', [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'bkt', 'prefix' => '', 'pathStyle' => false, 'accessKey' => 'AKIAEXAMPLE1' ] );

		$out = DestinationInput::apply( $current, self::registry(), [ 'name' => 'New name' ], [ 'secretKey' ] );

		$this->assertSame( [ 'New name', $current->settings, [] ], [ $out['profile']->name, $out['profile']->settings, $out['secrets'] ] );
	}

	public function test_a_refusal_trace_never_carries_the_submitted_secret(): void {
		$current = new Profile( 'abcd1234', 's3', 'P', [ 'preset' => 'aws', 'region' => 'us-east-1', 'bucket' => 'bkt', 'accessKey' => 'AKIAEXAMPLE1' ] );
		$before  = ini_set( 'zend.exception_ignore_args', '0' );
		try {
			DestinationInput::apply( $current, self::registry(), [ 'type' => 'ftp', 'secrets' => [ 'secretKey' => 'TRACE-SECRET-0000' ] ] );
			$this->fail( 'Refused.' );
		} catch ( RestException $e ) {
			$this->assertSame( 'type', $e->data['field'] );
			$frames = array_filter( $e->getTrace(), static fn( array $f ): bool => DestinationInput::class === ( $f['class'] ?? '' ) );
			$this->assertNotEmpty( $frames );
			$this->assertStringNotContainsString( 'TRACE-SECRET-0000', print_r( array_column( $frames, 'args' ), true ) );
		} finally {
			ini_set( 'zend.exception_ignore_args', (string) $before );
		}
	}

	/**
	 * @param array<string, mixed> $secrets Secrets.
	 * @return array<string, mixed>
	 */
	private static function keyBody( array $secrets ): array {
		return [
			'type'     => FakeKeyedType::KEY,
			'name'     => 'Box',
			'settings' => [
				'host' => 'example.com',
				'auth' => 'key',
			],
			'secrets'  => $secrets,
		];
	}

	public function test_a_required_secret_counts_only_while_its_field_is_shown(): void {
		$out = DestinationInput::apply( null, FakeKeyedType::registry(), self::keyBody( [ 'privateKey' => "-----BEGIN KEY-----\r\nabc\r\n-----END KEY-----\r\n" ] ) );

		$this->assertSame( [ 'privateKey' => "-----BEGIN KEY-----\nabc\n-----END KEY-----" ], $out['secrets'], 'No password for a key login; line ends unified, ends trimmed.' );
		try {
			DestinationInput::apply( null, FakeKeyedType::registry(), self::keyBody( [] ) );
			$this->fail( 'The shown key is required.' );
		} catch ( RestException $e ) {
			$this->assertSame( 'privateKey', $e->data['field'] );
		}
	}

	public function test_the_default_of_a_setting_decides_what_is_shown(): void {
		$body = [
			'type'     => FakeKeyedType::KEY,
			'name'     => 'Box',
			'settings' => [ 'host' => 'example.com' ],
			'secrets'  => [],
		];

		try {
			DestinationInput::apply( null, FakeKeyedType::registry(), $body );
			$this->fail( 'Password login by default: the password is required.' );
		} catch ( RestException $e ) {
			$this->assertSame( 'password', $e->data['field'] );
		}
	}

	public function test_switching_a_profile_to_key_login_needs_the_key_unless_it_is_stored(): void {
		$current = new Profile( 'abcd1234', FakeKeyedType::KEY, 'Box', [ 'host' => 'example.com', 'auth' => 'password' ] );
		$body    = [ 'settings' => [ 'host' => 'example.com', 'auth' => 'key' ] ];

		try {
			DestinationInput::apply( $current, FakeKeyedType::registry(), $body, [ 'password' ] );
			$this->fail( 'The newly shown key is required.' );
		} catch ( RestException $e ) {
			$this->assertSame( 'privateKey', $e->data['field'] );
		}
		$this->assertSame( [], DestinationInput::apply( $current, FakeKeyedType::registry(), $body, [ 'password', 'privateKey' ] )['secrets'] );
	}

	public function test_a_secret_of_sixteen_kib_is_taken_and_a_longer_one_refused(): void {
		$key = str_repeat( 'k', 16384 );

		$this->assertSame( $key, DestinationInput::apply( null, FakeKeyedType::registry(), self::keyBody( [ 'privateKey' => $key ] ) )['secrets']['privateKey'] );
		$this->expectException( RestException::class );
		DestinationInput::apply( null, FakeKeyedType::registry(), self::keyBody( [ 'privateKey' => $key . 'k' ] ) );
	}

	public function test_a_changed_client_id_forgets_the_connection_and_the_tokens_a_new_secret_does_not(): void {
		$registry            = new DestinationRegistry( [ new FakeOAuthType() ] );
		$current             = new Profile( 'abcd1234', 'fakeoauth', 'D', [ 'clientId' => 'old' ] );
		$current->connection = [ 'account' => 'a@example.com' ];

		$changed = DestinationInput::apply( $current, $registry, [ 'settings' => [ 'clientId' => 'new' ], 'secrets' => [ 'clientSecret' => 'new-secret' ] ], [ 'clientSecret' ] );
		$secret  = DestinationInput::apply( $current, $registry, [ 'settings' => [ 'clientId' => 'old' ], 'secrets' => [ 'clientSecret' => 'rotated' ] ], [ 'clientSecret' ] );
		$name    = DestinationInput::apply( $current, $registry, [ 'name' => 'E' ], [ 'clientSecret' ] );
		$created = DestinationInput::apply( null, $registry, [ 'type' => 'fakeoauth', 'name' => 'N', 'settings' => [ 'clientId' => 'c' ], 'secrets' => [ 'clientSecret' => 's' ] ] );

		$this->assertSame( [], $changed['profile']->connection );
		$this->assertSame( [ 'refreshToken', 'accessToken', 'grant', 'identity' ], $changed['forget'] );
		$this->assertSame( [ 'account' => 'a@example.com' ], $secret['profile']->connection );
		$this->assertSame( [], $secret['forget'] );
		$this->assertSame( [], $name['forget'] );
		$this->assertSame( [], $created['forget'] );
	}

	/**
	 * O7: tokens are vault fields no request may set; the connect marker is no setting.
	 */
	public function test_token_fields_are_refused_and_the_connect_marker_is_never_a_setting(): void {
		$registry = new DestinationRegistry( [ new FakeOAuthType() ] );
		$current  = new Profile( 'abcd1234', 'fakeoauth', 'D', [ 'clientId' => 'c' ] );

		foreach ( [ 'refreshToken', 'accessToken', 'grant', 'account' ] as $field ) {
			try {
				DestinationInput::apply( $current, $registry, [ 'secrets' => [ $field => 'x' ] ], [ 'clientSecret' ] );
				$this->fail( 'Refused: ' . $field );
			} catch ( RestException $e ) {
				$this->assertSame( [ 400, $field ], [ $e->status, $e->data['field'] ?? '' ] );
			}
		}
		$out = DestinationInput::apply( $current, $registry, [ 'settings' => [ 'clientId' => 'c', 'account' => 'x', 'refreshToken' => 'r' ] ], [ 'clientSecret' ] );
		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'c' ], $out['profile']->settings );
		$this->assertSame( 'clientSecret', self::oauthField( $registry, [ 'type' => 'fakeoauth', 'name' => 'N', 'settings' => [ 'clientId' => 'c' ] ] ), 'The client secret is required.' );
	}

	private static function oauthField( DestinationRegistry $registry, array $body ): string {
		try {
			DestinationInput::apply( null, $registry, $body );
		} catch ( RestException $e ) {
			return (string) ( $e->data['field'] ?? '' );
		}
		return 'accepted';
	}

	/**
	 * Review Focus 5: a mode switch forgets the connection and the tokens, both ways.
	 */
	public function test_switching_the_mode_forgets_the_connection_and_the_tokens(): void {
		$registry        = new DestinationRegistry( [ new FakeOAuthType() ] );
		$own             = new Profile( 'abcd1234', 'fakeoauth', 'D', [ 'ownApp' => true, 'clientId' => 'c' ] );
		$lw              = new Profile( 'abcd1235', 'fakeoauth', 'L', [ 'ownApp' => false ] );
		$own->connection = [ 'account' => 'a@example.com' ];
		$lw->connection  = [ 'account' => 'b@example.com' ];

		$toLw  = DestinationInput::apply( $own, $registry, [ 'settings' => [ 'ownApp' => false ] ], [ 'clientSecret' ] );
		$toOwn = DestinationInput::apply( $lw, $registry, [ 'settings' => [ 'ownApp' => true, 'clientId' => 'c' ], 'secrets' => [ 'clientSecret' => 's' ] ], [ 'relayAnchor' ] );
		$same  = DestinationInput::apply( $lw, $registry, [ 'name' => 'M', 'settings' => [ 'ownApp' => false ] ], [ 'relayAnchor' ] );

		$this->assertSame( [ [], [ 'refreshToken', 'accessToken', 'grant', 'identity' ] ], [ $toLw['profile']->connection, $toLw['forget'] ] );
		$this->assertSame( [ 'ownApp' => false ], $toLw['profile']->settings );
		$this->assertSame( [ [], [ 'refreshToken', 'accessToken', 'grant', 'identity' ] ], [ $toOwn['profile']->connection, $toOwn['forget'] ] );
		$this->assertSame( [], $same['forget'] );
		$this->assertSame( 'clientSecret', self::oauthField( $registry, [ 'type' => 'fakeoauth', 'name' => 'N', 'settings' => [ 'ownApp' => true, 'clientId' => 'c' ] ] ), 'The own app needs its secret.' );
		$this->assertSame( [], DestinationInput::apply( null, $registry, [ 'type' => 'fakeoauth', 'name' => 'N', 'settings' => [ 'ownApp' => false ] ] )['secrets'], 'The LW app needs none.' );
	}
}

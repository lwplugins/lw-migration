<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Tests\Support\FakeDestination;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DestinationsTest extends MonkeyTestCase {

	/** @var array<string, string>|null */
	public static ?array $opened = null;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		self::$opened = null;
	}

	private static function destinations( ?SecretVault $vault = null ): Destinations {
		$type = new class() implements DestinationType {
			public function key(): string {
				return 'fake';
			}
			public function label(): string {
				return 'Fake';
			}
			public function unavailable(): ?string {
				return null;
			}
			public function fields(): array {
				return [ new Field( 'secretKey', Field::SECRET, 'Secret', true ) ];
			}
			public function settings( array $raw ): array {
				return $raw;
			}
			public function make( Profile $profile, array $secrets ): DestinationInterface {
				DestinationsTest::$opened = $secrets;
				return new FakeDestination();
			}
			public function describe( Profile $profile ): string {
				return 'fake';
			}
			public function insecure( Profile $profile ): bool {
				return false;
			}
		};

		return new Destinations( new ProfileRepository( static fn(): int => 1000 ), $vault ?? new SecretVault(), new DestinationRegistry( [ $type ] ) );
	}

	public function test_opens_a_profile_with_its_secrets(): void {
		$d = self::destinations();
		$d->profiles()->save( new Profile( 'abcd1234', 'fake', 'P' ) );
		$d->vault()->put( 'abcd1234', [ 'secretKey' => 'k' ] );

		$d->open( $d->find( 'abcd1234' ) );

		$this->assertSame( [ 'secretKey' => 'k' ], self::$opened );
	}

	public function test_a_secret_that_no_longer_opens_marks_the_profile_reauth(): void {
		$d = self::destinations();
		$d->profiles()->save( new Profile( 'abcd1234', 'fake', 'P', [], [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], Profile::OK ) );
		$d->vault()->put( 'abcd1234', [ 'secretKey' => 'k' ] );
		Functions\when( 'wp_salt' )->justReturn( 'salt-two' );

		try {
			self::destinations()->open( $d->find( 'abcd1234' ) );
			$this->fail( 'A reauth failure is thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
		$this->assertSame( Profile::REAUTH, $d->find( 'abcd1234' )->status );
		$this->assertNull( self::$opened );
	}

	/**
	 * Review m1: a server without sodium cannot open any secret; that is the
	 * server's fault, not the stored credentials', so the profile keeps its state.
	 */
	public function test_without_sodium_the_destination_is_invalid_not_reauth(): void {
		$d = self::destinations();
		$d->profiles()->save( new Profile( 'abcd1234', 'fake', 'P', [], [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], Profile::OK ) );
		$d->vault()->put( 'abcd1234', [ 'secretKey' => 'k' ] );

		try {
			self::destinations( new SecretVault( null, false ) )->open( $d->find( 'abcd1234' ) );
			$this->fail( 'Without sodium nothing opens.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'sodium', $e->getMessage() );
		}
		$this->assertSame( [ Profile::OK, '' ], [ $d->find( 'abcd1234' )->status, $d->find( 'abcd1234' )->message ] );
		$this->assertNull( self::$opened );
	}

	public function test_an_unknown_type_is_invalid_and_forget_removes_both_rows(): void {
		$d = self::destinations();
		$d->profiles()->save( new Profile( 'abcd1234', 'gone', 'P' ) );
		$d->vault()->put( 'abcd1234', [ 'secretKey' => 'k' ] );

		try {
			$d->open( $d->find( 'abcd1234' ) );
			$this->fail( 'An unknown type cannot open.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertTrue( $d->forget( 'abcd1234' ) );
		$this->assertNull( $d->find( 'abcd1234' ) );
		$this->assertSame( [], $d->vault()->fields( 'abcd1234' ) );
	}
}

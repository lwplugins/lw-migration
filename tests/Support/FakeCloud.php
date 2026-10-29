<?php
/**
 * Stored destination profiles backed by in-memory destinations.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;

/**
 * Needs FakeOptions::install() and a wp_salt stub first; installs FakeWpdb
 * (profiles, secrets and copies are option rows read through $wpdb) unless
 * the test installed one already. Every profile gets type "fake", a sealed
 * secret and its own FakeDestination in $remotes, which the type hands out
 * (so a test reaches the "remote side").
 */
final class FakeCloud {

	/** @var array<string, FakeDestination|FakeFileDestination|FakeResumableDestination> Tests may swap in a FakeResumableDestination. */
	public static array $remotes = [];

	/**
	 * Runs whenever a destination is opened (after the caller's own checks,
	 * before its work), so a test can change the world in between.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $onOpen = null;

	/**
	 * @param array<string, string> $profiles Id => name.
	 * @param int                   $chunk    Part size of the fake destinations.
	 */
	public static function install( array $profiles, int $chunk = 5 ): Destinations {
		if ( ! ( ( $GLOBALS['wpdb'] ?? null ) instanceof FakeWpdb ) ) {
			FakeWpdb::install();
		}
		self::$remotes = [];
		self::$onOpen  = null;
		$type          = new class() implements DestinationType {
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
				return [ new Field( 'token', Field::SECRET, 'Token', true ) ];
			}
			public function settings( array $raw ): array {
				return $raw;
			}
			public function make( Profile $profile, #[\SensitiveParameter] array $secrets ): DestinationInterface {
				if ( null !== FakeCloud::$onOpen ) {
					( FakeCloud::$onOpen )();
				}
				return FakeCloud::$remotes[ $profile->id ];
			}
			public function describe( Profile $profile ): string {
				return 'fake:' . $profile->id;
			}
			public function insecure( Profile $profile ): bool {
				return false;
			}
		};
		$destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), new DestinationRegistry( [ $type ] ) );
		foreach ( $profiles as $id => $name ) {
			$destinations->profiles()->save( new Profile( $id, 'fake', $name ) );
			$destinations->vault()->put( $id, [ 'token' => 'secret-token-' . $id ] );
			self::$remotes[ $id ] = new FakeDestination( $chunk );
		}

		return $destinations;
	}
}

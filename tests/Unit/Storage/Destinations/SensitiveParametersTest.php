<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Rest\DestinationInput;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Storage\Destinations\DestinationType;
use LightweightPlugins\Migration\Storage\Destinations\S3\SigV4Signer;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Support\SecretBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every parameter that carries a plain secret is #[\SensitiveParameter], so a
 * stack trace (PHP 8.2+) never prints it.
 */
final class SensitiveParametersTest extends TestCase {

	#[DataProvider( 'parameters' )]
	public function test_a_parameter_that_carries_a_secret_is_marked_sensitive( string $class, string $method, string $parameter ): void {
		$found = null;
		foreach ( ( new \ReflectionMethod( $class, $method ) )->getParameters() as $candidate ) {
			if ( $candidate->getName() === $parameter ) {
				$found = $candidate;
			}
		}

		$this->assertNotNull( $found, $parameter );
		$this->assertCount( 1, $found->getAttributes( \SensitiveParameter::class ), $class . '::' . $method . '( $' . $parameter . ' )' );
	}

	/**
	 * @return array<string, array{0: class-string, 1: string, 2: string}>
	 */
	public static function parameters(): array {
		return [
			'SecretBox::seal'       => [ SecretBox::class, 'seal', 'plain' ],
			'DestinationType::make' => [ DestinationType::class, 'make', 'secrets' ],
			'SecretVault::put'      => [ SecretVault::class, 'put', 'values' ],
			'SigV4Signer secret'    => [ SigV4Signer::class, '__construct', 'secretKey' ],
			'REST create body'      => [ DestinationsApi::class, 'create', 'body' ],
			'REST update body'      => [ DestinationsApi::class, 'update', 'body' ],
			'REST input body'       => [ DestinationInput::class, 'apply', 'body' ],
		];
	}
}

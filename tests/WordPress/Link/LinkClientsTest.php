<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LinkClientsTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private const OUT = '0123456789abcdef';

	private const GONE = 'This connection was removed or no longer works on this site. Pair the two sites again.';

	private FakeWpdb $db;

	private Links $links;

	private string $key;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::stubCache();
		$this->db    = new FakeWpdb();
		$this->links = new Links( $this->db, new SecretBox( str_repeat( 'k', 32 ) ) );
		$this->key   = random_bytes( 32 );
		$this->add( self::OUT, Links::OUT, true );
	}

	private function add( string $id, string $direction, bool $confirmed, ?Links $links = null ): void {
		( $links ?? $this->links )->add(
			[
				'id'         => $id,
				'direction'  => $direction,
				'address'    => 'https://b.example',
				'name'       => 'Site B',
				'allow'      => [ 'pull', 'push' ],
				'createdBy'  => [ 'id' => 3, 'login' => 'me' ],
				'pairedFrom' => '',
			],
			$this->key,
			self::NOW - 10,
			$confirmed
		);
	}

	private function clients( ?Links $links = null ): LinkClients {
		return new LinkClients( $links ?? $this->links, static fn(): int => self::NOW );
	}

	private function assertGone( \Closure $call, string $label ): void {
		try {
			$call();
		} catch ( LinkException $e ) {
			$this->assertSame( [ LinkException::GONE, self::GONE ], [ $e->reason, $e->getMessage() ], $label );
			return;
		}
		$this->fail( 'GONE expected: ' . $label );
	}

	public function test_a_confirmed_outgoing_link_gives_a_client_that_signs_with_its_key(): void {
		$client = $this->clients()->forLink( self::OUT );

		$this->assertSame( [ self::OUT, 'https://b.example' ], [ $client->id(), $client->address() ] );
		$headers = $client->headers( 'POST', '/lw-migration/v1/link/hello', '' );
		$parts   = Signer::parse( $headers );
		$this->assertNotNull( $parts );
		$this->assertSame( self::NOW, $parts['time'], 'The client uses the injected clock.' );
		$this->assertTrue( Signer::verify( $parts, $this->key, 'POST', '/lw-migration/v1/link/hello', '', self::NOW ) );
	}

	public function test_every_link_that_cannot_send_is_gone(): void {
		$this->add( 'aaaaaaaaaaaaaaaa', Links::IN, true );
		$this->add( 'bbbbbbbbbbbbbbbb', Links::OUT, false );
		$other = new Links( $this->db, new SecretBox( str_repeat( 'x', 32 ) ) ); // The salt changed: no secret opens.

		$cases = [
			'incoming'    => fn() => $this->clients()->forLink( 'aaaaaaaaaaaaaaaa' ),
			'unconfirmed' => fn() => $this->clients()->forLink( 'bbbbbbbbbbbbbbbb' ),
			'no key'      => fn() => $this->clients( $other )->forLink( self::OUT ),
			'unknown'     => fn() => $this->clients()->forLink( 'cccccccccccccccc' ),
			'malformed'   => fn() => $this->clients()->forLink( '../../x' ),
			'upper case'  => fn() => $this->clients()->forLink( strtoupper( self::OUT ) ),
			'view'        => fn() => $this->clients()->view( 'aaaaaaaaaaaaaaaa' ),
		];
		foreach ( $cases as $label => $call ) {
			$this->assertGone( $call, $label );
		}
	}

	public function test_a_job_names_its_link_in_its_state_or_its_options(): void {
		$this->add( 'dddddddddddddddd', Links::OUT, true );
		$byState  = new Job( 'eeeeeeeeeeeeeeee', 'push', [], 0, [], [ 'location' => 'dddddddddddddddd' ], [ 'link' => [ 'id' => self::OUT ] ] );
		$byOption = new Job( 'eeeeeeeeeeeeeeee', 'pull', [], 0, [], [ 'location' => 'dddddddddddddddd' ] );
		$neither  = new Job( 'eeeeeeeeeeeeeeee', 'pull', [], 0, [], [], [ 'link' => [ 'id' => 5 ] ] );

		$this->assertSame( self::OUT, $this->clients()->forJob( $byState )->id() );
		$this->assertSame( 'dddddddddddddddd', $this->clients()->forJob( $byOption )->id() );
		$this->assertGone( fn() => $this->clients()->forJob( $neither ), 'no link' );
	}

	public function test_the_view_never_carries_the_key(): void {
		$view = $this->clients()->view( self::OUT );

		$this->assertSame(
			[
				'id'      => self::OUT,
				'name'    => 'Site B',
				'address' => 'https://b.example',
				'allow'   => [ 'pull', 'push' ],
			],
			$view
		);
		$this->assertStringNotContainsString( bin2hex( $this->key ), (string) json_encode( $view ) );
	}
}

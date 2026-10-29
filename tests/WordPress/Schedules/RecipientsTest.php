<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Recipients;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class RecipientsTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install( [ 'admin_email' => 'admin@site.test' ] );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
	}

	public function test_a_list_is_split_checked_and_deduplicated(): void {
		$this->assertSame( [ 'a@x.test', 'B@y.test' ], Recipients::parse( " a@x.test;B@y.test,\n a@X.test " ) );
		$this->assertSame( 'a@x.test, B@y.test', Recipients::normalize( 'a@x.test B@y.test' ) );
		$this->assertSame( [], Recipients::parse( '  ' ) );
	}

	public function test_an_invalid_address_or_too_many_are_refused(): void {
		foreach ( [ 'a@x.test, not-an-address', 'a@x.test%0Abcc:evil@x.test', 'a@1.test b@1.test c@1.test d@1.test e@1.test f@1.test' ] as $list ) {
			try {
				Recipients::parse( $list );
				$this->fail( 'Accepted: ' . $list );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'e-mail addresses', $e->getMessage() );
			}
		}
	}

	public function test_the_schedule_address_then_the_default_then_the_admin(): void {
		$this->assertSame( [ 'ops@x.test' ], Recipients::resolve( 'ops@x.test' ) );
		$this->assertSame( [ 'admin@site.test' ], Recipients::resolve( '' ) );
		FakeOptions::$values[ Options::NOTIFICATIONS ] = [ 'email' => 'team@x.test' ];
		$this->assertSame( 'team@x.test', Recipients::defaultAddress() );
		$this->assertSame( [ 'team@x.test' ], Recipients::resolve( '' ) );
		$this->assertSame( [ 'team@x.test' ], Recipients::resolve( 'broken' ), 'A broken stored address falls back.' );
	}
}

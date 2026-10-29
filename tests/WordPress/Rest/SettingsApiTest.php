<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\SettingsApi;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SettingsApiTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install(
			[
				Options::RETENTION => [
					'count' => 5,
					'days'  => 30,
				],
			]
		);
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
	}

	public function test_retention_round_trip_keeps_untouched_keys(): void {
		$this->assertSame( [ 'retention' => [ 'count' => 5, 'bytes' => 0, 'days' => 30 ], 'notifications' => [ 'email' => '' ] ], SettingsApi::get() );
		$this->assertSame( [ 'retention' => [ 'count' => 5, 'bytes' => 1073741824, 'days' => 0 ], 'notifications' => [ 'email' => '' ] ], SettingsApi::save( [ 'retention' => [ 'bytes' => 1073741824, 'days' => 0 ] ] ) );
		$this->assertSame( [ 'count' => 5, 'bytes' => 1073741824, 'days' => 0 ], FakeOptions::$values[ Options::RETENTION ] );
	}

	public function test_a_missing_option_reads_as_no_limits(): void {
		FakeOptions::install();

		$this->assertSame( [ 'retention' => [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], 'notifications' => [ 'email' => '' ] ], SettingsApi::get() );
	}

	public function test_wrong_values_are_refused_and_nothing_is_saved(): void {
		$bodies = [
			[],
			[ 'retention' => 5 ],
			[ 'retention' => [ 'count' => -1 ] ],
			[ 'retention' => [ 'days' => '7' ] ],
			[ 'retention' => [ 'days' => 7.0 ] ],
			[ 'retention' => [ 'count' => true ] ],
			[ 'retention' => [ 'count' => 10001 ] ],
			[ 'retention' => [ 'days' => 36501 ] ],
			[ 'retention' => [ 'count' => 3, 'maxAge' => 1 ] ],
			[ 'retention' => [ 'count' => 3 ], 'other' => 1 ],
		];
		foreach ( $bodies as $body ) {
			try {
				SettingsApi::save( $body );
				$this->fail( 'Accepted: ' . json_encode( $body ) );
			} catch ( RestException $e ) {
				$this->assertSame( 400, $e->status );
				$this->assertSame( ErrorMap::INVALID, $e->errorCode );
			}
		}
		$this->assertSame( [ 'count' => 5, 'days' => 30 ], FakeOptions::$values[ Options::RETENTION ] );
	}

	public function test_the_largest_values_are_accepted(): void {
		$this->assertSame(
			[
				'retention'     => [ 'count' => 10000, 'bytes' => 1125899906842624, 'days' => 36500 ],
				'notifications' => [ 'email' => '' ],
			],
			SettingsApi::save( [ 'retention' => [ 'count' => 10000, 'bytes' => 1125899906842624, 'days' => 36500 ] ] )
		);
	}

	public function test_the_default_notification_address_is_saved_normalised(): void {
		$saved = SettingsApi::save( [ 'notifications' => [ 'email' => 'a@x.test; B@y.test' ] ] );

		$this->assertSame( [ 'email' => 'a@x.test, B@y.test' ], $saved['notifications'] );
		$this->assertSame( [ 'email' => 'a@x.test, B@y.test' ], FakeOptions::$values[ Options::NOTIFICATIONS ] );
		$this->assertSame( [ 'email' => '' ], SettingsApi::save( [ 'notifications' => [ 'email' => '' ] ] )['notifications'], 'Emptied: the site admin gets the mails.' );
	}

	public function test_an_invalid_address_is_refused_at_its_field_and_nothing_is_saved(): void {
		$bodies = [
			[
				'retention'     => [ 'count' => 9 ],
				'notifications' => [ 'email' => 'nope' ],
			],
			[ 'notifications' => [ 'email' => 5 ] ],
			[ 'notifications' => [ 'email' => 'a@x.test', 'cc' => 'b@x.test' ] ],
			[ 'notifications' => [ 'email' => 'a@1.test, b@1.test, c@1.test, d@1.test, e@1.test, f@1.test' ] ],
			[ 'notifications' => [ 'email' => 'a@x.test' . str_repeat( ' ', 1017 ) ] ],
		];
		foreach ( $bodies as $body ) {
			try {
				SettingsApi::save( $body );
				$this->fail( 'Accepted: ' . json_encode( $body ) );
			} catch ( RestException $e ) {
				$this->assertSame( [ 400, 'email' ], [ $e->status, $e->data['field'] ?? null ] );
			}
		}
		$this->assertSame( [ 'count' => 5, 'days' => 30 ], FakeOptions::$values[ Options::RETENTION ] );
		$this->assertArrayNotHasKey( Options::NOTIFICATIONS, FakeOptions::$values );
	}

	public function test_a_list_of_up_to_1024_bytes_is_read(): void {
		$this->assertSame( [ 'email' => 'a@x.test' ], SettingsApi::save( [ 'notifications' => [ 'email' => 'a@x.test' . str_repeat( ' ', 1016 ) ] ] )['notifications'] );
	}
}

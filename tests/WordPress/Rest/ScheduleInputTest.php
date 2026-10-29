<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\ScheduleInput;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ScheduleInputTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( strip_tags( $text ) ) );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
	}

	/**
	 * @param array<string, mixed> $body    Request.
	 * @param array<string, mixed> $current Stored fields.
	 */
	private static function apply( array $body, array $current = [] ): Schedule {
		return ScheduleInput::apply(
			Schedule::fromArray( array_merge( [ 'id' => '0a1b2c3d', 'title' => 'Stored' ], $current ) ),
			$body,
			static fn( string $plain ): string => 'sealed(' . $plain . ')'
		);
	}

	public function test_only_the_keys_sent_change(): void {
		$s = self::apply(
			[
				'title'     => '  <b>Weekly</b> ',
				'frequency' => 'weekly',
				'weekday'   => 7,
				'hour'      => 22,
				'minute'    => 30,
				'notify'    => [ 'when' => 'always' ],
				'retention' => [ 'days' => 30 ],
			],
			[
				'notify'    => [ 'email' => 'ops@x.test' ],
				'retention' => [ 'count' => 5 ],
			]
		);

		$this->assertSame( [ 'Weekly', 'weekly', 7, 22, 30 ], [ $s->title, $s->frequency, $s->weekday, $s->hour, $s->minute ] );
		$this->assertSame( [ 'always', 'ops@x.test' ], [ $s->notifyWhen, $s->notifyEmail ] );
		$this->assertSame( [ 'count' => 5, 'bytes' => 0, 'days' => 30 ], $s->retention );
	}

	public function test_the_password_is_sealed_kept_or_forgotten(): void {
		$sealed = self::apply(
			[
				'export'   => [ 'encrypt' => true ],
				'password' => 'pw',
			]
		);
		$this->assertSame( 'sealed(pw)', $sealed->password );

		$kept = self::apply( [ 'enabled' => true ], $sealed->toArray() );
		$this->assertSame( 'sealed(pw)', $kept->password, 'No password key: the stored one stays.' );

		$off = self::apply( [ 'export' => [ 'encrypt' => false ] ], $sealed->toArray() );
		$this->assertSame( '', $off->password, 'Protection off: the password is forgotten.' );
	}

	public function test_export_options_are_checked_by_the_backup_section_rules(): void {
		$s = self::apply(
			[
				'export' => [
					'noCache'      => true,
					'compression'  => 'bzip2',
					'excludeFiles' => [ 'uploads/big' ],
				],
			]
		);

		$this->assertSame( [ true, 'bzip2', [ 'uploads/big' ] ], [ $s->export['noCache'], $s->export['compression'], $s->export['excludeFiles'] ] );
		$this->assertArrayNotHasKey( 'kind', $s->export );
		$this->assertArrayNotHasKey( 'noFiles', $s->export );
	}

	public function test_wrong_values_are_refused_at_their_field(): void {
		$cases = [
			[ [ 'color' => 'red' ], 'color' ],
			[ [ 'title' => '' ], 'title' ],
			[ [ 'title' => str_repeat( 'x', 101 ) ], 'title' ],
			[ [ 'title' => [ 'x' ] ], 'title' ],
			[ [ 'enabled' => 1 ], 'enabled' ],
			[ [ 'frequency' => 'yearly' ], 'frequency' ],
			[ [ 'minute' => 60 ], 'minute' ],
			[ [ 'hour' => '3' ], 'hour' ],
			[ [ 'weekday' => 0 ], 'weekday' ],
			[ [ 'monthday' => 32 ], 'monthday' ],
			[ [ 'frequency' => 'hours', 'every' => 1 ], 'every' ],
			[ [ 'frequency' => 'days', 'every' => 91 ], 'every' ],
			[ [ 'retention' => [ 'count' => -1 ] ], 'count' ],
			[ [ 'retention' => [ 'keep' => 1 ] ], 'retention' ],
			[ [ 'notify' => [ 'when' => 'sometimes' ] ], 'when' ],
			[ [ 'notify' => [ 'email' => 'not an address' ] ], 'email' ],
			[ [ 'notify' => [ 'email' => 'a@x.test, b@x.test, c@x.test, d@x.test, e@x.test, f@x.test' ] ], 'email' ],
			[ [ 'notify' => [ 'email' => 5 ] ], 'email' ],
			[ [ 'notify' => [ 'to' => 'a@x.test' ] ], 'notify' ],
			[ [ 'export' => [ 'kind' => 'pre-import' ] ], 'options' ],
			[ [ 'export' => [ 'encrypt' => true ] ], 'password' ],
			[ [ 'password' => [ 'pw' ] ], 'password' ],
		];
		foreach ( $cases as [ $body, $field ] ) {
			try {
				self::apply( $body );
				$this->fail( 'Accepted: ' . json_encode( $body ) );
			} catch ( RestException $e ) {
				$this->assertSame( [ 400, $field ], [ $e->status, $e->data['field'] ?? null ], json_encode( $body ) );
			}
		}
	}

	public function test_the_addresses_are_checked_and_normalised_like_the_mail_sends_them(): void {
		$s = self::apply( [ 'notify' => [ 'email' => ' ops@x.test; OPS@x.test  dev@x.test ' ] ] );

		$this->assertSame( 'ops@x.test, dev@x.test', $s->notifyEmail );
		$this->assertSame( '', self::apply( [ 'notify' => [ 'email' => '' ] ], [ 'notify' => [ 'email' => 'ops@x.test' ] ] )->notifyEmail, 'Empty: the default address.' );
	}

	public function test_destinations_need_existing_profiles_and_keep_local_a_boolean(): void {
		FakeOptions::install();
		$db = FakeWpdb::install();
		( new ProfileRepository() )->save( new Profile( 'aaaa1111', 's3', 'One' ) );

		$s = self::apply( [ 'destinations' => [ [ 'id' => 'aaaa1111', 'retention' => [ 'count' => 3 ] ] ], 'keepLocal' => false ] );

		$this->assertSame( [ [ 'id' => 'aaaa1111', 'retention' => [ 'count' => 3, 'bytes' => 0, 'days' => 0 ] ] ], $s->destinations );
		$this->assertFalse( $s->keepLocal );
		$this->assertSame( [ [ 'id' => 'aaaa1111', 'retention' => [ 'count' => 7, 'bytes' => 0, 'days' => 0 ] ] ], self::apply( [ 'destinations' => [ [ 'id' => 'aaaa1111' ] ] ] )->destinations );
		$this->assertTrue( self::apply( [ 'title' => 'Kept' ] )->keepLocal, 'Missing keepLocal keeps the stored (default) value.' );
		$this->assertNotSame( [], $db->rows, 'The profile is read from the option row.' );
		foreach (
			[
				[ 'destinations' => [ [ 'id' => 'ffff0000' ] ] ],
				[ 'destinations' => [ [ 'id' => 'aaaa1111' ], [ 'id' => 'aaaa1111' ] ] ],
				[ 'destinations' => [ [ 'id' => 'AAAA1111' ] ] ],
				[ 'destinations' => [ [ 'id' => 'aaaa1111', 'keep' => 1 ] ] ],
				[ 'destinations' => [ [ 'id' => 'aaaa1111', 'retention' => [ 'count' => -1 ] ] ] ],
				[ 'destinations' => array_fill( 0, Schedule::MAX_DESTINATIONS + 1, [ 'id' => 'aaaa1111' ] ) ],
				[ 'destinations' => 'x' ],
				[ 'keepLocal' => 'no' ],
			] as $body
		) {
			try {
				self::apply( $body );
				$this->fail( 'Refused: ' . json_encode( $body ) );
			} catch ( RestException $e ) {
				$this->assertSame( [ 400, array_key_first( $body ) ], [ $e->status, $e->data['field'] ?? '' ], json_encode( $body ) );
			}
		}
	}
}

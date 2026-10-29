<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Schedules\ScheduleException;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SealedPasswordTest extends MonkeyTestCase {

	public function test_a_sealed_password_opens_only_under_the_same_salts(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt-a' );
		$sealed = ( new SealedPassword() )->seal( 'correct horse' );

		$this->assertStringStartsWith( 'lwm1:', $sealed );
		$this->assertStringNotContainsString( 'correct horse', $sealed );
		$this->assertSame( 'correct horse', ( new SealedPassword() )->open( $sealed ) );

		Functions\when( 'wp_salt' )->justReturn( 'salt-b' );
		$this->assertNull( ( new SealedPassword() )->open( $sealed ), 'Changed security keys: it no longer opens.' );
	}

	public function test_nothing_or_garbage_opens_as_null(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt-a' );

		$this->assertNull( ( new SealedPassword() )->open( '' ) );
		$this->assertNull( ( new SealedPassword() )->open( 'plain text' ) );
	}

	public function test_without_sodium_nothing_is_sealed_or_opened(): void {
		$this->assertNull( ( new SealedPassword( null, false ) )->open( 'lwm1:abc' ) );
		$this->expectException( ScheduleException::class );
		( new SealedPassword( null, false ) )->seal( 'pw' );
	}

	public function test_a_value_sealed_under_the_job_secret_key_does_not_open(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt-a' );
		$jobSecret = ( new SecretBox( hash_hmac( 'sha256', 'lw-migration/job-secret/v1', 'salt-a', true ) ) )->seal( 'pw' );

		$this->assertNull( ( new SealedPassword() )->open( $jobSecret ), 'The schedule password has a key of its own.' );
	}
}

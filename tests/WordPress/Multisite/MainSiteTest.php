<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Multisite;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class MainSiteTest extends MonkeyTestCase {

	public function test_a_single_site_is_always_the_context(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'is_main_site' )->never();

		$this->assertTrue( MainSite::isContext() );
	}

	public function test_only_the_main_site_is_the_context_on_a_network(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\expect( 'is_main_site' )->twice()->with( null, 1 )->andReturn( true, false );

		$this->assertTrue( MainSite::isContext() );
		$this->assertFalse( MainSite::isContext() );
	}

	public function test_the_main_site_of_a_secondary_network_is_not_the_context(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		// The current blog is the main site of network 2: is_main_site() alone would say yes.
		Functions\when( 'is_main_site' )->alias( static fn( $site = null, $network = null ): bool => 2 === $network || null === $network );

		$this->assertFalse( MainSite::isContext() );
	}

	public function test_the_cli_message_names_the_main_site(): void {
		$this->assertStringContainsString( 'main site', MainSite::cliMessage() );
	}

	#[RunInSeparateProcess]
	public function test_the_rest_error_is_a_403_with_its_own_code(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';

		$error = MainSite::restError();

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'lw_migration_main_site', $error->get_error_code() );
		$this->assertSame( [ 'status' => 403 ], $error->get_error_data() );
		$this->assertNotSame( '', $error->get_error_message() );
	}
}

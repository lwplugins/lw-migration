<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit;

use LightweightPlugins\Migration\Options;
use PHPUnit\Framework\TestCase;

/**
 * Spec 21.3: "a lw_migration_destinations és lw_migration_destination_secrets sorok
 * kimaradnak a database.sql-ből; import után a céloldal saját profiljai maradnak".
 * Both follow from the shared prefix: DatabaseStep leaves DUMP_EXCLUDED_PREFIXES out
 * of every dump, PreserveStep/SwapStep carry the same prefixes over an import.
 */
final class OptionsTest extends TestCase {

	public function test_every_own_option_is_left_out_of_a_dump_and_kept_by_an_import(): void {
		$this->assertContains( Options::DESTINATIONS, Options::all() );
		$this->assertContains( Options::DESTINATION_SECRETS, Options::all() );
		$this->assertContains( Options::CLOUD_COPIES, Options::all() );
		foreach ( Options::all() as $name ) {
			$this->assertStringStartsWith( Options::DUMP_EXCLUDED_PREFIXES[0], $name );
		}
		$this->assertSame( [ 'lw_migration_destinations', 'lw_migration_destination_secrets', 'lw_migration_cloud_copies' ], [ Options::DESTINATIONS, Options::DESTINATION_SECRETS, Options::CLOUD_COPIES ] );
	}

	public function test_the_size_estimate_is_not_carried_over_a_restore_or_a_reset(): void {
		$this->assertSame( [ Options::EXPORT_ESTIMATE ], Options::NOT_CARRIED, 'It describes the site as it was.' );
		$this->assertSame( [], array_diff( Options::NOT_CARRIED, Options::all() ), 'Still removed on uninstall.' );
	}
}

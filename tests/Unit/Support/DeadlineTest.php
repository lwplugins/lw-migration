<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Support\TimeDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase {

	public function test_no_deadline_is_never_reached(): void {
		$this->assertFalse( ( new NoDeadline() )->reached() );
	}

	public function test_time_deadline(): void {
		$this->assertTrue( ( new TimeDeadline( 0.0 ) )->reached() );
		$this->assertFalse( ( new TimeDeadline( 60.0 ) )->reached() );
	}

	public function test_countdown_deadline(): void {
		$d = new CountdownDeadline( 2 );
		$this->assertFalse( $d->reached() );
		$this->assertFalse( $d->reached() );
		$this->assertTrue( $d->reached() );
	}

	public function test_config_entries(): void {
		$this->assertTrue( Format::isConfigEntry( 'package.json' ) );
		$this->assertTrue( Format::isConfigEntry( 'multisite.json' ) );
		$this->assertFalse( Format::isConfigEntry( 'uploads/package.json' ) );
		$this->assertFalse( Format::isConfigEntry( 'database.sql' ) );
	}
}

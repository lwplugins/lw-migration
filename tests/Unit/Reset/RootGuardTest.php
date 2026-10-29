<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\RootGuard;
use PHPUnit\Framework\TestCase;

final class RootGuardTest extends TestCase {

	public function test_root_and_an_unknown_user_count_as_root(): void {
		$this->assertTrue( ( new RootGuard( 0 ) )->isRoot() );
		$this->assertTrue( ( new RootGuard( null ) )->isRoot(), 'Fail closed.' );
		$this->assertFalse( ( new RootGuard( 33 ) )->isRoot() );
		$this->assertFalse( ( new RootGuard( RootGuard::NO_USERS ) )->isRoot(), 'A system without POSIX users has no root.' );
	}

	public function test_the_effective_user_is_found_or_fails_closed(): void {
		$this->assertSame( 0, RootGuard::euidFrom( "Name:\tphp\nUid:\t1000\t0\t0\t0\nGid:\t1000\t1000\t1000\t1000\n" ), 'The second Uid field is the effective one.' );
		$this->assertSame( 33, RootGuard::euidFrom( "Uid:\t33\t33\t33\t33\n" ) );
		$this->assertNull( RootGuard::euidFrom( '' ) );
		$this->assertSame( 7, RootGuard::euidOf( 7, '', 9, false ), 'POSIX first.' );
		$this->assertSame( 0, RootGuard::euidOf( null, "Uid:\t1\t0\t0\t0\n", 9, false ), 'Then /proc.' );
		$this->assertSame( 9, RootGuard::euidOf( null, '', 9, false ), 'Then the owner of a file this process created.' );
		$this->assertNull( RootGuard::euidOf( null, '', null, false ), 'Unknown: treated as root.' );
		$this->assertSame( RootGuard::NO_USERS, RootGuard::euidOf( null, '', 0, true ), 'Windows has no root (its files report owner 0).' );
	}

	public function test_this_process_is_found(): void {
		$expected = function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
		$this->assertSame( $expected, RootGuard::current()->isRoot() );
	}
}

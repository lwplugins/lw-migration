<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\InsertCheck;
use LightweightPlugins\Migration\Database\SqlValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InsertCheckTest extends TestCase {

	private function check(): InsertCheck {
		return new InsertCheck( 'lwt_' );
	}

	public function test_dumper_output_is_accepted(): void {
		$values = [
			SqlValue::encode( null, 'longtext' ),
			SqlValue::encode( '42', 'bigint(20) unsigned' ),
			SqlValue::encode( '-0', 'double' ),
			SqlValue::encode( '1.5E+20', 'double' ),
			SqlValue::encode( '-1.25e-7', 'float' ),
			SqlValue::encode( '-12.50', 'decimal(10,2)' ),
			SqlValue::encode( "\x00\xff'\\", 'longblob' ),
			SqlValue::encode( '', 'blob' ),
			SqlValue::encode( "it's \\ a \"test\"\n\r\x1a\x00 ) VALUES (", 'longtext' ),
			SqlValue::encode( '1', 'bit(1)' ),
		];
		$sql = rtrim( DumpFormat::insert( 'lwt_options', $values ) );
		$this->assertTrue( $this->check()->isSafe( $sql ), $sql );
	}

	public function test_the_dump_writer_writes_hex_with_a_lower_case_prefix(): void {
		foreach ( [ 'longblob', 'varbinary(16)', 'BINARY(4)', 'geometry' ] as $type ) {
			$this->assertSame( '0xabcdef', SqlValue::encode( "\xab\xcd\xef", $type ), $type );
		}
	}

	public function test_non_ascii_byte_continues_an_identifier(): void {
		foreach ( [ "1\xc3\xa9", "NULL\xc3\xa9", "TRUE\xff", "0x1f\x80", "DEFAULT\xc3\xa9" ] as $literal ) {
			$this->assertFalse( $this->check()->isSafe( "INSERT INTO `lwt_x` VALUES ({$literal})" ), bin2hex( $literal ) );
		}
		$this->assertTrue( $this->check()->isSafe( "INSERT INTO `lwt_x` VALUES (1,'\xc3\xa9')" ) );
		$this->assertTrue( $this->check()->isSafe( 'INSERT INTO `lwt_x` (`a`,`b`) VALUES (NULL,2)' ) );
	}

	public function test_every_literal_kind_is_accepted(): void {
		$sql = "insert  into\n`lwt_x` ( `a` , b,`c``d` )\nvalues\t(1,+2,-3.5,.5,6.,1e3,1E-3,0xFF,0xab,NULL,null,TRUE,false,DEFAULT,'s',\"d\",_utf8mb4'u',_binary 'b',N'n',X'0a',x'0A',b'101',B'1','a''b','c\\'d') ,\n (2,'x')";
		$this->assertTrue( $this->check()->isSafe( $sql ) );
		$this->assertTrue( $this->check()->isSafe( 'INSERT INTO `lwt_x` VALUES (1);' ) );
		$this->assertTrue( $this->check()->isSafe( 'INSERT INTO `lwt_x` VALUES (1) ; ' ) );
		$this->assertFalse( $this->check()->isSafe( 'INSERT INTO `LWT_x` VALUES (1);' ), 'Task 7 security review: the temporary prefix only in its exact case.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refused(): array {
		$p = '`lwt_x`';

		return [
			'vcomment'       => [ "INSERT INTO {$p} VALUES (1,'a') /*!50000 ; DROP TABLE wp_live */;" ],
			'two statements' => [ "INSERT INTO {$p} VALUES (1,'a'), (2,'b'); DROP TABLE wp_live;" ],
			'subquery'       => [ "INSERT INTO {$p} VALUES (10,(SELECT v FROM lwm_other.secret LIMIT 1));" ],
			'comment select' => [ "INSERT INTO {$p} (id, v /* ) VALUES */) SELECT id, v FROM lwm_other.secret;" ],
			'odku'           => [ "INSERT INTO {$p} VALUES (1,'a') ON DUPLICATE KEY UPDATE v=(SELECT v FROM wp_live LIMIT 1);" ],
			'function'       => [ "INSERT INTO {$p} VALUES (20, lwm_test.lwm_f());" ],
			'builtin fn'     => [ "INSERT INTO {$p} VALUES (now());" ],
			'hash comment'   => [ "INSERT INTO {$p} VALUES (1,'a') # ; DROP TABLE wp_live\n;" ],
			'dash comment'   => [ "INSERT INTO {$p} VALUES (1,'a') -- x\n;" ],
			'variable'       => [ "INSERT INTO {$p} VALUES (@x);" ],
			'identifier'     => [ "INSERT INTO {$p} VALUES (1abc);" ],
			'column ref'     => [ "INSERT INTO {$p} VALUES (`a`);" ],
			'expression'     => [ "INSERT INTO {$p} VALUES (1+1);" ],
			'adjacent str'   => [ "INSERT INTO {$p} VALUES ('a' 'b');" ],
			'prefix only'    => [ "INSERT INTO {$p} VALUES (_utf8mb4 1);" ],
			'empty tuple'    => [ "INSERT INTO {$p} VALUES ();" ],
			'trailing comma' => [ "INSERT INTO {$p} VALUES (1),;" ],
			'unterminated'   => [ "INSERT INTO {$p} VALUES ('a);" ],
			'set form'       => [ "INSERT INTO {$p} SET a=1;" ],
			'select form'    => [ "INSERT INTO {$p} SELECT * FROM wp_users;" ],
			'qualified'      => [ 'INSERT INTO `lwt_x`.`wp_live` VALUES (1);' ],
			'not tmp'        => [ 'INSERT INTO `wp_live` VALUES (1);' ],
			'unquoted name'  => [ 'INSERT INTO lwt_x VALUES (1);' ],
			'ignore'         => [ "INSERT IGNORE INTO {$p} VALUES (1);" ],
			'partition'      => [ "INSERT INTO {$p} PARTITION (p0) VALUES (1);" ],
			'row alias'      => [ "INSERT INTO {$p} VALUES (1) AS new;" ],
			'column comment' => [ "INSERT INTO {$p} (a /* x */) VALUES (1);" ],
			'upper 0X hex'   => [ "INSERT INTO {$p} VALUES (0Xab);" ], // MySQL reads 0X… as an identifier, not a hex literal.
		];
	}

	#[DataProvider( 'refused' )]
	public function test_refused( string $sql ): void {
		$this->assertFalse( $this->check()->isSafe( $sql ) );
	}

	public function test_twenty_megabytes_are_checked_in_linear_time(): void {
		$row  = "(123456,'" . str_repeat( 'lorem ipsum \\\' dolor ', 20 ) . "',NULL,0xdeadbeef,-1.5E+20,'2026-10-06 12:00:00')";
		$sql  = 'INSERT INTO `lwt_big` VALUES ' . implode( ',', array_fill( 0, (int) ( 20971520 / strlen( $row ) ) + 1, $row ) ) . ';';
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$mem  = memory_get_usage();
		$time = microtime( true );
		$this->assertTrue( $this->check()->isSafe( $sql ) );
		$this->assertLessThan( 1.0, microtime( true ) - $time );
		$this->assertGreaterThan( 20971520, strlen( $sql ) );
		$this->assertLessThan( 1048576, memory_get_peak_usage() - $mem - strlen( $sql ) );

		$one = 'INSERT INTO `lwt_big` VALUES (' . "'" . str_repeat( 'x', 20971520 ) . "');";
		$time = microtime( true );
		$this->assertTrue( $this->check()->isSafe( $one ) );
		$this->assertLessThan( 1.0, microtime( true ) - $time );
	}
}

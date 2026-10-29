<?php
/**
 * What a database error code means, without the server's own text.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Server error messages quote the failing SQL and its values ("near '…'",
 * "Duplicate entry '…'"), and no pattern removes them reliably (escaped or
 * unbalanced quotes). QueryException's message, and so every warning, job
 * error and job.log line, names an error only by its code and a fixed
 * description from this list.
 */
final class ServerError {

	/** Code => fixed description. */
	private const KNOWN = [
		1021 => 'the disk is full',
		1030 => 'storage engine error',
		1054 => 'unknown column',
		1062 => 'duplicate entry',
		1064 => 'SQL syntax error',
		1114 => 'the table is full',
		1146 => 'missing table',
		1180 => 'the commit failed',
		1205 => 'lock wait timeout',
		1213 => 'deadlock',
		1226 => 'a resource limit of the database user was reached',
		1356 => 'the view references a missing table, column or function',
		1366 => 'incorrect value',
		1406 => 'data too long',
		1451 => 'another table references it',
		1813 => 'the tablespace already exists',
		2006 => 'the connection to the database server was lost',
		2013 => 'the connection to the database server was lost',
		3730 => 'another table references it',
		4031 => 'the connection to the database server was lost',
	];

	/**
	 * @param int $code Server error number.
	 */
	public static function describe( int $code ): string {
		return self::KNOWN[ $code ] ?? 'server error';
	}
}

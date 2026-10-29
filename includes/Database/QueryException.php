<?php
/**
 * A failed SQL statement.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the server error number and the statement. The message is only
 * "Database error <code> (<fixed description>)." (ServerError): the server's
 * own text quotes SQL and row values, so it is kept apart in serverText() for
 * decisions in code and is never shown, logged or saved.
 */
class QueryException extends DatabaseException {

	/**
	 * @var int
	 */
	private int $errno;

	/**
	 * @var string
	 */
	private string $sql;

	/**
	 * @var string
	 */
	private string $serverText;

	/**
	 * @param string $serverText The server's error text (never shown).
	 * @param int    $errno      Server error number.
	 * @param string $sql        Statement.
	 */
	public function __construct( string $serverText, int $errno, string $sql ) {
		parent::__construct( sprintf( 'Database error %d (%s).', $errno, ServerError::describe( $errno ) ), $errno );
		$this->errno      = $errno;
		$this->sql        = $sql;
		$this->serverText = $serverText;
	}

	/** The server's own text: for decisions in code only, never for output. */
	public function serverText(): string {
		return $this->serverText;
	}

	public function errno(): int {
		return $this->errno;
	}

	public function sql(): string {
		return $this->sql;
	}
}

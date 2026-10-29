<?php
/**
 * Run ids of the temporary import tables this site created.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Several sites may share one database, and lwtXXXX_ tables of another site's
 * import look exactly like ours. Each import registers its run id (the XXXX)
 * here before it creates a table, and the daily sweep drops only registered
 * runs. One non-autoloaded row holding a JSON map run id => registration time,
 * changed by compare-and-swap (OptionRow), so concurrent changes merge.
 * The lw_migration_ prefix keeps it out of dumps and carries it over the swap.
 */
final class TmpRunRegistry {

	private const ID_PATTERN = '/^[a-z0-9]{4}\z/';

	private const TRIES = 10;

	/**
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null $db Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?object $db = null ) {
		$this->row = new OptionRow( Options::TMP_RUNS, $db );
	}

	/**
	 * @return array<string, int> Run id => registration time.
	 */
	public function all(): array {
		return self::decode( $this->row->read() );
	}

	/**
	 * @return array<int, string> Registered run ids (as strings: PHP turns "1234" keys into integers).
	 */
	public function ids(): array {
		return array_map( 'strval', array_keys( $this->all() ) );
	}

	/**
	 * @param string $runId Four lower-case letters or digits.
	 * @param int    $now   Unix time.
	 * @return bool False for an invalid id or when the row cannot be written.
	 */
	public function register( string $runId, int $now ): bool {
		if ( 1 !== preg_match( self::ID_PATTERN, $runId ) ) {
			return false;
		}

		return $this->change( static fn( array $runs ): array => $runs + [ $runId => $now ] );
	}

	/**
	 * @param string $runId Run id.
	 * @return bool False when the row cannot be written.
	 */
	public function forget( string $runId ): bool {
		return $this->change(
			static function ( array $runs ) use ( $runId ): array {
				unset( $runs[ $runId ] );
				return $runs;
			}
		);
	}

	/**
	 * @param \Closure $edit fn( array<string, int> ): array<string, int>.
	 */
	private function change( \Closure $edit ): bool {
		for ( $i = 0; $i < self::TRIES; $i++ ) {
			$raw  = $this->row->read();
			$runs = self::decode( $raw );
			$new  = $edit( $runs );
			if ( $new === $runs && null !== $raw && self::encode( $runs ) === $raw ) {
				return true;
			}
			if ( [] === $new && null === $raw ) {
				return true;
			}
			if ( null === $raw ) {
				$done = $this->row->insert( self::encode( $new ) );
			} elseif ( [] === $new ) {
				$done = $this->row->deleteIf( $raw );
			} else {
				$done = self::encode( $new ) === $raw || $this->row->swap( $raw, self::encode( $new ) );
			}
			if ( $done ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string|null $raw Stored value.
	 * @return array<string, int>
	 */
	private static function decode( ?string $raw ): array {
		$value = null === $raw ? null : json_decode( $raw, true );
		$runs  = [];
		foreach ( is_array( $value ) ? $value : [] as $id => $time ) {
			if ( 1 === preg_match( self::ID_PATTERN, (string) $id ) && is_int( $time ) ) {
				$runs[ (string) $id ] = $time;
			}
		}

		return $runs;
	}

	/**
	 * @param array<string, int> $runs Run id => time.
	 */
	private static function encode( array $runs ): string {
		return (string) wp_json_encode( (object) $runs );
	}
}

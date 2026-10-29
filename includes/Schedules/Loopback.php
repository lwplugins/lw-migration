<?php
/**
 * The request that runs the next slice of a scheduled job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 8: a one-time, job-bound token, checked with hash_equals. One row
 * {job, hash, expires} (OptionRow, never autoloaded) holds the only valid
 * token; only its SHA-256 is stored. Issuing replaces the previous token;
 * redeeming deletes the row by compare-and-delete, so a replay or a second
 * concurrent use of the same token fails. The request is sent like
 * WordPress' own cron spawn: non-blocking, 0.01 s, certificate check through
 * core's https_local_ssl_verify filter. It carries the job id and the token
 * only, and its answer carries no job data (Rest\ScheduleRoutes).
 */
final class Loopback {

	public const TTL = 600;

	public const ROUTE = '/schedules/continue';

	private const JOB_PATTERN = '/^[a-f0-9]{16}\z/';

	private const TOKEN_PATTERN = '/^[a-f0-9]{32}\z/';

	/**
	 * The token row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null $db Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?object $db = null ) {
		$this->row = new OptionRow( Options::LOOPBACK, $db );
	}

	/**
	 * @param string $jobId Job the next slice belongs to.
	 * @param int    $now   Unix time.
	 * @return string|null The token, or null when a concurrent request wrote the row first (its request goes instead).
	 */
	public function issue( string $jobId, int $now ): ?string {
		$token = bin2hex( random_bytes( 16 ) );
		$value = (string) json_encode(
			[
				'job'     => $jobId,
				'hash'    => hash( 'sha256', $token ),
				'expires' => $now + self::TTL,
			]
		);
		$raw   = $this->row->read();
		$saved = null === $raw ? $this->row->insert( $value ) : $this->row->swap( $raw, $value );

		return $saved ? $token : null;
	}

	/**
	 * @param string $jobId Job id from the request.
	 * @param string $token Token from the request.
	 * @param int    $now   Unix time.
	 * @return bool True exactly once per issued token, before it expires.
	 */
	public function redeem( string $jobId, string $token, int $now ): bool {
		if ( 1 !== preg_match( self::JOB_PATTERN, $jobId ) || 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return false;
		}
		$raw  = $this->row->read();
		$data = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || ( $data['job'] ?? null ) !== $jobId || ! is_int( $data['expires'] ?? null ) || $data['expires'] <= $now || ! is_string( $data['hash'] ?? null ) ) {
			return false;
		}

		return hash_equals( $data['hash'], hash( 'sha256', $token ) ) && $this->row->deleteIf( (string) $raw );
	}

	public function send( string $jobId, string $token ): void {
		/**
		 * Address of the request that continues a scheduled backup (default: this
		 * site's REST route through index.php, which works without pretty
		 * permalinks and without a rewritten /wp-json/). For servers that reach
		 * themselves only under another address.
		 *
		 * @param string $url Address.
		 */
		$url = (string) apply_filters( 'lw_migration_loopback_url', add_query_arg( 'rest_route', '/' . Routes::NAMESPACE . self::ROUTE, site_url( 'index.php' ) ) );
		wp_remote_post(
			$url,
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter for requests to the site itself, as in spawn_cron().
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false, $url ),
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => (string) wp_json_encode(
					[
						'job'   => $jobId,
						'token' => $token,
					]
				),
			]
		);
	}
}

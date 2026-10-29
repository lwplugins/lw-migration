<?php
/**
 * Stores jobs as <base>/<id>/job.json.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic saves (write + rename) and a non-blocking per-job lock file.
 */
final class JobStore {

	private const ID_PATTERN = '/^[a-f0-9]{16}\z/';

	/**
	 * Directory holding one subdirectory per job.
	 *
	 * @var string
	 */
	private string $baseDir;

	public function __construct( string $baseDir ) {
		$this->baseDir = rtrim( $baseDir, '/' );
	}

	/**
	 * @param string               $type    Job type.
	 * @param array<int, string>   $steps   Step keys.
	 * @param array<string, mixed> $options User options.
	 * @param int                  $now     Unix time.
	 * @return Job
	 * @throws JobException When the job cannot be created.
	 */
	public function create( string $type, array $steps, array $options, int $now ): Job {
		$id  = bin2hex( random_bytes( 8 ) );
		$dir = $this->dir( $id );
		if ( ! Files::privateDir( $dir ) ) {
			throw new JobException( sprintf( 'Cannot create job directory: %s', $dir ) );
		}
		$job            = new Job( $id, $type, array_values( $steps ) );
		$job->options   = $options;
		$job->createdAt = $now;
		$job->updatedAt = $now;
		$this->save( $job );

		return $job;
	}

	public function load( string $id ): Job {
		$file = $this->dir( $id ) . '/job.json';
		$json = is_file( $file ) ? file_get_contents( $file ) : false;
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new JobException( sprintf( 'Job not found: %s', $id ) );
		}

		return Job::fromArray( $data );
	}

	public function save( Job $job ): void {
		$dir = $this->dir( $job->id );
		if ( ! is_dir( $dir ) ) {
			throw new JobException( sprintf( 'Job not found: %s', $job->id ) );
		}
		$json = json_encode( $job->toArray(), JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new JobException( sprintf( 'Cannot encode job %s: %s', $job->id, json_last_error_msg() ) );
		}
		$file = $dir . '/job.json';
		$tmp  = $file . '.tmp';
		Files::privateFile( $tmp );
		if ( false === file_put_contents( $tmp, $json ) || ! rename( $tmp, $file ) ) {
			throw new JobException( sprintf( 'Cannot save job: %s', $job->id ) );
		}
	}

	/**
	 * @param string $id Job id.
	 * @return string Job directory.
	 * @throws JobException On an invalid id.
	 */
	public function dir( string $id ): string {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new JobException( 'Invalid job id.' );
		}

		return $this->baseDir . '/' . $id;
	}

	public function delete( string $id ): void {
		Files::removeTree( $this->dir( $id ) );
	}

	/**
	 * @param string $id Job id.
	 * @return resource|null Lock handle, or null when another process holds it.
	 * @throws JobException When the lock file cannot be opened.
	 */
	public function acquire( string $id ) {
		$dir = $this->dir( $id );
		if ( ! is_dir( $dir ) ) {
			throw new JobException( sprintf( 'Job not found: %s', $id ) );
		}
		Files::privateFile( $dir . '/lock' );
		$handle = fopen( $dir . '/lock', 'c' );
		if ( false === $handle ) {
			throw new JobException( sprintf( 'Cannot open job lock: %s', $id ) );
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return null;
		}

		return $handle;
	}

	/**
	 * @param resource $lock Handle from acquire().
	 * @return void
	 */
	public function release( $lock ): void {
		flock( $lock, LOCK_UN );
		fclose( $lock );
	}

	/**
	 * @return array<int, string>
	 */
	public function ids(): array {
		$ids = [];
		foreach ( (array) glob( $this->baseDir . '/*/job.json' ) as $file ) {
			$id = basename( dirname( (string) $file ) );
			if ( 1 === preg_match( self::ID_PATTERN, $id ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Every job folder, also one whose job.json is missing; never a symlink.
	 *
	 * @return array<int, string> Job ids.
	 */
	public function folders(): array {
		$items = is_dir( $this->baseDir ) ? scandir( $this->baseDir ) : false;
		if ( false === $items ) {
			return [];
		}
		$ids = [];
		foreach ( $items as $name ) {
			$path = $this->baseDir . '/' . $name;
			if ( 1 === preg_match( self::ID_PATTERN, $name ) && ! is_link( $path ) && is_dir( $path ) ) {
				$ids[] = $name;
			}
		}

		return $ids;
	}
}

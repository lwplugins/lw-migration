<?php
/**
 * The result part of a reset job's view.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Reset\ResetJobs;

defined( 'ABSPATH' ) || exit;

/**
 * What a reset did: its type, the backup taken before it (name and size,
 * never a path), the plugins and themes it removed (count and the first
 * names), the media it removed (attachments and files, the files it could
 * not remove and the folders it kept), the tables the database reset
 * replaced, retired and kept and the views it left, the theme it made active,
 * the drop-ins still in place and the login of the admin who reset (who stays
 * logged in after a database reset).
 */
final class ResetResult {

	/**
	 * @param Job $job Reset job.
	 * @return array{kind: string, backup: array{name: string, size: int}|null, plugins: array{count: int, names: array<int, string>, leftovers: int}|null, themes: array{count: int, names: array<int, string>, leftovers: int}|null, media: array{attachments: int, files: int, failed: int, kept: int}|null, database: array{replaced: int, retired: int, kept: int, views: array<int, string>}|null, defaultTheme: array{slug: string, source: string}|null, dropIns: array<int, string>, user: string}
	 */
	public static function of( Job $job ): array {
		$backup  = $job->state['backup'] ?? null;
		$default = $job->state['defaultTheme'] ?? null;

		return [
			'kind'         => (string) ( $job->options['type'] ?? '' ),
			'backup'       => is_array( $backup ) && is_string( $backup['name'] ?? null ) && '' !== $backup['name'] ? [
				'name' => $backup['name'],
				'size' => (int) ( $backup['size'] ?? 0 ),
			] : null,
			'plugins'      => self::removed( $job, 'plugins' ),
			'themes'       => self::removed( $job, 'themes' ),
			'media'        => self::media( $job ),
			'database'     => self::database( $job ),
			'defaultTheme' => is_array( $default ) ? [
				'slug'   => (string) ( $default['slug'] ?? '' ),
				'source' => (string) ( $default['source'] ?? '' ),
			] : null,
			'dropIns'      => array_values( array_map( 'strval', (array) ( $job->state['dropIns'] ?? [] ) ) ),
			'user'         => ResetJobs::admin( $job )['login'],
		];
	}

	/**
	 * @param Job    $job  Reset job.
	 * @param string $kind 'plugins' or 'themes'.
	 * @return array{count: int, names: array<int, string>, leftovers: int}|null Null when that part did not run; leftovers = how many of them kept files that could not be removed.
	 */
	private static function removed( Job $job, string $kind ): ?array {
		$removed = $job->state['removed'][ $kind ] ?? null;

		return is_array( $removed ) ? [
			'count'     => (int) ( $removed['count'] ?? 0 ),
			'names'     => array_values( array_map( 'strval', (array) ( $removed['names'] ?? [] ) ) ),
			'leftovers' => (int) ( $removed['leftovers'] ?? 0 ),
		] : null;
	}

	/**
	 * @param Job $job Reset job.
	 * @return array{attachments: int, files: int, failed: int, kept: int}|null Null when the media part did not run.
	 */
	private static function media( Job $job ): ?array {
		$media = $job->state['removed']['media'] ?? null;

		return is_array( $media ) ? [
			'attachments' => (int) ( $media['attachments'] ?? 0 ),
			'files'       => (int) ( $media['files'] ?? 0 ),
			'failed'      => (int) ( $media['failed'] ?? 0 ),
			'kept'        => (int) ( $media['kept'] ?? 0 ),
		] : null;
	}

	/**
	 * @param Job $job Reset job.
	 * @return array{replaced: int, retired: int, kept: int, views: array<int, string>}|null Null when the database part did not reach its swap.
	 */
	private static function database( Job $job ): ?array {
		$database = $job->state['removed']['database'] ?? null;

		return is_array( $database ) ? [
			'replaced' => (int) ( $database['replaced'] ?? 0 ),
			'retired'  => (int) ( $database['retired'] ?? 0 ),
			'kept'     => (int) ( $database['kept'] ?? 0 ),
			'views'    => array_values( array_map( 'strval', (array) ( $database['views'] ?? [] ) ) ),
		] : null;
	}
}

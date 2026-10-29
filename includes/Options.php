<?php
/**
 * Option names used by the plugin.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * One place for every option and option prefix the plugin owns.
 */
final class Options {

	public const STORAGE_DIR = 'lw_migration_storage_dir';

	public const LOCK = 'lw_migration_lock';

	public const LABELS = 'lw_migration_backup_labels';

	public const RETENTION = 'lw_migration_retention';

	/** Run ids of the temporary import tables this site created (TmpRunRegistry). */
	public const TMP_RUNS = 'lw_migration_tmp_runs';

	/** The backup schedules (Schedules\Repository). */
	public const SCHEDULES = 'lw_migration_schedules';

	/** Schedules whose run is due but has not started yet (Schedules\Queue). */
	public const SCHEDULE_QUEUE = 'lw_migration_schedule_queue';

	/** The one-time token of the next background slice (Schedules\Loopback). */
	public const LOOPBACK = 'lw_migration_loopback';

	/** Notification settings: the default e-mail address list. */
	public const NOTIFICATIONS = 'lw_migration_notifications';

	/** The backups folder setting (Storage\BackupDir). */
	public const BACKUP_DIR = 'lw_migration_backup_dir';

	/** The transfer links (Backups\Shares): only the SHA-256 of each token. */
	public const SHARES = 'lw_migration_shares';

	/** The connections to other sites (Link\Links): secrets sealed. */
	public const LINKS = 'lw_migration_links';

	/** The one open pairing code (Link\Codes): a lookup hash and the sealed nonce. */
	public const LINK_CODE = 'lw_migration_link_code';

	/** Nonces of signed requests seen lately (Link\Nonces). */
	public const LINK_NONCES = 'lw_migration_link_nonces';

	/** Wrong reset passwords per user and their lock (Reset\Tries). */
	public const RESET_TRIES = 'lw_migration_reset_tries';

	/** The cloud destination profiles (Storage\Destinations\ProfileRepository): no secrets. */
	public const DESTINATIONS = 'lw_migration_destinations';

	/** The destinations' secrets, sealed per field (Storage\Destinations\SecretVault). */
	public const DESTINATION_SECRETS = 'lw_migration_destination_secrets';

	/** Which backup is on which destination (Storage\Cloud\CloudCopies). */
	public const CLOUD_COPIES = 'lw_migration_cloud_copies';

	/** The backup size estimate: the last result, the measuring under way and its lease (Export\Estimate\EstimateStore). */
	public const EXPORT_ESTIMATE = 'lw_migration_export_estimate';

	/** What each backup holds, read from its entry headers, per file fingerprint (Backups\ContentsCache). */
	public const BACKUP_CONTENTS = 'lw_migration_backup_contents';

	/** Prefix of the run log of one schedule (Schedules\Log): the prefix plus the schedule id. */
	public const SCHEDULE_LOG_PREFIX = 'lw_migration_schedule_log_';

	/**
	 * Option name prefixes never written into a database dump: our own settings
	 * and the job-secret transients (one may hold a backup password).
	 */
	public const DUMP_EXCLUDED_PREFIXES = [ 'lw_migration_', '_transient_lw_migration_', '_transient_timeout_lw_migration_' ];

	/**
	 * Our rows a restore or a reset does not carry into the new tables: they
	 * describe the site as it was before (the size estimate is measured again).
	 */
	public const NOT_CARRIED = [ self::EXPORT_ESTIMATE ];

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return [ self::STORAGE_DIR, self::LOCK, self::LABELS, self::RETENTION, self::TMP_RUNS, self::SCHEDULES, self::SCHEDULE_QUEUE, self::LOOPBACK, self::NOTIFICATIONS, self::BACKUP_DIR, self::SHARES, self::LINKS, self::LINK_CODE, self::LINK_NONCES, self::RESET_TRIES, self::DESTINATIONS, self::DESTINATION_SECRETS, self::CLOUD_COPIES, self::EXPORT_ESTIMATE, self::BACKUP_CONTENTS ];
	}
}

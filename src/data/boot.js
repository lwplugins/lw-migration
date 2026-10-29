/**
 * Server-provided boot data (Admin\AdminPage inline script). The REST nonce
 * is not part of it: core's wp-api-fetch carries and refreshes it.
 */
// `window` is missing only in the unit tests (Node).
const boot = ( 'undefined' !== typeof window && window.lwMigration ) || {};

export const VERSION = boot.version || '';
export const NAMESPACE = boot.namespace || 'lw-migration/v1';
export const CHUNK_BYTES = Number( boot.chunkBytes ) || 2097152;
export const INITIAL_TAB = boot.tab || 'overview';
export const LOGIN_URL = boot.loginUrl || '';
/** The site admin's address: who gets schedule mails when nothing else is set. */
export const ADMIN_EMAIL = boot.adminEmail || '';
/**
 * The slug this site's backups are kept under at a destination (a new
 * destination's folder starts as it; an account keeps its backups in it).
 * Mirrors Storage\SiteFolder::name().
 */
export const SITE_FOLDER = boot.siteFolder || '';
/**
 * Null on a single site; on a network `{ subdomain, mainUrl, siteCount }`
 * (the app then runs in Network Admin and backs up the network).
 */
export const NETWORK = boot.network || null;

/**
 * Folder kind of the backups this plugin makes (mirrors Backups\Backup::OWN).
 */
export const OWN_FOLDER = 'own';

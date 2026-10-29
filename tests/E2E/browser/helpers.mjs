import { execFileSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import {
	mkdirSync,
	readFileSync,
	readdirSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { join } from 'node:path';

export const SITE = process.env.LWM_E2E_A;
export const SECOND = process.env.LWM_E2E_B;
export const URL_A = process.env.LWM_UI_URL;
export const URL_B = process.env.LWM_UI_URL_B;
// The throwaway site of the Reset tests: never site A or B.
export const SITE_R = process.env.LWM_E2E_R;
export const URL_R = process.env.LWM_UI_URL_R;

/**
 * WP-CLI on an E2E site (memory as in the web requests: 256M).
 *
 * @param {string}    site Site folder.
 * @param {...string} args Command and arguments.
 * @return {string} Trimmed output.
 */
export function wp( site, ...args ) {
	return execFileSync(
		process.env.LWM_E2E_PHP,
		[
			'-d',
			'memory_limit=256M',
			process.env.LWM_E2E_WP_CLI,
			`--path=${ site }`,
			...args,
		],
		{ encoding: 'utf8' }
	).trim();
}

export const screen = ( tab ) =>
	`/wp-admin/admin.php?page=lw-migration#${ tab }`;

/**
 * Logs the admin in on the page's site (`base` = its URL, default site A).
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          base Site URL.
 */
export async function login( page, base = URL_A ) {
	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin-pass' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /\/wp-admin\// );
}

/** A backup job that nobody drives (a closed tab), holding the site lock. */
export const pendingExport = () =>
	wp(
		SITE,
		'eval',
		'$s = \\LightweightPlugins\\Migration\\Services::get(); $s->storage()->ensure(); ' +
			'echo \\LightweightPlugins\\Migration\\Export\\ExportJobs::create( $s->jobs(), $s->lock(), ' +
			'\\LightweightPlugins\\Migration\\WordPress\\SiteReader::read(), ' +
			'\\LightweightPlugins\\Migration\\Export\\ExportOptions::fromArray( [ "noMedia" => true ] ), null, ' +
			'new \\LightweightPlugins\\Migration\\WordPress\\TransientSecretStore(), time() )->id;'
	);

export const lockState = ( site = SITE ) =>
	wp(
		site,
		'eval',
		'echo get_option( "lw_migration_lock" ) ? "held" : "free";'
	);

/**
 * The plugin's own backups folder of a site.
 *
 * @param {string} site Site folder.
 * @return {string} Folder.
 */
export function backupsDir( site ) {
	const content = join( site, 'wp-content' );
	const storage = readdirSync( content ).find( ( name ) =>
		/^lw-migration-[0-9a-f]+$/.test( name )
	);
	return join( content, storage, 'backups' );
}

/**
 * @param {string} site Site folder.
 * @return {string[]} Archive names in the own backups folder.
 */
export const archives = ( site ) =>
	readdirSync( backupsDir( site ) )
		.filter( ( name ) => name.endsWith( '.wpress' ) )
		.sort();

export const sha1 = ( file ) =>
	createHash( 'sha1' ).update( readFileSync( file ) ).digest( 'hex' );

/**
 * Sets the tick length the mu-plugin hands to the plugin.
 *
 * @param {number} seconds Seconds; 0 = the plugin's own length.
 * @return {string} WP-CLI output.
 */
export const setTickSeconds = ( seconds ) =>
	seconds
		? wp(
				SITE,
				'option',
				'update',
				'lwm_e2e_tick_seconds',
				String( seconds )
			)
		: wp( SITE, 'option', 'delete', 'lwm_e2e_tick_seconds' );

const SLOW = () => join( SITE, 'wp-content', 'lwm-e2e-slow' );

/**
 * Work that keeps a bzip2 backup busy for many seconds: incompressible bytes
 * (about 13 MB/s through bzip2 on a fast machine).
 *
 * @param {number} megabytes Size.
 */
export function addSlowFixture( megabytes ) {
	mkdirSync( SLOW(), { recursive: true } );
	writeFileSync(
		join( SLOW(), 'noise.bin' ),
		randomBytes( megabytes * 1024 * 1024 )
	);
}

export const removeSlowFixture = () =>
	rmSync( SLOW(), { recursive: true, force: true } );

// Plain permalinks: the REST path sits in `rest_route`, with its slashes encoded or not.
const S = '(?:%2F|/)';
export const ROUTE = {
	active: new RegExp( `jobs${ S }active` ),
	tick: new RegExp( `jobs${ S }[a-f0-9]{16}${ S }tick` ),
	upload: new RegExp( `jobs${ S }[a-f0-9]{16}${ S }upload` ),
	job: new RegExp( `jobs${ S }[a-f0-9]{16}(?:&|$)` ),
};

/**
 * Whether a request is the job cancel (apiFetch sends DELETE as POST with a
 * method override).
 *
 * @param {import('@playwright/test').Request} request Request.
 * @return {boolean} Cancel.
 */
export const isCancel = ( request ) =>
	ROUTE.job.test( request.url() ) &&
	( 'DELETE' === request.method() ||
		'DELETE' ===
			(
				request.headers()[ 'x-http-method-override' ] || ''
			).toUpperCase() );

/**
 * Counts every `.components-spinner` that ever appears on the page (house
 * rule: skeletons, never a spinner), across re-renders and reloads: the
 * count lives in the tab's sessionStorage, so a navigation does not reset it.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
export async function watchSpinners( page ) {
	await page.addInitScript( () => {
		const KEY = 'lwSpinnersSeen';
		let storage;
		try {
			storage = window.sessionStorage;
		} catch {
			return; // about:blank and other opaque origins.
		}
		new window.MutationObserver( () => {
			if ( document.querySelector( '.components-spinner' ) ) {
				storage.setItem(
					KEY,
					String( ( Number( storage.getItem( KEY ) ) || 0 ) + 1 )
				);
			}
		} ).observe( document, { childList: true, subtree: true } );
	} );
}

/**
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Spinners seen since the watcher was armed.
 */
export const spinnersSeen = ( page ) =>
	page.evaluate(
		() => Number( window.sessionStorage.getItem( 'lwSpinnersSeen' ) ) || 0
	);

/**
 * Types the target's host into the check's confirmation, which keeps
 * "Restore now" off until then (the field shows the host as placeholder).
 *
 * @param {import('@playwright/test').Page|import('@playwright/test').Locator} scope Page or part of it.
 */
export async function typeTarget( scope ) {
	const field = scope.getByLabel( /^Type .+ to confirm$/ );
	await field.fill( ( await field.getAttribute( 'placeholder' ) ) || '' );
}

/**
 * Runs one action of a backup's ⋯ menu in the Backups section (Restore,
 * Transfer link, Label, Contents, Delete…).
 *
 * @param {import('@playwright/test').Page} page   Page on the Backups section.
 * @param {string}                          name   Backup file name.
 * @param {string}                          action Menu item.
 */
export async function backupAction( page, name, action ) {
	await page.getByRole( 'button', { name: `More for ${ name }` } ).click();
	await page.getByRole( 'menuitem', { name: action } ).click();
}

/**
 * The restore form's request, without React: what the Restore section sends
 * to `POST /jobs` and where a refusal of it is shown. The address is checked
 * by the server only (its refusal comes back with `data.field`).
 */

/** Rest\JobInput::SOURCES: the browser's sources and the keys each takes (pinned by the test). */
export const SOURCE_KEYS = {
	upload: [ 'name', 'size' ],
	backup: [ 'name', 'folder' ],
	url: [ 'url' ],
};

/**
 * @param {string} source upload|url|backup.
 * @param {Object} choice { file, url, backup } as chosen in the form.
 * @return {boolean} Whether the chosen source is filled in.
 */
export function isReady( source, choice ) {
	if ( 'upload' === source ) {
		return Boolean( choice.file );
	}
	if ( 'url' === source ) {
		// A first look only; the server checks the address for real.
		return /^https?:\/\/\S+$/i.test( String( choice.url || '' ).trim() );
	}
	return 'backup' === source && Boolean( choice.backup );
}

/**
 * The `POST /jobs` body. A backup is named by the folder and name of its list
 * entry (never by splitting its id); the safety-backup choice is made on the
 * confirmation step, so it is not sent here.
 *
 * @param {string} source upload|url|backup.
 * @param {Object} choice { file, url, backup }.
 * @return {Object} Body.
 */
export function buildImportRequest( source, choice ) {
	const { file, url, backup } = choice;
	const body = { type: 'import', source };
	if ( 'upload' === source ) {
		return { ...body, name: file.name, size: file.size };
	}
	if ( 'url' === source ) {
		return { ...body, url: url.trim() };
	}
	return { ...body, folder: backup.folder, name: backup.name };
}

/**
 * The list entry with this folder and name.
 *
 * @param {Array}       items  Backups list.
 * @param {Object|null} wanted { folder, name }.
 * @return {Object|null} Entry.
 */
export const findBackup = ( items, wanted ) =>
	( wanted &&
		items.find(
			( item ) =>
				item.folder === wanted.folder && item.name === wanted.name
		) ) ||
	null;

/** Request field → the form control that shows its refusal. */
const CONTROLS = {
	name: 'upload',
	size: 'upload',
	url: 'url',
	folder: 'backup',
};

/**
 * Where a refused start is shown (ruling P3): next to the control the server
 * named; anything else (a busy site with its lock expiry, a logout, a
 * web-server error page, a storage failure) goes to the notice. The text is
 * chosen by the view (data/refusal.js), since it is translated.
 *
 * @param {Object} error Rejection of api.startJob() (request()).
 * @return {Object} `{ control, field, reload }`: control is upload|url|backup
 *                  or null (notice); field = the refused request field;
 *                  reload = the backups list is stale.
 */
export function startProblem( error ) {
	const code = error?.code || '';
	if ( 'lw_migration_invalid' === code ) {
		const field = error?.data?.field || null;
		const control = CONTROLS[ field ] || null;
		return { control, field: control ? field : null, reload: false };
	}
	if ( 'lw_migration_no_room' === code ) {
		return { control: 'upload', field: null, reload: false };
	}
	if ( 'lw_migration_backup_missing' === code ) {
		return { control: 'backup', field: null, reload: true };
	}
	return { control: null, field: null, reload: false };
}

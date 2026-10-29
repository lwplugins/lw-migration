/**
 * What the backups folder section offers (pure: unit-tested).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * The buttons the section shows, the note that explains a missing one, and
 * whether they are disabled. A folder of your own works only where
 * `supported`; the default folder works everywhere, so the way back to it
 * (or a finish or undo that ends there) stays. While another job or a move
 * runs, every shown button is disabled.
 *
 * @param {Object}  view GET /backup-dir.
 * @param {boolean} busy Another job runs on this page.
 * @return {{show: string[], why: string, disabled: boolean}} Buttons (move,
 *                                                            backToDefault, finish, undo), the note ('' | 'locked' |
 *                                                            'unsupported') and whether they are disabled.
 */
export function actionsFor( view, busy = false ) {
	if ( ! view.editable ) {
		return { show: [], why: 'locked', disabled: false };
	}
	const own = view.supported;
	const show = view.moving
		? [
				( own || '' === view.moving.to ) && 'finish',
				( own || '' === view.custom ) && 'undo',
			]
		: [ own && 'move', '' !== view.custom && 'backToDefault' ];
	return {
		show: show.filter( Boolean ),
		why: own ? '' : 'unsupported',
		disabled: Boolean( busy || view.running ),
	};
}

const WAIT = () =>
	__(
		'Scheduled backups wait, and no backup or restore can start until it is done. Keep this page open; if it stops, you can finish it later.',
		'lw-migration'
	);

/**
 * @param {Object} body The move request ({ path } or { action }).
 * @return {string} The question asked before it starts.
 */
export function confirmText( body ) {
	let question;
	if ( 'finish' === body.action ) {
		question = __(
			'Finish moving the backups to the new folder?',
			'lw-migration'
		);
	} else if ( 'undo' === body.action ) {
		question = __(
			'Move the backups back to the old folder?',
			'lw-migration'
		);
	} else if ( '' === body.path ) {
		question = __(
			'Move every backup to the default folder?',
			'lw-migration'
		);
	} else {
		question = __( 'Move every backup to this folder?', 'lw-migration' );
	}
	return `${ question } ${ WAIT() }`;
}

/**
 * @param {Object} moving The view's unfinished move (`to` already masked by the server when `toHidden`).
 * @return {string} Its target as it may be named in a sentence.
 */
export const movingTarget = ( moving ) =>
	'' === moving.to ? __( 'the default folder', 'lw-migration' ) : moving.to;

/**
 * @param {string} folder A folder as the server shows it ('' = the default one).
 * @return {string} How it is named; the default folder's path is never shown.
 */
export const targetLabel = ( folder ) =>
	'' === folder
		? __( 'Default: a protected folder inside wp-content', 'lw-migration' )
		: folder;

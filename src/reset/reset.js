/**
 * The Reset section without React: which reset needs what before it may
 * start, what a refusal means, and the lines of the done screen (what each
 * reset removes and keeps: resetFacts.js). Only codes and flags are read,
 * never a translated text.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/** The five resets, in the order of their cards. */
export const TYPES = [ 'plugins', 'themes', 'media', 'database', 'full' ];

/**
 * @param {string} type Reset type.
 * @return {string} The card title, also the confirm button's text.
 */
export function titleOf( type ) {
	const titles = {
		plugins: __( 'Delete plugins', 'lw-migration' ),
		themes: __( 'Reset themes', 'lw-migration' ),
		media: __( 'Clean up media', 'lw-migration' ),
		database: __( 'Reset the database', 'lw-migration' ),
		full: __( 'Reset everything', 'lw-migration' ),
	};
	return titles[ type ] ?? '';
}

/**
 * @param {string} type Reset type.
 * @return {boolean} Whether the site address must be typed to confirm.
 */
export const needsPhrase = ( type ) => 'database' === type || 'full' === type;

/**
 * Whether the dialog's confirm button may be pressed.
 *
 * @param {string} type           Reset type.
 * @param {Object} typed
 * @param {string} typed.password The password field.
 * @param {string} typed.phrase   The site address field.
 * @param {Object} summary        `GET /reset` (`phrase`).
 * @return {boolean} Ready.
 */
export function canConfirm( type, { password, phrase }, summary ) {
	if ( ! password ) {
		return false;
	}
	if ( ! needsPhrase( type ) ) {
		return true;
	}
	const expected = summary?.phrase || '';
	return '' !== expected && expected === String( phrase ?? '' ).trim();
}

/**
 * Which steps before the start are done. A switched-off backup needs
 * nothing; a switched-on one needs a place to write it.
 *
 * @param {Object}  input
 * @param {string}  input.type        Reset type.
 * @param {boolean} input.backup      The backup switch.
 * @param {boolean} input.backupReady False when the server cannot take a backup (`GET /reset`).
 * @param {string}  input.password    The password field.
 * @param {string}  input.phrase      The site address field.
 * @param {Object}  summary           `GET /reset` (`phrase`).
 * @return {{backup: boolean, password: boolean, phrase: (boolean|null), ready: boolean, why: string}}
 *   `phrase` is null when the type asks for none; `why` names the first
 *   open step ('' when ready).
 */
export function stepsReady(
	{ type, backup, backupReady, password, phrase },
	summary
) {
	const backupOk = ! backup || false !== backupReady;
	const passwordOk = Boolean( password );
	const phraseOk = needsPhrase( type )
		? canConfirm( type, { password: 'x', phrase }, summary )
		: null;
	let why = '';
	if ( ! backupOk ) {
		why = __(
			'The backup folder cannot take a backup right now: check Settings, or switch the backup off.',
			'lw-migration'
		);
	} else if ( ! passwordOk ) {
		why = __( 'Enter your password.', 'lw-migration' );
	} else if ( false === phraseOk ) {
		why = __( 'Type the site address to confirm.', 'lw-migration' );
	}
	return {
		backup: backupOk,
		password: passwordOk,
		phrase: phraseOk,
		ready: backupOk && canConfirm( type, { password, phrase }, summary ),
		why,
	};
}

/**
 * @param {Object} error Rejection of request().
 * @return {number} Whole minutes until the lock ends (at least 1), 0 when it is no lock.
 */
export function lockMinutes( error ) {
	if ( 'lw_migration_reset_locked' !== error?.code ) {
		return 0;
	}
	return Math.max(
		1,
		Math.ceil( ( Number( error.data?.retryAfter ) || 0 ) / 60 )
	);
}

/**
 * @param {Object} error Rejection of request().
 * @return {string|null} The refusal code of a reset that may not start, else null.
 */
export const refusalReason = ( error ) =>
	'lw_migration_reset_refused' === error?.code
		? error?.data?.reason || null
		: null;

/**
 * Why a reset may not start: the server's text (it knows the detail: the
 * owner, a view's name), our hint by code when it sent none.
 *
 * @param {Object} part One type of the summary (`reason`, `reasonText`).
 * @return {string} Text.
 */
export function unavailableText( part ) {
	if ( part?.reasonText ) {
		return part.reasonText;
	}
	const hints = {
		network: __(
			'On a network only plugins, themes and media can be reset in this version.',
			'lw-migration'
		),
		file_mods: __(
			'This site does not allow plugins and themes to be changed.',
			'lw-migration'
		),
		not_writable: __(
			'The web server cannot write the folder this reset would change.',
			'lw-migration'
		),
		as_root: __(
			'This server runs as the root user, so files are never reset from here.',
			'lw-migration'
		),
	};
	return (
		hints[ part?.reason ] ||
		__( 'This reset cannot run on this site.', 'lw-migration' )
	);
}

/**
 * @param {Object} part `result.plugins` or `result.themes`.
 * @return {string} The note on deleted ones that kept files ('' = none).
 */
function leftovers( part ) {
	const kept = Number( part?.leftovers ) || 0;
	return kept > 0
		? ' ' +
				sprintf(
					/* translators: %d: how many of the deleted plugins or themes */
					__(
						'%d of them kept files that could not be removed (see the warnings).',
						'lw-migration'
					),
					kept
				)
		: '';
}

/**
 * The lines of the done screen, from the job's result.
 *
 * @param {Object} result Job result of a reset.
 * @return {string[]} Translated lines.
 */
export function resultLines( result ) {
	const lines = [];
	if ( result?.plugins ) {
		const count = Number( result.plugins.count ) || 0;
		lines.push(
			sprintf(
				/* translators: %d: number of plugins */
				_n(
					'%d plugin deleted.',
					'%d plugins deleted.',
					count,
					'lw-migration'
				),
				count
			) + leftovers( result.plugins )
		);
	}
	if ( result?.themes ) {
		const count = Number( result.themes.count ) || 0;
		const theme = result.defaultTheme || {};
		let line = sprintf(
			/* translators: 1: theme folder name, 2: number of themes */
			_n(
				'%1$s is active; %2$d theme deleted.',
				'%1$s is active; %2$d themes deleted.',
				count,
				'lw-migration'
			),
			theme.slug || '',
			count
		);
		line += leftovers( result.themes );
		if ( 'stub' === theme.source ) {
			line +=
				' ' +
				__(
					'(a minimal blank theme, because none could be installed)',
					'lw-migration'
				);
		}
		lines.push( line );
	}
	if ( result?.media ) {
		lines.push(
			sprintf(
				/* translators: 1: number of media items, 2: number of files */
				__(
					'Media library emptied: %1$d items, %2$d files.',
					'lw-migration'
				),
				Number( result.media.attachments ) || 0,
				Number( result.media.files ) || 0
			)
		);
	}
	if ( result?.database ) {
		lines.push(
			sprintf(
				/* translators: %s: your login */
				__(
					'Fresh database ready. You are still logged in as %s; every other user was removed.',
					'lw-migration'
				),
				result.user || ''
			)
		);
	}
	return lines;
}

/**
 * @param {Object} result Job result of a reset.
 * @return {boolean} Whether the page shows menus of a site that is gone (reload it).
 */
export const needsReload = ( result ) =>
	Boolean( result?.database ) ||
	'database' === result?.kind ||
	'full' === result?.kind;

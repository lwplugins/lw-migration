/**
 * A schedule's form state and request, without React. The export options
 * use the Backup section's form shape and checks (exportRequest.js), so a
 * schedule backs up exactly what a manual backup with the same settings
 * would. Only keys of Rest\ScheduleInput::KEYS leave the browser (pinned
 * by scheduleForm.test.js against the PHP source). A stored password is
 * never sent back by the server (`hasPassword` only): leaving both password
 * fields empty keeps it, typing one replaces it. Destinations are sent by
 * id with their retention (spec 21.1).
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NETWORK } from '../data/boot';
import {
	DEFAULTS as EXPORT_DEFAULTS,
	FLAG_KEYS,
	checkForm as checkExport,
	toRequest as exportRequest,
} from '../views/exportRequest';

const ON_NETWORK = Boolean( NETWORK );

/** Schedules\Schedule::EVERY. */
export const EVERY = { hours: [ 2, 48 ], days: [ 2, 90 ] };

/** Backups\Retention::LIMITS. */
export const RETENTION_MAX = {
	count: 10000,
	bytes: 1125899906842624,
	days: 36500,
};

export const NEW_SCHEDULE = {
	title: '',
	enabled: true,
	frequency: 'daily',
	every: 2,
	minute: 0,
	hour: 3,
	weekday: 1,
	monthday: 1,
	retention: { count: 7, bytes: 0, days: 0 },
	notify: { when: 'failure', email: '' },
	hasPassword: false,
	export: EXPORT_DEFAULTS,
	destinations: [],
	keepLocal: true,
};

const copyDestinations = ( list ) =>
	( list || [] ).map( ( d ) => ( {
		id: d.id,
		retention: { ...d.retention },
	} ) );

const lines = ( list ) => ( list || [] ).join( '\n' );

/**
 * @param {Object} item A ScheduleView item.
 * @return {Object} Form state.
 */
export function fromItem( item ) {
	const e = item.export;
	return {
		title: item.title,
		enabled: item.enabled,
		frequency: item.frequency,
		every: EVERY[ item.frequency ] ? item.every : 2,
		minute: item.minute,
		hour: item.hour,
		weekday: item.weekday,
		monthday: item.monthday,
		retention: { ...item.retention },
		notify: { ...item.notify },
		hasPassword: item.hasPassword,
		destinations: copyDestinations( item.destinations ),
		keepLocal: false !== item.keepLocal,
		export: {
			...Object.fromEntries(
				FLAG_KEYS.map( ( key ) => [ key, Boolean( e[ key ] ) ] )
			),
			compression: e.compression || 'none',
			encrypt: Boolean( e.encrypt ),
			excludeFiles: lines( e.excludeFiles ),
			excludeTables: lines( e.excludeTables ),
			includeTables: lines( e.includeTables ),
			replace: ( e.replace || [] ).map( ( [ from, to ] ) => [
				from,
				to,
			] ),
			// A schedule of chosen sites stays one (export.sites, network only).
			scope: e.sites?.length ? 'sites' : 'network',
			sites: [ ...( e.sites || [] ) ],
			siteLabels: {},
		},
	};
}

/**
 * A new schedule with the fields of an existing one ("Duplicate"): its name
 * gets " (copy)", and the password never comes along (an encrypted copy
 * asks for a new one).
 *
 * @param {Object} item A ScheduleView item.
 * @return {Object} Form state of a new schedule.
 */
export function duplicateForm( item ) {
	return {
		...fromItem( item ),
		title: sprintf(
			/* translators: %s: name of the schedule copied. */
			__( '%s (copy)', 'lw-migration' ),
			item.title
		),
		hasPassword: false,
	};
}

/**
 * The POST body of a create or an update.
 *
 * @param {Object}  form     Form state.
 * @param {string}  password Typed password ('' = keep the stored one).
 * @param {boolean} network  The page runs on a network (default: the boot data).
 * @return {Object} Body.
 */
export function toRequest( form, password, network = ON_NETWORK ) {
	const body = {
		title: form.title.trim(),
		enabled: form.enabled,
		frequency: form.frequency,
		minute: form.minute,
		hour: form.hour,
		weekday: form.weekday,
		monthday: form.monthday,
		retention: { ...form.retention },
		notify: { when: form.notify.when, email: form.notify.email.trim() },
		export: exportRequest( form.export, network ),
		destinations: copyDestinations( form.destinations ),
		keepLocal: form.keepLocal,
	};
	if ( EVERY[ form.frequency ] ) {
		body.every = form.every;
	}
	if ( form.export.encrypt && '' !== password ) {
		body.password = password;
	}
	return body;
}

/**
 * The form without destinations deleted since it was opened (the server
 * refuses an unknown id). While the list is unknown (not loaded, or its load
 * failed) the form keeps what the schedule had.
 *
 * @param {Object}        form  Form state.
 * @param {Object[]|null} known Destination views, or null.
 * @return {Object} Form state.
 */
export function withKnownDestinations( form, known ) {
	if ( ! Array.isArray( known ) ) {
		return form;
	}
	const ids = new Set( known.map( ( d ) => d.id ) );
	return {
		...form,
		destinations: form.destinations.filter( ( d ) => ids.has( d.id ) ),
	};
}

/**
 * @param {Object} form     Form state.
 * @param {string} password Typed password.
 * @param {string} again    Typed again.
 * @return {boolean} The stored password stays.
 */
export const keepsPassword = ( form, password, again ) =>
	Boolean( form.export.encrypt && form.hasPassword ) &&
	'' === password &&
	'' === again;

/**
 * What the server would refuse, checked before sending.
 *
 * @param {Object}  form     Form state.
 * @param {string}  password Typed password.
 * @param {string}  again    Typed again.
 * @param {boolean} network  The page runs on a network (default: the boot data).
 * @return {Object|null} `{ field, reason, index? }` or null.
 */
export function checkSchedule( form, password, again, network = ON_NETWORK ) {
	if ( '' === form.title.trim() ) {
		return { field: 'title', reason: 'empty' };
	}
	const range = EVERY[ form.frequency ];
	if ( range && ! ( form.every >= range[ 0 ] && form.every <= range[ 1 ] ) ) {
		return { field: 'every', reason: 'range' };
	}
	return keepsPassword( form, password, again )
		? checkExport( form.export, 'kept', 'kept', network )
		: checkExport( form.export, password, again, network );
}

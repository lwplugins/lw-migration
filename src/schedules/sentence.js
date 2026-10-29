/**
 * The editor's live sentence, without React: what the form would do, in
 * whole sentences. The timing sentence has one template per frequency
 * (with <b> around the timing, for createInterpolateElement); what it backs
 * up, where the backups go and the e-mail are each a sentence of their own,
 * so every language can word them freely.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NETWORK } from '../data/boot';
import { pageLocale } from '../data/format';
import { listText } from '../overview/status';
import { clock, weekdayName } from './timing';
import { whatPhrase } from './what';

const ON_NETWORK = Boolean( NETWORK );

/**
 * @param {Object} form Form state.
 * @param {string} what whatPhrase().
 * @return {string} The timing sentence, the timing in <b></b>.
 */
export function timingSentence( form, what ) {
	const at = () => clock( form.hour, form.minute );
	switch ( form.frequency ) {
		case 'hourly':
			return sprintf(
				/* translators: 1: minute of the hour, 0-59; 2: what it backs up, e.g. the whole site. */
				__(
					'<b>Every hour at minute %1$d</b>, it backs up %2$s.',
					'lw-migration'
				),
				form.minute,
				what
			);
		case 'hours':
			return sprintf(
				/* translators: 1: number of hours; 2: minute of the hour; 3: what it backs up. */
				_n(
					'<b>Every %1$d hour at minute %2$d</b>, it backs up %3$s.',
					'<b>Every %1$d hours at minute %2$d</b>, it backs up %3$s.',
					form.every,
					'lw-migration'
				),
				form.every,
				form.minute,
				what
			);
		case 'days':
			return sprintf(
				/* translators: 1: number of days; 2: time of day, e.g. 03:00; 3: what it backs up. */
				_n(
					'<b>Every %1$d day at %2$s</b>, it backs up %3$s.',
					'<b>Every %1$d days at %2$s</b>, it backs up %3$s.',
					form.every,
					'lw-migration'
				),
				form.every,
				at(),
				what
			);
		case 'weekly':
			return sprintf(
				/* translators: 1: day of the week; 2: time of day, e.g. 03:00; 3: what it backs up. */
				__(
					'<b>Every %1$s at %2$s</b>, it backs up %3$s.',
					'lw-migration'
				),
				weekdayName( form.weekday ),
				at(),
				what
			);
		case 'monthly':
			return sprintf(
				/* translators: 1: day of the month; 2: time of day, e.g. 03:00; 3: what it backs up. */
				__(
					'<b>On day %1$d of every month at %2$s</b>, it backs up %3$s.',
					'lw-migration'
				),
				form.monthday,
				at(),
				what
			);
		default:
			return sprintf(
				/* translators: 1: time of day, e.g. 03:00; 2: what it backs up. */
				__(
					'<b>Every day at %1$s</b>, it backs up %2$s.',
					'lw-migration'
				),
				at(),
				what
			);
	}
}

/**
 * @param {Object}   form   Form state (keepLocal).
 * @param {string[]} names  Names of the chosen destinations.
 * @param {string}   locale BCP 47 tag.
 * @return {string} Where the backups go.
 */
export function whereSentence( form, names, locale = pageLocale() ) {
	if ( 0 === names.length ) {
		return __( 'The backups stay on this server.', 'lw-migration' );
	}
	const list = listText( names, locale );
	return form.keepLocal
		? sprintf(
				/* translators: %s: list of destination names. */
				__(
					'They stay on this server and are uploaded to: %s.',
					'lw-migration'
				),
				list
			)
		: sprintf(
				/* translators: %s: list of destination names. */
				__(
					'They are uploaded to: %s. The copy on this server is deleted once every upload succeeded.',
					'lw-migration'
				),
				list
			);
}

/**
 * @param {Object} notify {when, email}.
 * @return {string} When an e-mail goes out.
 */
export function mailSentence( notify ) {
	switch ( notify.when ) {
		case 'off':
			return __( 'No e-mail is sent.', 'lw-migration' );
		case 'success':
			return __(
				'An e-mail goes out when a backup is ready.',
				'lw-migration'
			);
		case 'always':
			return __( 'An e-mail goes out after every run.', 'lw-migration' );
		default:
			return __( 'An e-mail goes out when a run fails.', 'lw-migration' );
	}
}

/**
 * The whole live sentence.
 *
 * @param {Object}   form    Form state.
 * @param {string[]} names   Names of the chosen destinations.
 * @param {string}   locale  BCP 47 tag.
 * @param {boolean}  network The page runs on a network.
 * @return {{timing: string, rest: string}} The timing sentence (with <b>) and the others.
 */
export function liveSentence(
	form,
	names,
	locale = pageLocale(),
	network = ON_NETWORK
) {
	const rest = [
		whereSentence( form, names, locale ),
		mailSentence( form.notify ),
	];
	if ( 'monthly' === form.frequency && form.monthday > 28 ) {
		rest.unshift(
			__(
				'In a month without this day it runs on the last day.',
				'lw-migration'
			)
		);
	}
	return {
		timing: timingSentence(
			form,
			whatPhrase( form.export, locale, network )
		),
		rest: rest.join( ' ' ),
	};
}

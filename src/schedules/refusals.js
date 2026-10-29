/**
 * Which control of the schedule editor shows a refusal, and our own text
 * for a refused schedule field, without React.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { EVERY } from './scheduleForm';

/** Refusals shown at the export controls (the options panel, the password, the site picker). */
export const EXPORT_FIELDS = [
	'sites',
	'replace',
	'excludeFiles',
	'excludeTables',
	'includeTables',
	'password',
];

/** Of those, the ones inside the folded Advanced settings. */
export const FOLDED_FIELDS = [
	'replace',
	'excludeFiles',
	'excludeTables',
	'includeTables',
];

/**
 * Our text for a refused schedule field ('' = none: the notice shows the server's).
 *
 * @param {string} field Field name.
 * @param {Object} form  Form state.
 * @return {string} Text.
 */
export function ownText( field, form ) {
	switch ( field ) {
		case 'title':
			return __(
				'Give the schedule a name, up to 100 characters.',
				'lw-migration'
			);
		case 'every': {
			const span = EVERY[ form.frequency ] || [ 1, 90 ];
			return sprintf(
				/* translators: 1: smallest number, 2: largest number. */
				__( 'Enter a number from %1$d to %2$d.', 'lw-migration' ),
				span[ 0 ],
				span[ 1 ]
			);
		}
		case 'email':
			return __(
				'Enter up to 5 valid e-mail addresses, separated by commas.',
				'lw-migration'
			);
		case 'count':
		case 'bytes':
		case 'days':
			return __(
				'Enter a number from 0 up to the limit.',
				'lw-migration'
			);
		default:
			return '';
	}
}

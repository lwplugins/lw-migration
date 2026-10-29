/**
 * The three ready-made schedules of the empty state, without React. A
 * starter only fills the editor; nothing is saved until "Save schedule".
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NEW_SCHEDULE } from './scheduleForm';
import { clock } from './timing';

const keep = ( count ) => ( { count, bytes: 0, days: 0 } );

/**
 * @return {{key: string, title: string, text: string, form: Object}[]} Starters, in the order shown.
 */
export function starters() {
	const nightly = __( 'Daily backup at night', 'lw-migration' );
	const weekly = __( 'Weekly full backup', 'lw-migration' );
	const database = __( 'Database every 6 hours', 'lw-migration' );
	return [
		{
			key: 'nightly',
			title: nightly,
			text: sprintf(
				/* translators: %s: time of day, e.g. 03:00. */
				__(
					'Every day at %s, the whole site. The last 7 stay here.',
					'lw-migration'
				),
				clock( 3, 0 )
			),
			form: {
				...NEW_SCHEDULE,
				title: nightly,
				frequency: 'daily',
				hour: 3,
				minute: 0,
				retention: keep( 7 ),
			},
		},
		{
			key: 'weekly',
			title: weekly,
			text: sprintf(
				/* translators: %s: time of day, e.g. 02:30. */
				__(
					'Sundays at %s, the whole site. The last 4 stay here.',
					'lw-migration'
				),
				clock( 2, 30 )
			),
			form: {
				...NEW_SCHEDULE,
				title: weekly,
				frequency: 'weekly',
				weekday: 7,
				hour: 2,
				minute: 30,
				retention: keep( 4 ),
			},
		},
		{
			key: 'database',
			title: database,
			text: __(
				'Only the database, at minute 15 of every 6th hour. The last 12 stay.',
				'lw-migration'
			),
			form: {
				...NEW_SCHEDULE,
				title: database,
				frequency: 'hours',
				every: 6,
				minute: 15,
				retention: keep( 12 ),
				export: {
					...NEW_SCHEDULE.export,
					noMedia: true,
					noPlugins: true,
					noThemes: true,
					noMustUsePlugins: true,
				},
			},
		},
	];
}

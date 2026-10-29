/**
 * The facts a destination card lists, without React.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatDatetime } from '../data/format';
import { gbShown } from '../views/retentionSize';

/**
 * What the destination keeps: the backups, the size and the age that count
 * as limits ("10 backups · 30 days"); a zero is no limit and is left out.
 *
 * @param {{count?: number, bytes?: number, days?: number}} retention Retention.
 * @return {string} Text.
 */
export function retentionFact( retention ) {
	const { count = 0, bytes = 0, days = 0 } = retention ?? {};
	const parts = [];
	if ( count > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of backups kept. */
				_n( '%d backup', '%d backups', count, 'lw-migration' ),
				count
			)
		);
	}
	if ( bytes > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: total size in GB, e.g. 2.5. */
				__( '%s GB', 'lw-migration' ),
				gbShown( bytes )
			)
		);
	}
	if ( days > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: age in days. */
				_n( '%d day', '%d days', days, 'lw-migration' ),
				days
			)
		);
	}
	return parts.length
		? parts.join( ' · ' )
		: __( 'no limit', 'lw-migration' );
}

/**
 * The card's facts, in order.
 *
 * @param {Object} item Destination view.
 * @return {string[][]} `[ label, value ]` pairs.
 */
export function cardFacts( item ) {
	const facts = [
		[
			__( 'Last upload', 'lw-migration' ),
			item.lastUploadAt > 0
				? formatDatetime( item.lastUploadAt )
				: __( 'Nothing uploaded yet', 'lw-migration' ),
		],
	];
	if ( item.usedBy > 0 ) {
		facts.push( [
			__( 'Schedules', 'lw-migration' ),
			sprintf(
				/* translators: %d: number of schedules. */
				_n(
					'%d schedule',
					'%d schedules',
					item.usedBy,
					'lw-migration'
				),
				item.usedBy
			),
		] );
	}
	facts.push( [
		__( 'Keeping', 'lw-migration' ),
		retentionFact( item.retention ),
	] );
	if ( item.connection?.account ) {
		facts.push( [
			__( 'Account', 'lw-migration' ),
			item.connection.account,
		] );
	}
	return facts;
}

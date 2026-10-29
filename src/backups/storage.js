/**
 * The storage strip without React: what the backups take, what is free, and
 * the rule that deletes backups by themselves, worded from `GET /backups`
 * `storage` (Rest\BackupStorage).
 */

/**
 * WordPress dependencies
 */
import { __, _n, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes, formatNumber } from '../data/format';

const amount = ( value ) => Math.max( 0, Number( value ) || 0 );

/**
 * @param {Object|undefined} storage `storage` of `GET /backups`.
 * @return {{own: number, other: number, free: (number|null), used: number}} Bytes; free null = the host does not tell.
 */
export function storageSums( storage ) {
	const own = amount( storage?.ownBytes );
	const other = amount( storage?.otherBytes );
	const free =
		null === storage?.free || undefined === storage?.free
			? null
			: amount( storage.free );
	return { own, other, free, used: own + other };
}

/**
 * @param {Object} rule A `keep` entry `{ count, bytes, days }`.
 * @return {boolean} Some limit deletes backups (0 = no such limit).
 */
const deletes = ( rule ) =>
	amount( rule.count ) > 0 ||
	amount( rule.bytes ) > 0 ||
	amount( rule.days ) > 0;

/**
 * "kept for 7 days, the newest 10 are kept, at most 20 GB in total".
 *
 * @param {Object} rule A `keep` entry that deletes().
 * @return {string} Its limits.
 */
function limitsText( rule ) {
	const days = amount( rule.days );
	const count = amount( rule.count );
	const bytes = amount( rule.bytes );
	return [
		days > 0 &&
			sprintf(
				/* translators: %s: number of days. */
				_n(
					'kept for %s day',
					'kept for %s days',
					days,
					'lw-migration'
				),
				formatNumber( days )
			),
		count > 0 &&
			sprintf(
				/* translators: %s: number of backups. */
				_n(
					'the newest %s is kept',
					'the newest %s are kept',
					count,
					'lw-migration'
				),
				formatNumber( count )
			),
		bytes > 0 &&
			sprintf(
				/* translators: %s: size, e.g. 20 GB. */
				__( 'at most %s in total', 'lw-migration' ),
				formatBytes( bytes )
			),
	]
		.filter( Boolean )
		.join( ', ' );
}

/**
 * One switched-on schedule's part of the line.
 *
 * @param {Object}  rule  Its `keep` entry `{ name, count, bytes, days }`.
 * @param {boolean} named More than one schedule: say which one.
 * @return {string} Text.
 */
function scheduleText( rule, named ) {
	if ( named && deletes( rule ) ) {
		return sprintf(
			/* translators: 1: schedule name, 2: how long its backups are kept. */
			_x( '%1$s: %2$s', 'schedule and its retention', 'lw-migration' ),
			rule.name,
			limitsText( rule )
		);
	}
	if ( named ) {
		return sprintf(
			/* translators: %s: schedule name. */
			__( '%s: never deleted', 'lw-migration' ),
			rule.name
		);
	}
	if ( deletes( rule ) ) {
		return sprintf(
			/* translators: %s: how long scheduled backups are kept. */
			__( 'Scheduled backups: %s', 'lw-migration' ),
			limitsText( rule )
		);
	}
	return __( 'Scheduled backups are never deleted', 'lw-migration' );
}

/**
 * The manual backups' part: lower case after a schedule's part.
 *
 * @param {Object}  rule  The first `keep` entry.
 * @param {boolean} after It follows a schedule's part.
 * @return {string} Text.
 */
function manualText( rule, after ) {
	if ( ! deletes( rule ) ) {
		return after
			? __( 'manual ones are never deleted', 'lw-migration' )
			: __( 'Manual backups are never deleted', 'lw-migration' );
	}
	if ( after ) {
		return sprintf(
			/* translators: %s: how long manual backups are kept. */
			__( 'ones made here by hand: %s', 'lw-migration' ),
			limitsText( rule )
		);
	}
	return sprintf(
		/* translators: %s: how long manual backups are kept. */
		__( 'Backups made here by hand: %s', 'lw-migration' ),
		limitsText( rule )
	);
}

/**
 * The retention line: each switched-on schedule's rule (by name when there
 * are several), then the manual backups' rule.
 *
 * @param {Array|undefined} keep `storage.keep` (the manual rule first).
 * @return {string} Text.
 */
export function keepText( keep ) {
	const rules = Array.isArray( keep ) ? keep : [];
	const manual = rules.find( ( rule ) => null === rule.schedule ) || {
		count: 0,
		bytes: 0,
		days: 0,
	};
	const schedules = rules.filter(
		( rule ) => null !== rule.schedule && undefined !== rule.schedule
	);
	return [
		...schedules.map( ( rule ) =>
			scheduleText( rule, schedules.length > 1 )
		),
		manualText( manual, schedules.length > 0 ),
	].join( ' · ' );
}

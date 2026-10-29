/**
 * The Overview's history chart without React: which days are shown, the
 * axis (a byte unit and round steps), each bar's place and height, the day
 * labels and the titles. The days come from the server as the site's own
 * calendar days (`2026-10-16`, GET /overview `history`), so they are written
 * as they are, never moved into the browser's time zone.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatBytes, pageLocale } from '../data/format';

/** The units of the axis (1024 apart, like formatBytes()). */
const UNITS = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

/** The drawing (viewBox units): width, height and the room around the plot. */
export const BOX = {
	width: 760,
	height: 190,
	left: 56,
	right: 10,
	top: 12,
	bottom: 30,
};

/** The height of a bar whose size is not known (only a destination holds it). */
const UNKNOWN_HEIGHT = 4;

/** The widest a day's bars get. */
const MAX_BAR = 26;

/**
 * @param {Object[]} history GET /overview `history` (oldest first).
 * @param {number}   days    14 or 30.
 * @return {Object[]} The last `days` days.
 */
export const lastDays = ( history, days ) => ( history || [] ).slice( -days );

/**
 * What the shown days hold: the backups (failed runs not counted) and the
 * bytes of those whose size is known.
 *
 * @param {Object[]} entries Days.
 * @return {{count: number, bytes: number, failed: number}} Totals.
 */
export function totals( entries ) {
	let count = 0;
	let bytes = 0;
	let failed = 0;
	for ( const entry of entries ) {
		for ( const backup of entry.backups ) {
			if ( 'failed' === backup.state ) {
				failed++;
				continue;
			}
			count++;
			bytes += Number( backup.size ) || 0;
		}
	}
	return { count, bytes, failed };
}

/**
 * The axis for the largest backup: the unit it is written in and round
 * steps (1, 2, 2.5 or 5 times a power of ten), four or five of them, the
 * top one at or above the largest backup.
 *
 * @param {number} maxBytes Largest size shown.
 * @return {{unit: number, ticks: number[], topBytes: number}} Unit index, tick values in that unit, the top in bytes.
 */
export function axisScale( maxBytes ) {
	let value = Math.max( 0, Number( maxBytes ) || 0 );
	let unit = 0;
	while ( value >= 1024 && unit < UNITS.length - 1 ) {
		value /= 1024;
		unit++;
	}
	if ( 0 === value ) {
		// Nothing with a size: an axis of 0 and 1 MB keeps the frame.
		return { unit: 2, ticks: [ 0, 1 ], topBytes: 1024 * 1024 };
	}
	const rough = value / 4;
	const power = Math.pow( 10, Math.floor( Math.log10( rough ) ) );
	const step =
		[ 1, 2, 2.5, 5, 10 ]
			.map( ( factor ) => factor * power )
			.find( ( candidate ) => candidate >= rough - 1e-9 ) || 10 * power;
	const top = step * Math.ceil( value / step - 1e-9 );
	const ticks = [];
	for ( let tick = 0; tick <= top + step / 2; tick += step ) {
		ticks.push( Math.round( tick * 1000 ) / 1000 );
	}
	return { unit, ticks, topBytes: top * Math.pow( 1024, unit ) };
}

/**
 * @param {number} value  Tick value in the axis unit.
 * @param {number} unit   Unit index.
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} "1.5 GB" / "1,5 GB".
 */
export const tickLabel = ( value, unit, locale = pageLocale() ) =>
	`${ new Intl.NumberFormat( locale, {
		maximumFractionDigits: 2,
		useGrouping: false,
	} ).format( value ) } ${ UNITS[ unit ] }`;

/**
 * A server day (`YYYY-MM-DD`, already the site's day) as an instant that
 * Intl writes back as the same day when told the time zone is UTC.
 *
 * @param {string} day Day.
 * @return {number} Milliseconds.
 */
const dayStamp = ( day ) => {
	const [ year, month, date ] = String( day ).split( '-' ).map( Number );
	return Date.UTC( year, month - 1, date, 12 );
};

/**
 * Short day under a bar: "10/16" / "10. 16.".
 *
 * @param {string} day    Day (`YYYY-MM-DD`).
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Label.
 */
export const dayLabel = ( day, locale = pageLocale() ) =>
	new Intl.DateTimeFormat( locale, {
		month: 'numeric',
		day: 'numeric',
		timeZone: 'UTC',
	} ).format( dayStamp( day ) );

/**
 * The day in a bar's title: "Oct 16" / "okt. 16.".
 *
 * @param {string} day    Day (`YYYY-MM-DD`).
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Label.
 */
export const dayName = ( day, locale = pageLocale() ) =>
	new Intl.DateTimeFormat( locale, {
		month: 'short',
		day: 'numeric',
		timeZone: 'UTC',
	} ).format( dayStamp( day ) );

/**
 * Whether a day gets its label: today, then every second day (every fifth
 * over 30 days), counted back from today.
 *
 * @param {number} fromEnd Days before today (0 = today).
 * @param {number} days    Days shown.
 * @return {boolean} Labelled.
 */
export const labelled = ( fromEnd, days ) =>
	0 === fromEnd % ( days > 14 ? 5 : 2 );

/**
 * The title of one bar or failed run.
 *
 * @param {string} day    Day.
 * @param {Object} backup `{ size, state, manual }`.
 * @param {string} locale BCP 47 tag.
 * @return {string} Title.
 */
export function barTitle( day, backup, locale = pageLocale() ) {
	const when = dayName( day, locale );
	if ( 'failed' === backup.state ) {
		return sprintf(
			/* translators: %s: day, e.g. Oct 16. */
			__( '%s: the scheduled backup failed', 'lw-migration' ),
			when
		);
	}
	const where =
		'cloud' === backup.state
			? __( 'uploaded to the cloud too', 'lw-migration' )
			: __( 'only on this server', 'lw-migration' );
	const size =
		null === backup.size || undefined === backup.size
			? __( 'size unknown', 'lw-migration' )
			: formatBytes( backup.size, locale );
	return backup.manual
		? sprintf(
				/* translators: 1: day, e.g. Oct 16; 2: size, e.g. 1.7 GB; 3: where it is kept. */
				__( '%1$s: manual backup, %2$s, %3$s', 'lw-migration' ),
				when,
				size,
				where
			)
		: sprintf(
				/* translators: 1: day, e.g. Oct 16; 2: size, e.g. 1.7 GB; 3: where it is kept. */
				__( '%1$s: %2$s, %3$s', 'lw-migration' ),
				when,
				size,
				where
			);
}

/**
 * The whole drawing: grid lines with their labels, and per day its x, its
 * label (or null) and its marks — a bar for a backup (to scale with the
 * axis; a short one when the size is not known), a cross for a failed run.
 * Several in a day share the day's width side by side.
 *
 * @param {Object[]} entries Days shown (oldest first, today last).
 * @param {string}   locale  BCP 47 tag (default: the page language).
 * @param {number}   width   Drawing width in CSS pixels (the box drawn 1:1, so
 *                           the axis text keeps its CSS size at any width).
 * @return {{grid: Object[], days: Object[], base: number}} Drawing.
 */
export function layout( entries, locale = pageLocale(), width = BOX.width ) {
	const { height, left, right, top, bottom } = BOX;
	const plot = height - top - bottom;
	const base = top + plot;
	const sizes = entries.flatMap( ( entry ) =>
		entry.backups.map( ( backup ) => Number( backup.size ) || 0 )
	);
	const scale = axisScale( Math.max( 0, ...sizes ) );
	const y = ( bytes ) => base - ( plot * bytes ) / scale.topBytes;
	const slot = ( width - left - right ) / Math.max( 1, entries.length );
	const barWidth = Math.min( MAX_BAR, slot * 0.6 );

	const grid = scale.ticks.map( ( tick ) => ( {
		y: y( tick * Math.pow( 1024, scale.unit ) ),
		label: tickLabel( tick, scale.unit, locale ),
	} ) );

	const days = entries.map( ( entry, index ) => {
		const x = left + slot * index + slot / 2;
		const fromEnd = entries.length - 1 - index;
		const count = entry.backups.length;
		const each = count ? barWidth / count : barWidth;
		const marks = entry.backups.map( ( backup, at ) => {
			const markX = x - barWidth / 2 + each * at;
			const title = barTitle( entry.day, backup, locale );
			if ( 'failed' === backup.state ) {
				return {
					kind: 'failed',
					x: markX + each / 2,
					size: Math.min( 10, Math.max( 4, each - 2 ) ),
					title,
				};
			}
			const known = null !== backup.size && undefined !== backup.size;
			const barHeight = known
				? Math.max( 2, base - y( Number( backup.size ) || 0 ) )
				: UNKNOWN_HEIGHT;
			return {
				kind: backup.state,
				x: markX + ( count > 1 ? 0.5 : 0 ),
				width: Math.max( 1, each - ( count > 1 ? 1 : 0 ) ),
				y: base - barHeight,
				height: barHeight,
				title,
			};
		} );
		let label = null;
		if ( labelled( fromEnd, entries.length ) ) {
			label =
				0 === fromEnd
					? __( 'today', 'lw-migration' )
					: dayLabel( entry.day, locale );
		}
		return { day: entry.day, x, label, marks };
	} );

	return { grid, days, base };
}

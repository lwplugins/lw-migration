/**
 * WordPress dependencies
 */
import { getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * The language of the admin page (`<html lang>`, the user's admin locale), or
 * undefined (the browser's own) when there is none or Intl cannot use it.
 *
 * @return {string|undefined} BCP 47 tag.
 */
export function pageLocale() {
	const lang = globalThis.document?.documentElement?.lang || '';
	if ( ! lang ) {
		return undefined;
	}
	try {
		return Intl.NumberFormat.supportedLocalesOf( [ lang ] ).length
			? lang
			: undefined;
	} catch {
		return undefined;
	}
}

/**
 * @param {number|string} value  Number.
 * @param {string}        locale BCP 47 tag (default: the page language).
 * @return {string} Grouped the way the locale writes numbers.
 */
export const formatNumber = ( value, locale = pageLocale() ) =>
	new Intl.NumberFormat( locale ).format( Number( value ) || 0 );

const DAY_TIME = {
	month: 'short',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
};
const TIME = { hour: '2-digit', minute: '2-digit' };
const DAY = { year: 'numeric', month: '2-digit', day: '2-digit' };

/**
 * The site's time zone for Intl (the WordPress setting core hands to
 * wp.date): its name, or UTC plus a shift for a site set to an offset only
 * (or to a name this browser does not know).
 *
 * @return {{timeZone: string, shift: number}} Zone and milliseconds to add.
 */
function siteZone() {
	const timezone = getSettings().timezone || {};
	if ( timezone.string ) {
		try {
			new Intl.DateTimeFormat( 'en', { timeZone: timezone.string } );
			return { timeZone: timezone.string, shift: 0 };
		} catch {
			// Unknown to this browser: use the offset below.
		}
	}
	return {
		timeZone: 'UTC',
		shift: ( Number( timezone.offset ) || 0 ) * 3600000,
	};
}

/**
 * One formatter for many moments in the site's time zone (builds the
 * Intl.DateTimeFormat once: long lists format hundreds of times).
 *
 * @param {Object} options Intl.DateTimeFormat options (no time zone).
 * @param {string} locale  BCP 47 tag (default: the page language).
 * @return {(ts: number) => string} Writes Unix seconds.
 */
export function siteTimeFormatter( options, locale = pageLocale() ) {
	const { timeZone, shift } = siteZone();
	const intl = new Intl.DateTimeFormat( locale, { ...options, timeZone } );
	return ( ts ) => intl.format( ts * 1000 + shift );
}

/**
 * A moment in the site's time zone, written the way the page language does.
 *
 * @param {number} ts      Unix seconds.
 * @param {Object} options Intl.DateTimeFormat options (no time zone).
 * @param {string} locale  BCP 47 tag (default: the page language).
 * @return {string} Text.
 */
export const formatSiteTime = ( ts, options, locale = pageLocale() ) =>
	siteTimeFormatter( options, locale )( ts );

/**
 * "okt. 11. 22:00" / "Oct 11, 10:00 PM" (site time zone).
 *
 * @param {number} ts     Unix seconds.
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Day and time.
 */
export const formatDayTime = ( ts, locale = pageLocale() ) =>
	formatSiteTime( ts, DAY_TIME, locale );

/**
 * A time of day without a date: "07:00" / "7:00 AM" style of the language.
 *
 * @param {number} hour   0-23.
 * @param {number} minute 0-59.
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Clock time.
 */
export const formatClock = ( hour, minute, locale = pageLocale() ) =>
	new Intl.DateTimeFormat( locale, { ...TIME, timeZone: 'UTC' } ).format(
		Date.UTC( 2000, 0, 1, hour, minute )
	);

/**
 * "today 22:00" for the current site day, else the day and time; "—" for 0.
 *
 * @param {number} ts     Unix seconds.
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Label.
 */
export function formatDatetime( ts, locale = pageLocale() ) {
	if ( ! ts ) {
		return '—';
	}
	const today = Math.floor( Date.now() / 1000 );
	if (
		formatSiteTime( ts, DAY, 'en' ) === formatSiteTime( today, DAY, 'en' )
	) {
		return sprintf(
			/* translators: %s: time of day, e.g. 14:02. */
			__( 'today %s', 'lw-migration' ),
			formatSiteTime( ts, TIME, locale )
		);
	}
	return formatDayTime( ts, locale );
}

/**
 * "20.4 MB" / "20,4 MB": whole numbers stay whole, the rest get one decimal
 * with the separator of the page language; no thousands grouping (below 1024).
 *
 * @param {number} bytes  Size.
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Label.
 */
export function formatBytes( bytes, locale = pageLocale() ) {
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let value = Math.max( 0, bytes || 0 );
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}
	const digits = Number.isInteger( value ) ? 0 : 1;
	const text = new Intl.NumberFormat( locale, {
		minimumFractionDigits: digits,
		maximumFractionDigits: digits,
		useGrouping: false,
	} ).format( value );
	return `${ text } ${ units[ unit ] }`;
}

/**
 * What the Backup section shows from GET /export/estimate, without React:
 * the size each part of the backup would have with the form's choices, the
 * composition bar's segments, and the state of the measuring. The figures
 * are uncompressed; the Advanced section's exclusions by name are not in
 * them (docs/react-admin-api.md).
 */

/** The bar's segments, in drawing order. */
export const SEGMENTS = [ 'media', 'database', 'plugins', 'themes', 'other' ];

/** The estimate parts the "other" segment adds up (always carried, or by choice). */
const OTHER_PARTS = [ 'other', 'muPlugins', 'cache' ];

const partOf = ( estimate, key ) => estimate?.parts?.[ key ] ?? null;

/**
 * Whether the estimate is a first measuring that is still under way: no
 * finished result yet (`measuredAt` null), so a file part with a figure is
 * the walk so far, which only grows. A failed later request leaves it so.
 *
 * @param {?Object} estimate Estimate.
 * @return {boolean} Its file figures are lower bounds.
 */
const firstWalk = ( estimate ) =>
	Boolean( estimate ) &&
	! estimate.complete &&
	null === ( estimate.measuredAt ?? null );

/**
 * How far a part is measured.
 *
 * @param {?Object} estimate GET /export/estimate response.
 * @param {string}  key      Part name (media, database, plugins, …).
 * @return {'known'|'growing'|'failed'|'unknown'} 'growing': a file part of a
 *   first measuring, its figure only a lower bound (the database is sized in
 *   one go, so its figure is final once there; its spam and revision counts
 *   stay null until every table is counted); 'failed': it could not be
 *   counted (a finished result names it in `partial`, or still has no figure
 *   for it, like a database it cannot read); 'unknown': not measured yet.
 */
export function partState( estimate, key ) {
	const missing = null === partOf( estimate, key );
	if (
		estimate?.complete &&
		( missing || ( estimate.partial || [] ).includes( key ) )
	) {
		return 'failed';
	}
	if ( missing ) {
		return 'unknown';
	}
	return 'database' !== key && firstWalk( estimate ) ? 'growing' : 'known';
}

/** The order in which states make a sum less certain. */
const WORST = [ 'failed', 'unknown', 'growing' ];

/**
 * The least certain of several states.
 *
 * @param {string[]} states partState() values.
 * @return {string} State.
 */
const worst = ( states ) =>
	WORST.find( ( state ) => states.includes( state ) ) || 'known';

/**
 * Whether a state has a figure (final or a lower bound).
 *
 * @param {string} state partState().
 * @return {boolean} A figure is there.
 */
const figured = ( state ) => 'known' === state || 'growing' === state;

/**
 * The bytes of a code part (plugins or themes) that go in with the choices.
 *
 * @param {Object}  part        { bytes, activeBytes }.
 * @param {boolean} none        noPlugins / noThemes.
 * @param {boolean} onlyActives noInactivePlugins / noInactiveThemes.
 * @return {number} Bytes.
 */
const codeBytes = ( part, none, onlyActives ) => {
	if ( none ) {
		return 0;
	}
	return onlyActives ? ( part.activeBytes ?? part.bytes ) : part.bytes;
};

/**
 * One segment of the bar with the form's choices.
 *
 * @param {?Object} estimate Estimate.
 * @param {string}  key      Segment (SEGMENTS).
 * @param {Object}  o        Form state (exportRequest DEFAULTS shape).
 * @return {{key: string, bytes: ?number, full: ?number, off: boolean, state: string}}
 *   `bytes` what goes in (null without a figure; a lower bound while
 *   'growing'), `full` the part with everything in, `off` the choices leave
 *   it out, `state` partState() of the segment (for "other" the least
 *   certain of the parts that go in).
 */
export function segment( estimate, key, o ) {
	if ( 'other' === key ) {
		// Only the parts that go in decide how sure the figure is.
		const used = OTHER_PARTS.filter(
			( name ) =>
				( 'muPlugins' !== name || ! o.noMustUsePlugins ) &&
				( 'cache' !== name || ! o.noCache )
		);
		const state = worst(
			used.map( ( name ) => partState( estimate, name ) )
		);
		const sum = ( names ) =>
			names.reduce(
				( total, name ) =>
					total + ( partOf( estimate, name )?.bytes ?? 0 ),
				0
			);
		return {
			key,
			bytes: figured( state ) ? sum( used ) : null,
			full: figured( state ) ? sum( OTHER_PARTS ) : null,
			off: false,
			state,
		};
	}
	const state = partState( estimate, key );
	const part = figured( state ) ? partOf( estimate, key ) : null;
	const off = {
		media: Boolean( o.noMedia ),
		database: Boolean( o.noDatabase ),
		plugins: Boolean( o.noPlugins ),
		themes: Boolean( o.noThemes ),
	}[ key ];
	let bytes = null;
	if ( part ) {
		if ( 'plugins' === key ) {
			bytes = codeBytes( part, o.noPlugins, o.noInactivePlugins );
		} else if ( 'themes' === key ) {
			bytes = codeBytes( part, o.noThemes, o.noInactiveThemes );
		} else {
			bytes = off ? 0 : part.bytes;
		}
	}
	return { key, bytes, full: part ? part.bytes : null, off, state };
}

/**
 * The bar: every segment, and the total of what is known to go in.
 *
 * @param {?Object} estimate Estimate.
 * @param {Object}  o        Form state.
 * @return {{segments: Object[], total: number, known: boolean, missing: boolean, failed: boolean}}
 *   `known` some figure is there to show; `missing` the total is only a
 *   lower bound (a part that goes in is not measured yet, still growing, or
 *   could not be counted); `failed` one of them could not be counted.
 */
export function composition( estimate, o ) {
	const segments = SEGMENTS.map( ( key ) => segment( estimate, key, o ) );
	const counted = segments.filter( ( s ) => null !== s.bytes );
	const lacking = segments.filter(
		( s ) => ! s.off && ( null === s.bytes || 'growing' === s.state )
	);
	return {
		segments,
		total: counted.reduce( ( sum, s ) => sum + s.bytes, 0 ),
		known: counted.length > 0,
		missing: lacking.length > 0,
		failed: lacking.some( ( s ) => 'failed' === s.state ),
	};
}

/**
 * Where the measuring is.
 *
 * @param {?Object} estimate Estimate (null before the first answer).
 * @param {?Object} error    The last request's failure.
 * @return {'loading'|'unavailable'|'stopped'|'counting'|'done'} 'stopped':
 *   a later request failed while it was still counting (the figures so far
 *   stay); 'unavailable': no figure could be had.
 */
export function estimateStatus( estimate, error ) {
	if ( ! estimate ) {
		return error ? 'unavailable' : 'loading';
	}
	if ( ! estimate.complete ) {
		return error ? 'stopped' : 'counting';
	}
	return 'done';
}

/**
 * A part's final figures, for the counts in the help lines; null while it
 * is not measured, still growing or could not be counted.
 *
 * @param {?Object} estimate Estimate.
 * @param {string}  key      Part name.
 * @return {?Object} The part.
 */
export const knownPart = ( estimate, key ) =>
	'known' === partState( estimate, key ) ? partOf( estimate, key ) : null;

/**
 * A part's figures, final or so far (check partState() for which).
 *
 * @param {?Object} estimate Estimate.
 * @param {string}  key      Part name.
 * @return {?Object} The part, or null without a figure.
 */
export const figuresOf = ( estimate, key ) =>
	figured( partState( estimate, key ) ) ? partOf( estimate, key ) : null;

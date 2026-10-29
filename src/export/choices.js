/**
 * The Backup section's controls over the request flags (exportRequest.js
 * FLAG_KEYS), without React: the plugins and themes choice ("all / active
 * only / none") stands for two flags each, and the folded Advanced section
 * counts what is set in it.
 */

/** The two flags behind each code choice. */
const CODE_FLAGS = {
	plugins: { none: 'noPlugins', inactive: 'noInactivePlugins' },
	themes: { none: 'noThemes', inactive: 'noInactiveThemes' },
};

/**
 * The choice the flags make: leaving them all out wins over "active only".
 *
 * @param {Object}             o    Form state.
 * @param {'plugins'|'themes'} kind Which code.
 * @return {'all'|'active'|'none'} Choice.
 */
export function codeChoice( o, kind ) {
	const flags = CODE_FLAGS[ kind ];
	if ( o[ flags.none ] ) {
		return 'none';
	}
	return o[ flags.inactive ] ? 'active' : 'all';
}

/**
 * The form-state patch for a choice: both flags, so the request never
 * carries the two at once.
 *
 * @param {'plugins'|'themes'}    kind   Which code.
 * @param {'all'|'active'|'none'} choice Choice.
 * @return {Object} Patch.
 */
export function codePatch( kind, choice ) {
	const flags = CODE_FLAGS[ kind ];
	return {
		[ flags.none ]: 'none' === choice,
		[ flags.inactive ]: 'active' === choice,
	};
}

const filled = ( text ) => '' !== String( text || '' ).trim();

/**
 * How many settings of the Advanced section differ from the defaults (shown
 * while it is folded, so nothing set there goes unnoticed).
 *
 * @param {Object} o Form state.
 * @return {number} Count.
 */
export const advancedCount = ( o ) =>
	[
		( o.replace || [] ).some(
			( [ from, to ] ) => filled( from ) || filled( to )
		),
		filled( o.excludeFiles ),
		filled( o.excludeTables ),
		filled( o.includeTables ),
		Boolean( o.noEmailReplace ),
	].filter( Boolean ).length;

/**
 * Whether the Advanced section leaves something out by name, which the size
 * estimate does not take into account.
 *
 * @param {Object} o Form state.
 * @return {boolean} Exclusions by name are set.
 */
export const excludesByName = ( o ) =>
	filled( o.excludeFiles ) ||
	filled( o.excludeTables ) ||
	filled( o.includeTables );

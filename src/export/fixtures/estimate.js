/**
 * Estimates for the Backup section's tests: the docs/react-admin-api.md
 * example in round megabytes, and the same before the file walk.
 */

export const MB = 1024 * 1024;

/**
 * @param {Object} patch Top-level keys to change.
 * @return {Object} A finished estimate.
 */
export const estimate = ( patch = {} ) => ( {
	parts: {
		media: { bytes: 1100 * MB, files: 4812 },
		database: {
			bytes: 212 * MB,
			tables: 64,
			spamComments: 412,
			revisions: 3270,
		},
		plugins: {
			bytes: 286 * MB,
			count: 31,
			activeBytes: 198 * MB,
			activeCount: 24,
		},
		themes: {
			bytes: 96 * MB,
			count: 4,
			activeBytes: 41 * MB,
			activeCount: 1,
		},
		muPlugins: { bytes: 2 * MB, count: 2 },
		cache: { bytes: 38 * MB },
		other: { bytes: 92 * MB },
	},
	complete: true,
	partial: [],
	measuredAt: 1760600000,
	freeSpace: null,
	scope: 'site',
	...patch,
} );

/** The file parts (`partial` lists these when the file walk was given up). */
export const FILE_PARTS = [
	'media',
	'plugins',
	'themes',
	'muPlugins',
	'cache',
	'other',
];
export const withoutFiles = ( patch ) =>
	estimate( {
		parts: {
			...estimate().parts,
			...Object.fromEntries( FILE_PARTS.map( ( key ) => [ key, null ] ) ),
		},
		...patch,
	} );

/**
 * A first measuring under way: no finished result yet (`measuredAt` null),
 * the database sized, the file parts the walk so far (they only grow).
 *
 * @param {Object} patch Top-level keys to change.
 * @return {Object} Estimate.
 */
export const firstWalk = ( patch = {} ) =>
	estimate( { complete: false, measuredAt: null, ...patch } );

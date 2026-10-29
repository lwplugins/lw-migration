/**
 * Tests only: `GET /reset` of a single site and of a network.
 */

const open = { available: true, reason: null, reasonText: '' };

export const SITE = {
	network: false,
	phrase: 'example.com/shop',
	backupReady: true,
	dropIns: [ 'object-cache.php' ],
	muPlugins: 2,
	keptPlugins: [ { plugin: 'cache/cache.php', by: 'object-cache.php' } ],
	types: {
		plugins: { ...open, count: 12, names: [ 'a/a.php' ], more: 11 },
		themes: {
			...open,
			target: {
				slug: 'tt5',
				name: 'Twenty Twenty-Five',
				source: 'installed',
			},
			count: 3,
			names: [ 'one', 'two', 'three' ],
			more: 0,
		},
		media: { ...open, attachments: 1284, folder: '[uploads]' },
		database: {
			...open,
			user: 'admin',
			tables: {
				replaced: 12,
				retired: 2,
				kept: 9,
				retiredNames: [ 'wp_a', 'wp_b' ],
				more: 0,
			},
			prefixWarning: false,
			connections: 2,
			schedules: 3,
			otherUsers: 5,
		},
		full: open,
	},
};

export const NETWORK = {
	...SITE,
	network: true,
	dropIns: [],
	keptPlugins: [],
	muPlugins: 0,
	types: {
		...SITE.types,
		media: { ...open, attachments: null, folder: '[uploads]' },
		database: {
			available: false,
			reason: 'network',
			reasonText: '',
			user: 'admin',
			tables: {
				replaced: 0,
				retired: 0,
				kept: 0,
				retiredNames: [],
				more: 0,
			},
			prefixWarning: false,
			connections: 0,
			schedules: 0,
		},
		full: { available: false, reason: 'network', reasonText: '' },
	},
};

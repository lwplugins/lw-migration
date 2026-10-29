/**
 * Confirm summaries (`job.input.summary`, docs/react-admin-api.md) for the
 * check view's tests: an archive of this plugin, an encrypted one, one made
 * by the reference .wpress tool (no optional metadata), one whose headers
 * could not be read, sites restored into a network, and one network site
 * restored into a single site.
 */

const part = ( included, bytes, count ) => ( { included, bytes, count } );

/** A plain archive of another site made by this plugin. */
export const plain = {
	from: 'https://dev2.example',
	to: 'https://croco2.example',
	replacesAddress: true,
	wordpress: { archive: '7.1', site: '7.1' },
	php: { archive: '8.2.29', site: '8.4.20' },
	prefix: { archive: 'wp_', site: 'wp_' },
	createdAt: 1760307300,
	archiveSize: 3242719232,
	expandedSize: 3650000000,
	encrypted: false,
	compression: '',
	parts: {
		database: part( true, 222298112, null ),
		media: part( true, 2254857830, 6120 ),
		plugins: part( true, 299892736, 27 ),
		themes: part( false, 0, 0 ),
		muPlugins: part( true, 419430, 2 ),
	},
	keepsThisPlugin: true,
	overwrites: { database: true, media: true, plugins: true, themes: false },
	subsite: null,
	safetyBackup: true,
	safetyBytes: 1868310528,
	diskNeeded: 8353748992,
	diskFree: 44237948928,
	lowDiskSpace: false,
	network: null,
};

/** Encrypted and compressed: stored sizes only, no expanded size. */
export const encrypted = {
	...plain,
	from: 'https://croco2.example/',
	replacesAddress: false,
	encrypted: true,
	compression: 'gzip',
	expandedSize: null,
};

/** Made by the reference tool: no versions, prefix or time; contents read from the headers. */
export const reference = {
	...plain,
	wordpress: { archive: '', site: '7.1' },
	php: { archive: '', site: '8.4.20' },
	prefix: { archive: null, site: 'wp_' },
	createdAt: null,
	expandedSize: null,
};

/** The headers could not be read: only the package.json flags are known. */
export const unreadable = {
	...reference,
	from: '',
	replacesAddress: null,
	parts: {
		database: part( null, null, null ),
		media: part( null, null, null ),
		plugins: part( false, null, null ),
		themes: part( null, null, null ),
		muPlugins: part( null, null, null ),
	},
	diskFree: null,
};

/** Two sites of a network restored into this network. */
export const networkSites = {
	...plain,
	from: 'http://net-n.test',
	to: 'https://net.example',
	network: {
		kind: 'sites',
		sites: [
			{
				index: 0,
				from: { blogId: 2, url: 'http://net-n.test/a/' },
				proposal: 'https://net.example/a/',
				address: '',
				existing: null,
				error: null,
			},
			{
				index: 1,
				from: { blogId: 3, url: 'http://net-n.test/b/' },
				proposal: 'https://net.example/b/',
				address: '',
				existing: 4,
				error: null,
			},
		],
		unlisted: [],
		error: null,
	},
};

/** A whole network archive into this network. */
export const wholeNetwork = {
	...networkSites,
	network: { ...networkSites.network, kind: 'network' },
};

/** Site 3 of a network archive restored into this single site. */
export const subsite = { ...plain, subsite: 3 };

/** Too little room: the safety backup cannot be made. */
export const lowSpace = {
	...plain,
	diskNeeded: 8353748992,
	diskFree: 7000000000,
	lowDiskSpace: true,
};

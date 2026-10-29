/**
 * The Overview's words without React: the status headline and its lead,
 * the running job's line, a schedule's run dots, a connection's line and
 * the links of the attention items. Everything is read from GET /overview
 * as it is (docs/react-admin-api.md); nothing here asks the server.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatDatetime, formatSiteTime, pageLocale } from '../data/format';
import { allowText } from '../links/links';

/**
 * Without a switched-on schedule, after this many seconds the newest backup
 * is no longer recent.
 */
export const STALE_AFTER = 2 * 86400;

/** A schedule's backup may come this much later than its interval. */
export const LATE_GRACE = 12 * 3600;

/**
 * "3 hours ago" / "3 órával ezelőtt", "yesterday" / "tegnap".
 *
 * @param {number} ts     Unix seconds.
 * @param {number} now    Unix seconds.
 * @param {string} locale BCP 47 tag (default: the page language).
 * @return {string} Text.
 */
export function agoText( ts, now, locale = pageLocale() ) {
	const words = new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } );
	const seconds = Math.max( 0, now - ts );
	if ( seconds < 3600 ) {
		return words.format(
			-Math.max( 1, Math.round( seconds / 60 ) ),
			'minute'
		);
	}
	if ( seconds < 86400 ) {
		return words.format( -Math.floor( seconds / 3600 ), 'hour' );
	}
	return words.format( -Math.floor( seconds / 86400 ), 'day' );
}

/**
 * Names joined the way the language lists them: "A, B and C".
 *
 * @param {string[]} names  Names.
 * @param {string}   locale BCP 47 tag (default: the page language).
 * @return {string} List.
 */
export const listText = ( names, locale = pageLocale() ) =>
	new Intl.ListFormat( locale, { type: 'conjunction' } ).format( names );

/**
 * Which headline the status block shows. The site is protected only when
 * the newest good backup is recent (a switched-on schedule: within its
 * interval plus LATE_GRACE; else within STALE_AFTER) and the backups folder
 * needs no look; otherwise the headline names the reason.
 *
 * @param {Object} data GET /overview.
 * @param {number} now  Unix seconds.
 * @return {string} none (no backup at all) | failed (the newest scheduled
 *                  run failed) | partial (an upload of the newest backup
 *                  failed) | late (a switched-on schedule's backup is
 *                  overdue) | stale (no schedule is on and the backup is
 *                  older than STALE_AFTER) | folder (the backups folder
 *                  needs a look) | ok.
 */
export function headlineKind( data, now ) {
	const last = data.last;
	if ( ! last ) {
		return 'none';
	}
	if ( 'failed' === last.outcome ) {
		return 'failed';
	}
	if ( 'partial' === last.outcome ) {
		return 'partial';
	}
	const scheduled = Boolean( data.schedule?.enabled );
	const interval = Number( data.schedule?.interval ) || 0;
	const limit =
		scheduled && interval > 0 ? interval + LATE_GRACE : STALE_AFTER;
	if ( now - last.time > limit ) {
		return scheduled ? 'late' : 'stale';
	}
	return false === data.folder?.protected ? 'folder' : 'ok';
}

/**
 * Where the newest backup is: the uploads that worked, are under way or
 * failed, and whether the local file stays.
 *
 * @param {Object} last   GET /overview `last`.
 * @param {string} locale BCP 47 tag.
 * @return {string[]} Sentences.
 */
function whereSentences( last, locale ) {
	const named = ( status ) =>
		( last.cloud || [] )
			.filter( ( copy ) => copy.status === status )
			.map( ( copy ) => copy.name );
	const ok = named( 'ok' );
	const pending = named( 'pending' );
	const failed = named( 'failed' );
	const sentences = [];
	if ( ok.length ) {
		sentences.push(
			sprintf(
				/* translators: %s: destination names, e.g. Google Drive and SFTP. */
				__( 'It was uploaded to %s.', 'lw-migration' ),
				listText( ok, locale )
			)
		);
	}
	if ( pending.length ) {
		sentences.push(
			sprintf(
				/* translators: %s: destination names. */
				__( 'It is being uploaded to %s.', 'lw-migration' ),
				listText( pending, locale )
			)
		);
	}
	if ( failed.length ) {
		sentences.push(
			sprintf(
				/* translators: %s: destination names. */
				__( 'The upload to %s failed.', 'lw-migration' ),
				listText( failed, locale )
			)
		);
	}
	if ( ! last.local ) {
		sentences.push(
			__( 'Only the uploaded copies are kept.', 'lw-migration' )
		);
	} else if ( ( last.cloud || [] ).length ) {
		sentences.push(
			__( 'A copy stays in the backups folder.', 'lw-migration' )
		);
	} else {
		sentences.push(
			__(
				'It is kept only in the backups folder on this server.',
				'lw-migration'
			)
		);
	}
	return sentences;
}

/**
 * The status block's headline and lead.
 *
 * @param {Object}  data    GET /overview.
 * @param {number}  now     Unix seconds.
 * @param {boolean} network The page runs on a network.
 * @param {string}  locale  BCP 47 tag (default: the page language).
 * @return {{kind: string, title: string, lead: string}} Texts.
 */
export function headline( data, now, network = false, locale = pageLocale() ) {
	const kind = headlineKind( data, now );
	if ( 'none' === kind ) {
		return {
			kind,
			title: network
				? __( 'No backup of this network yet', 'lw-migration' )
				: __( 'No backup of this site yet', 'lw-migration' ),
			lead: __(
				'Start the first one below: files and database go into one .wpress file in the protected backups folder.',
				'lw-migration'
			),
		};
	}
	const last = data.last;
	const ago = agoText( last.time, now, locale );
	let title;
	if ( 'failed' === kind ) {
		title = __( 'The last scheduled backup failed', 'lw-migration' );
		const good = sprintf(
			/* translators: %s: how long ago, e.g. 3 hours ago. */
			__( 'The newest good backup was made %s.', 'lw-migration' ),
			ago
		);
		return {
			kind,
			title,
			lead: [ good, ...whereSentences( last, locale ) ].join( ' ' ),
		};
	}
	if ( 'partial' === kind ) {
		title = sprintf(
			/* translators: %s: how long ago, e.g. 3 hours ago. */
			__(
				'The last backup was made %s, but an upload failed',
				'lw-migration'
			),
			ago
		);
	} else if ( 'late' === kind ) {
		title = sprintf(
			/* translators: %s: how long ago, e.g. 5 days ago. */
			__(
				'The scheduled backup is late: the last one was made %s',
				'lw-migration'
			),
			ago
		);
	} else if ( 'stale' === kind ) {
		title = sprintf(
			/* translators: %s: how long ago, e.g. 5 days ago. */
			__(
				'The last backup was made %s and no schedule is on',
				'lw-migration'
			),
			ago
		);
	} else if ( 'folder' === kind ) {
		title = sprintf(
			/* translators: %s: how long ago, e.g. 3 hours ago. */
			__(
				'The last backup was made %s, but the backups folder needs a look',
				'lw-migration'
			),
			ago
		);
	} else {
		title = network
			? sprintf(
					/* translators: %s: how long ago, e.g. 3 hours ago. */
					__(
						'Your network is protected: the last backup was made %s',
						'lw-migration'
					),
					ago
				)
			: sprintf(
					/* translators: %s: how long ago, e.g. 3 hours ago. */
					__(
						'Your site is protected: the last backup was made %s',
						'lw-migration'
					),
					ago
				);
	}
	const made = sprintf(
		/* translators: %s: day and time, e.g. today 03:00. */
		__( 'Made %s.', 'lw-migration' ),
		formatDatetime( last.time, locale )
	);
	return {
		kind,
		title,
		lead: [ made, ...whereSentences( last, locale ) ].join( ' ' ),
	};
}

/**
 * What a running job does, by its type.
 *
 * @param {string} type Job type.
 * @return {string} Sentence start.
 */
export const runningText = ( type ) =>
	( {
		export: __( 'A backup is running', 'lw-migration' ),
		import: __( 'A restore is running', 'lw-migration' ),
		cloud: __( 'An upload or download is running', 'lw-migration' ),
		move: __( 'The backups are being moved', 'lw-migration' ),
		reset: __( 'A reset is running', 'lw-migration' ),
		push: __(
			'This site is being sent to a connected site',
			'lw-migration'
		),
	} )[ type ] || __( 'A job is running', 'lw-migration' );

/**
 * The run dots of the schedule block: its last runs, oldest first.
 *
 * @param {Object[]} runs  GET /overview `schedule.runs` (newest first).
 * @param {number}   count How many.
 * @return {Object[]} Runs.
 */
export const lastRuns = ( runs, count = 14 ) =>
	( runs || [] ).slice( 0, count ).reverse();

/**
 * The spoken summary of the run dots.
 *
 * @param {Object[]} runs Runs shown.
 * @return {string} Text.
 */
export function runsSummary( runs ) {
	const failed = runs.filter( ( run ) => 'ok' !== run.outcome ).length;
	return sprintf(
		/* translators: 1: number of runs; 2: runs that worked; 3: runs that did not. */
		_n(
			'The last %1$d scheduled run: %2$d worked, %3$d did not.',
			'The last %1$d scheduled runs: %2$d worked, %3$d did not.',
			runs.length,
			'lw-migration'
		),
		runs.length,
		runs.length - failed,
		failed
	);
}

/**
 * One run dot's title.
 *
 * @param {Object} run    `{ time, outcome, reason }`.
 * @param {string} locale BCP 47 tag.
 * @return {string} Title.
 */
export function runTitle( run, locale = pageLocale() ) {
	const when = formatSiteTime(
		run.time,
		{ month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' },
		locale
	);
	const word = {
		ok: __( 'worked', 'lw-migration' ),
		partial: __( 'an upload failed', 'lw-migration' ),
		failed: __( 'failed', 'lw-migration' ),
	}[ run.outcome ];
	const text = run.reason ? `${ word }: ${ run.reason }` : word;
	return `${ when }: ${ text }`;
}

/**
 * The note under the dots about the newest run that did not work, and
 * whether every run since worked; '' when every shown run worked.
 *
 * @param {Object[]} runs   Runs shown (oldest first).
 * @param {string}   locale BCP 47 tag.
 * @return {string} Text.
 */
export function failureNote( runs, locale = pageLocale() ) {
	let at = runs.length - 1;
	while ( at >= 0 && 'ok' === runs[ at ].outcome ) {
		at--;
	}
	if ( -1 === at ) {
		return '';
	}
	const run = runs[ at ];
	const when = formatSiteTime(
		run.time,
		{ month: 'short', day: 'numeric' },
		locale
	);
	let text;
	if ( 'partial' === run.outcome ) {
		text = run.reason
			? sprintf(
					/* translators: 1: day, e.g. Oct 8; 2: the reason. */
					__( 'On %1$s an upload failed: %2$s', 'lw-migration' ),
					when,
					run.reason
				)
			: sprintf(
					/* translators: %s: day, e.g. Oct 8. */
					__( 'On %s an upload failed.', 'lw-migration' ),
					when
				);
	} else {
		text = run.reason
			? sprintf(
					/* translators: 1: day, e.g. Oct 8; 2: the reason. */
					__( 'On %1$s the backup failed: %2$s', 'lw-migration' ),
					when,
					run.reason
				)
			: sprintf(
					/* translators: %s: day, e.g. Oct 8. */
					__( 'On %s the backup failed.', 'lw-migration' ),
					when
				);
	}
	return at < runs.length - 1
		? `${ text } ${ __( 'Every run since then worked.', 'lw-migration' ) }`
		: text;
}

/**
 * What a connection lets do, with the sentence of the Connections section:
 * `direction` is the rights, `incoming` says which site uses them.
 *
 * @param {Object} link GET /overview `links[]` row.
 * @return {string} Text.
 */
export const linkRights = ( link ) =>
	allowText(
		'both' === link.direction
			? [ 'push', 'pull' ]
			: [ String( link.direction ) ],
		link.incoming ? 'in' : 'out'
	);

/**
 * The link text of an attention item, by kind.
 *
 * @param {string} kind Attention kind.
 * @return {string} Label.
 */
export const attentionAction = ( kind ) =>
	( {
		reauth: __( 'Sign in again', 'lw-migration' ),
		hostKey: __( 'Check the fingerprint', 'lw-migration' ),
		certificate: __( 'Check the fingerprint', 'lw-migration' ),
		error: __( 'Open Destinations', 'lw-migration' ),
		folder: __( 'Open Settings', 'lw-migration' ),
		space: __( 'Open Backups', 'lw-migration' ),
		failedRun: __( 'Open Schedules', 'lw-migration' ),
	} )[ kind ] || __( 'Open', 'lw-migration' );

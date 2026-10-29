/**
 * The job screen's cancel texts: what cancelling leaves behind, by job type,
 * and what goes on instead when a cancel comes past the point of no return.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

// What cancelling leaves behind, by job type (the rest are restores).
export const CANCEL_TEXTS = {
	export: __(
		'Cancel this backup? The unfinished file is removed.',
		'lw-migration'
	),
	move: __(
		'Stop moving? Backups already moved stay in the new folder, the others in the old one; both stay listed until you finish or undo the move.',
		'lw-migration'
	),
	import: __(
		'Cancel this restore? Nothing more is written; files already put in place stay.',
		'lw-migration'
	),
	push: __(
		"Stop sending? Once you confirmed the other site's summary, sending finishes instead.",
		'lw-migration'
	),
	reset: __(
		'Stop the reset? What was already deleted stays deleted; once the database is being replaced, the reset finishes instead.',
		'lw-migration'
	),
	cloud: __(
		'Cancel this transfer? A started upload is stopped at the destination; an unfinished download is removed.',
		'lw-migration'
	),
};

// A cancel asked for past the point of no return: what goes on instead.
export const DEFERRED = {
	push: {
		said: __(
			'The other site may already be replacing its content, so sending cannot be cancelled any more. Its last steps are finishing now.',
			'lw-migration'
		),
		asked: __(
			"Cancelling was asked for after the other site's summary was confirmed: the other site may already be replacing its content, so sending cannot stop any more. Its last steps are finishing; keep this page open until it is done.",
			'lw-migration'
		),
		past: __(
			'The other site may already be replacing its content; the last steps are finishing. Keep this page open until it is done.',
			'lw-migration'
		),
	},
	reset: {
		said: __(
			'The database is already replaced, so the reset cannot be cancelled any more. Its remaining steps are finishing now.',
			'lw-migration'
		),
		asked: __(
			'Cancelling was asked for after the database was replaced, so the reset cannot stop any more. Its remaining steps are finishing; keep this page open until it is done.',
			'lw-migration'
		),
		past: __(
			'The database is already replaced; the remaining steps are finishing. Keep this page open until it is done.',
			'lw-migration'
		),
	},
	import: {
		said: __(
			'The database is already replaced, so the restore cannot be cancelled any more. Its last steps are finishing now.',
			'lw-migration'
		),
		asked: __(
			'Cancelling was asked for after the database was replaced, so the restore cannot stop any more. Its last steps are finishing; keep this page open until it is done.',
			'lw-migration'
		),
		past: __(
			'The database is already replaced; the last steps are finishing. Keep this page open until it is done.',
			'lw-migration'
		),
	},
};

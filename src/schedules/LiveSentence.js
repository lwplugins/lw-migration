/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { previewRuns } from './preview';
import { liveSentence } from './sentence';
import { runWriter } from './when';

/**
 * The editor's top line: what the form would do, as sentences, and its
 * next three runs as a preview (computed here from the form; the saved
 * schedule's card shows the server's times).
 *
 * @param {Object}   props
 * @param {Object}   props.form     Form state.
 * @param {string[]} props.names    Names of the chosen destinations.
 * @param {string}   props.timezone The site's time zone.
 */
export default function LiveSentence( { form, names, timezone } ) {
	const now = Math.floor( Date.now() / 1000 );
	const { timing, rest } = liveSentence( form, names );
	const write = runWriter( now, timezone );

	return (
		<div className="lw-sheet-sentence">
			<p>
				{ createInterpolateElement( timing, { b: <b /> } ) } { rest }
			</p>
			<p className="lw-sheet-sentence__runs">
				{ form.enabled
					? sprintf(
							/* translators: %s: the next run times, e.g. tomorrow 03:00 · Sunday 18 Oct, 03:00. */
							__( 'Next runs (a preview): %s', 'lw-migration' ),
							previewRuns( form, now, timezone )
								.map( write )
								.join( ' · ' )
						)
					: __(
							'Switched off: it runs only when you start it.',
							'lw-migration'
						) }
			</p>
		</div>
	);
}

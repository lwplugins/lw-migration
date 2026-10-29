/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, lockOutline } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { jobTime } from './clock';
import { BrowserTab } from './icons';

/** The footer note's id: a Cancel that is off is described by it. */
export const NOTE_ID = 'lw-job-note';

/**
 * One footer note: an icon and its text.
 *
 * @param {Object}  props
 * @param {Element} props.icon     Icon (decorative).
 * @param {Element} props.children Text.
 */
export function Note( { icon, children } ) {
	return (
		<p className="lw-job__note" id={ NOTE_ID }>
			{ icon }
			<span>{ children }</span>
		</p>
	);
}

/**
 * "Last progress at 15:22." (nothing without a save time).
 *
 * @param {Object} props
 * @param {Object} props.job Job view.
 */
export function LastProgress( { job } ) {
	if ( ! job.updatedAt ) {
		return null;
	}
	return (
		<Note icon={ <BrowserTab /> }>
			{ createInterpolateElement(
				sprintf(
					/* translators: %s: when the job last made progress, e.g. 15:22. */
					__(
						'Last progress at <strong>%s</strong>.',
						'lw-migration'
					),
					jobTime( job.updatedAt, Date.now() / 1000 )
				),
				{ strong: <strong /> }
			) }
		</Note>
	);
}

/**
 * The note of a job this page drives: why Cancel is off past the point of no
 * return; when a stopped job (also after a failure) last moved; otherwise to
 * keep the tab open.
 *
 * @param {Object}  props
 * @param {Object}  props.job     Job view.
 * @param {string}  props.past    What goes on past the point of no return (empty: said above).
 * @param {boolean} props.stopped The job does not move.
 */
export function FooterNote( { job, past, stopped } ) {
	if ( job.pastNoReturn ) {
		return (
			<Note icon={ <Icon icon={ lockOutline } size={ 20 } /> }>
				<strong>
					{ __(
						'It can no longer be cancelled at this point.',
						'lw-migration'
					) }
				</strong>
				{ past && ` ${ past }` }
			</Note>
		);
	}
	if ( stopped ) {
		return <LastProgress job={ job } />;
	}
	return (
		<Note icon={ <BrowserTab /> }>
			<strong>{ __( 'Keep this tab open.', 'lw-migration' ) }</strong>{ ' ' }
			{ __(
				'The job runs from this page: if you close it, it stops, and you can continue it later.',
				'lw-migration'
			) }
		</Note>
	);
}

/**
 * The card's Cancel: "Cancelling…" while the cancel is on its way; when it
 * is off for a reason, the note says why.
 *
 * @param {Object}                  props
 * @param {boolean}                 props.cancelling The cancel is on its way.
 * @param {boolean}                 props.disabled   Off.
 * @param {boolean}                 props.explained  The note says why it is off.
 * @param {(event: Object) => void} props.onClick    Ask to cancel.
 */
export function CancelButton( { cancelling, disabled, explained, onClick } ) {
	return (
		<Button
			__next40pxDefaultSize
			variant="secondary"
			isDestructive
			disabled={ cancelling || disabled }
			accessibleWhenDisabled
			aria-describedby={ explained ? NOTE_ID : undefined }
			onClick={ onClick }
		>
			{ cancelling
				? __( 'Cancelling…', 'lw-migration' )
				: __( 'Cancel', 'lw-migration' ) }
		</Button>
	);
}

/**
 * The job card's footer: the note and the actions.
 *
 * @param {Object}  props
 * @param {Element} props.note     The note.
 * @param {Element} props.children The actions (none: no action row).
 */
export default function JobFooter( { note, children } ) {
	return (
		<footer className="lw-job__foot">
			{ note }
			{ children && <div className="lw-actions">{ children }</div> }
		</footer>
	);
}

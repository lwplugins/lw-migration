/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, link } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import CancelConfirm from './CancelConfirm';
import JobClock from './JobClock';
import JobFooter, { CancelButton, Note } from './JobFooter';
import JobResult from './JobResult';
import JobTrack from './JobTrack';
import { isFinished, tabOf } from './phases';
import { announcement, kindOf, titleOf, useBusyElsewhere } from './status';
import useInlineConfirm from './useInlineConfirm';
import './job.scss';

/** How often the screen reads the job again. */
export const FOLLOW_MS = 5000;

/**
 * Whether the followed job stands still: reading stopped for good (logged
 * out or gone), or it went quiet. Waiting for the other site's admin to
 * confirm is not a stall (controller ruling).
 *
 * @param {Object}  state
 * @param {boolean} state.finished   The job ended.
 * @param {boolean} state.stopped    Reading stopped for good.
 * @param {boolean} state.quiet      No save for a while.
 * @param {boolean} state.confirming The other site's admin is asked to confirm.
 * @return {boolean} The track stands still.
 */
export const followStalled = ( { finished, stopped, quiet, confirming } ) =>
	! finished && ( stopped || ( quiet && ! confirming ) );

/**
 * A job a connected site drives on this site (ruling I5): this screen only
 * reads it (`GET /jobs/<id>` every few seconds) and can cancel it. It never
 * ticks, uploads or answers. Once the job has been quiet for a while, or
 * its cancel was deferred past the swap, it offers to continue here: the
 * server refuses that (409 lw_migration_link_job) as long as the connection
 * may still drive it, and allows it once the connection released the job or
 * stopped driving it for SiteLock::TTL.
 *
 * @param {Object}                 props
 * @param {Object}                 props.job        Job view.
 * @param {(view: Object) => void} props.onChange   Every new job view.
 * @param {(view: Object) => void} props.onTakeOver The server let this site drive it.
 * @param {() => void}             props.onClose    Back to the forms.
 */
export default function JobFollower( {
	job: initial,
	onChange,
	onTakeOver,
	onClose,
} ) {
	const [ job, setJob ] = useState( initial );
	const [ error, setError ] = useState( null );
	const confirm = useInlineConfirm();
	const [ working, setWorking ] = useState( '' );
	// Reading stops for good once the server says logged out or gone.
	const [ stopped, setStopped ] = useState( false );
	const { createNotice } = useDispatch( noticesStore );
	const finished = isFinished( job );
	const quiet = 0 === useBusyElsewhere( job );
	// Waiting for the other site's admin to confirm is not a stall (controller ruling).
	const confirming =
		'needs_input' === job.status && 'confirm' === job.input?.kind;
	const title = titleOf( job );
	const name = job.link?.name || job.link?.address || '';
	const changed = useRef( onChange );
	changed.current = onChange;

	useEffect( () => {
		if ( finished || stopped ) {
			return undefined;
		}
		const controller = new window.AbortController();
		const timer = setInterval( () => {
			api.job( job.id, controller.signal ).then(
				( view ) => {
					setError( null );
					setJob( view );
					changed.current( view );
				},
				( e ) => {
					if ( 'AbortError' === e?.name ) {
						return;
					}
					setError( e );
					if ( isLoggedOut( e ) || 404 === e?.status ) {
						setStopped( true );
					}
				}
			);
		}, FOLLOW_MS );
		return () => {
			clearInterval( timer );
			controller.abort();
		};
	}, [ job.id, finished, stopped ] );

	const show = ( view ) => {
		setJob( view );
		changed.current( view );
	};
	const cancel = async () => {
		confirm.close();
		setWorking( 'cancel' );
		try {
			const result = await api.cancel( job.id );
			if ( result?.job ) {
				show( result.job );
			}
			if ( result?.cancelDeferred ) {
				createNotice(
					'warning',
					__(
						'The database is already replaced, so the restore cannot be cancelled any more. Continue it here to finish it.',
						'lw-migration'
					),
					{ type: 'snackbar' }
				);
			}
		} catch ( e ) {
			if ( isLoggedOut( e ) ) {
				setError( e ); // The shared login notice.
			} else {
				createNotice( 'error', errorMessage( e ), {
					type: 'snackbar',
				} );
			}
		}
		setWorking( '' );
	};
	const takeOver = async () => {
		setWorking( 'continue' );
		setError( null );
		try {
			onTakeOver( await api.tick( job.id ) );
			return;
		} catch ( e ) {
			setError( e );
		}
		setWorking( '' );
	};

	const stalled = followStalled( { finished, stopped, quiet, confirming } );
	const canTakeOver =
		( quiet || job.cancelDeferred ) && ! confirming && ! stopped;

	return (
		<section
			className={ `lw-job is-${ job.status }` }
			aria-labelledby="lw-job-title"
		>
			<div className="lw-job__body">
				<header className="lw-job__head">
					<p className="lw-job__kind">{ kindOf( job ) }</p>
					<h2 id="lw-job-title" className="lw-job__title">
						{ title }
					</h2>
					{ ! finished && (
						<JobClock
							createdAt={ job.createdAt }
							stoppedAt={ stalled ? job.updatedAt || 0 : 0 }
						/>
					) }
				</header>
				<JobTrack
					job={ job }
					message={ finished ? '' : job.message }
					stalled={ stalled }
				/>
				<p className="screen-reader-text" aria-live="polite">
					{ announcement(
						{ ...job, status: finished ? job.status : 'running' },
						null,
						title
					) }
				</p>
				{ error && isLoggedOut( error ) && (
					<LoginNotice tab={ tabOf( job ) } />
				) }
				{ error && ! isLoggedOut( error ) && (
					<Notice status="warning" isDismissible={ false }>
						{ errorMessage( error ) }
					</Notice>
				) }
				{ finished && <JobResult job={ job } onClose={ onClose } /> }
				{ confirm.open && ! finished && (
					<CancelConfirm
						text={ sprintf(
							/* translators: %s: name of the connected site. */
							__(
								'Cancel this job? %s can no longer finish it.',
								'lw-migration'
							),
							name
						) }
						confirm={ confirm }
						onConfirm={ cancel }
					/>
				) }
			</div>
			{ ! finished && (
				<JobFooter
					note={
						<Note icon={ <Icon icon={ link } size={ 20 } /> }>
							{ sprintf(
								/* translators: %s: name of the connected site. */
								__(
									'Driven by %s. This screen only follows it; you can cancel it here.',
									'lw-migration'
								),
								name
							) }
						</Note>
					}
				>
					<CancelButton
						cancelling={ 'cancel' === working }
						disabled={ '' !== working }
						onClick={ confirm.ask }
					/>
					{ canTakeOver && (
						<Button
							__next40pxDefaultSize
							variant="primary"
							isBusy={ 'continue' === working }
							disabled={ '' !== working }
							accessibleWhenDisabled
							onClick={ takeOver }
						>
							{ __( 'Continue here', 'lw-migration' ) }
						</Button>
					) }
				</JobFooter>
			) }
		</section>
	);
}

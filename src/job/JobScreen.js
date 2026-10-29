/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, pause, play } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import LoginNotice from '../components/LoginNotice';
import { errorMessage } from '../data/api';
import { drivenElsewhere } from '../links/links';
import CheckView from '../import/CheckView';
import RestoreLead from '../import/RestoreLead';
import { restoreStep } from '../import/steps';
import CancelConfirm from './CancelConfirm';
import { CANCEL_TEXTS, DEFERRED } from './cancelTexts';
import JobClock from './JobClock';
import JobFollower from './JobFollower';
import JobFooter, { CancelButton, FooterNote } from './JobFooter';
import JobResult from './JobResult';
import JobTrack from './JobTrack';
import PasswordPrompt from './PasswordPrompt';
import UploadPrompt from './UploadPrompt';
import { isFinished, tabOf } from './phases';
import {
	announcement,
	kindOf,
	sourceLine,
	statusLine,
	titleOf,
	useBusyElsewhere,
} from './status';
import useInlineConfirm from './useInlineConfirm';
import useJobDriver from './useJobDriver';
import useUpload from './useUpload';
import './job.scss';

/**
 * The session ended mid-job (ruling P7): a restore that replaced the login
 * keys logs everyone out. After logging in, the page opens on this job's tab
 * and the job goes on (past its point of no return by itself, otherwise
 * with Continue).
 *
 * @param {Object} props
 * @param {Object} props.job Job view.
 */
function LoggedOut( { job } ) {
	return (
		<LoginNotice tab={ tabOf( job ) }>
			{ job.pastNoReturn
				? __(
						"You were logged out — this can happen when a restore replaces the site's login keys. Log in again; the restore goes on by itself.",
						'lw-migration'
					)
				: __(
						'You were logged out. Log in again, then continue the job on this page.',
						'lw-migration'
					) }
		</LoginNotice>
	);
}

/**
 * One job of this site from start to end (decision 5): drives it when this
 * tab started it or when it is past its point of no return, otherwise
 * offers Continue/Cancel.
 *
 * @param {Object}                 props
 * @param {Object}                 props.job      Job view.
 * @param {File|null}              props.file     File to upload (null after a reload).
 * @param {boolean}                props.resumed  Found on page load (not started here).
 * @param {(view: Object) => void} props.onChange Every new job view.
 * @param {() => void}             props.onClose  Back to the forms.
 * @param {() => void}             props.onBack   Back from a restore's check to its source (Restore section).
 */
function DriverScreen( {
	job: initial,
	file,
	resumed,
	onChange,
	onClose,
	onBack,
} ) {
	const driver = useJobDriver( initial, {
		autoStart: ! resumed || initial.pastNoReturn,
		onChange,
	} );
	const upload = useUpload( driver, file );
	const { job } = driver;
	const finished = isFinished( job );
	const waiting = 'needs_input' === job.status;
	const kind = waiting ? job.input?.kind : '';
	// The Restore section draws its step rail; its prompts stand on their own.
	const restoreTab = 'import' === tabOf( job );
	const prompting = 'confirm' === kind || 'password' === kind;
	const stalled =
		! driver.running && ! finished && ! waiting && ! driver.loggedOut;
	// Stopped without a failure: the warning strip, Continue and Cancel.
	const paused = stalled && ! driver.error;
	const elsewhere = useBusyElsewhere( job ) > 0;
	const title = titleOf( job );
	const deferred = DEFERRED[ job.type ] ?? DEFERRED.import;
	// Past the point of no return the confirmation closes, like Cancel turns off.
	const confirm = useInlineConfirm( !! job.pastNoReturn );
	// From "Cancel the job" until the cancel answered (it first waits for the running tick).
	const [ cancelling, setCancelling ] = useState( false );
	const { createNotice } = useDispatch( noticesStore );

	const cancel = async () => {
		confirm.close();
		setCancelling( true );
		try {
			const result = await driver.cancel();
			if ( result.cancelDeferred ) {
				createNotice( 'warning', deferred.said, {
					type: 'snackbar',
				} );
			}
		} catch ( e ) {
			createNotice( 'error', errorMessage( e ), { type: 'snackbar' } );
		} finally {
			setCancelling( false );
		}
	};

	const notices = (
		<>
			{ driver.loggedOut && <LoggedOut job={ job } /> }
			{ driver.error && (
				<Notice
					status="error"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Try again', 'lw-migration' ),
							onClick: driver.resume,
						},
					] }
				>
					{ driver.error }
				</Notice>
			) }
		</>
	);
	const prompt = (
		<>
			{ 'password' === kind && (
				<PasswordPrompt
					reason={ job.input.reason }
					onSubmit={ ( password ) => driver.answer( { password } ) }
					onCancel={ confirm.ask }
					cancelling={ cancelling }
				/>
			) }
			{ 'confirm' === kind && (
				<CheckView
					summary={ job.input.summary }
					target={ 'push' === job.type ? job.link : null }
					back={ restoreTab }
					onConfirm={ ( safetyBackup, sites = {} ) =>
						driver.answer( {
							confirm: true,
							safetyBackup,
							// Network only: the changed site addresses, by index.
							...( Object.keys( sites ).length ? { sites } : {} ),
						} )
					}
					onDecline={ async () => {
						const next = await driver.answer( { confirm: false } );
						// Back to the source once the job is really cancelled.
						if ( restoreTab && next && isFinished( next ) ) {
							onBack?.();
						}
					} }
				/>
			) }
		</>
	);
	const confirmation = confirm.open && ! finished && (
		<CancelConfirm
			text={ CANCEL_TEXTS[ job.type ] ?? CANCEL_TEXTS.import }
			confirm={ confirm }
			onConfirm={ cancel }
		/>
	);
	const live = (
		<p className="screen-reader-text" aria-live="polite">
			{ announcement( job, upload.percent, title ) }
		</p>
	);

	// The password and the check are the Restore section's step 2 itself.
	if ( restoreTab && prompting ) {
		return (
			<div className={ `lw-restore is-${ job.status }` }>
				<RestoreLead step={ restoreStep( job ) } />
				{ live }
				{ notices }
				{ prompt }
				{ confirmation }
			</div>
		);
	}

	const classes = [ 'lw-job', `is-${ job.status }` ];
	if ( paused ) {
		classes.push( 'is-stalled' );
	}
	const moving = ! stalled && ! driver.loggedOut;
	const screen = (
		<section
			className={ classes.join( ' ' ) }
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
							stoppedAt={ moving ? 0 : job.updatedAt || 0 }
						/>
					) }
					{ job.source && (
						<p className="lw-job__source">
							{ sourceLine( job.source ) }
						</p>
					) }
					{ 'push' === job.type && job.link && (
						<p className="lw-job__source">
							{ sprintf(
								/* translators: %s: name of the connected site. */
								__( 'To %s', 'lw-migration' ),
								job.link.name || job.link.address
							) }
						</p>
					) }
				</header>
				{ paused && (
					<div className="lw-job__stalled">
						<Icon icon={ pause } size={ 20 } />
						<p>
							{ elsewhere
								? __(
										'This job was saved moments ago, so it may still be running in another window or tab. Look there first, or continue it here.',
										'lw-migration'
									)
								: __(
										'This job stopped when its page was closed or the connection broke. Continue it, or cancel it.',
										'lw-migration'
									) }
						</p>
					</div>
				) }
				{ /* While a prompt waits, the progress and message are old. */ }
				{ ! prompting && (
					<JobTrack
						job={ job }
						uploaded={ upload.percent }
						stalled={ stalled }
						message={
							finished ? '' : statusLine( job, upload.percent )
						}
					/>
				) }
				{ live }
				{ job.cancelDeferred && ! finished && (
					<Callout>{ deferred.asked }</Callout>
				) }
				{ notices }
				{ prompt }
				{ upload.needsFile && (
					<UploadPrompt
						expected={ job.source }
						error={ upload.error }
						onFile={ upload.chooseFile }
					/>
				) }
				{ finished && <JobResult job={ job } onClose={ onClose } /> }
				{ confirmation }
			</div>
			{ ! finished && ! prompting && ! driver.loggedOut && (
				<JobFooter
					note={
						<FooterNote
							job={ job }
							past={ job.cancelDeferred ? '' : deferred.past }
							stopped={ stalled }
						/>
					}
				>
					{ ! driver.error && (
						<>
							<CancelButton
								cancelling={ cancelling }
								disabled={ !! job.pastNoReturn }
								explained={ !! job.pastNoReturn }
								onClick={ confirm.ask }
							/>
							{ paused && (
								<Button
									__next40pxDefaultSize
									variant="primary"
									icon={ play }
									disabled={ cancelling }
									accessibleWhenDisabled
									onClick={ driver.resume }
								>
									{ __( 'Continue', 'lw-migration' ) }
								</Button>
							) }
						</>
					) }
				</JobFooter>
			) }
		</section>
	);

	return restoreTab ? (
		<div className="lw-restore">
			<RestoreLead step={ restoreStep( job ) } />
			{ screen }
		</div>
	) : (
		screen
	);
}

/**
 * The job screen: a job a connected site drives here is only followed
 * (ruling I5) until the server lets this site drive it; every other job is
 * driven here.
 *
 * @param {Object}                 props
 * @param {Object}                 props.job      Job view.
 * @param {File|null}              props.file     File to upload (null after a reload).
 * @param {boolean}                props.resumed  Found on page load (not started here).
 * @param {(view: Object) => void} props.onChange Every new job view.
 * @param {() => void}             props.onClose  Back to the forms.
 * @param {() => void}             props.onBack   Back from a restore's check to its source.
 */
export default function JobScreen( props ) {
	const [ following, setFollowing ] = useState( () =>
		drivenElsewhere( props.job )
	);
	const [ tookOver, setTookOver ] = useState( false );
	if ( following ) {
		return (
			<JobFollower
				job={ props.job }
				onChange={ props.onChange }
				onClose={ props.onClose }
				onTakeOver={ ( view ) => {
					props.onChange( view );
					setTookOver( true );
					setFollowing( false );
				} }
			/>
		);
	}
	return (
		<DriverScreen { ...props } resumed={ props.resumed && ! tookOver } />
	);
}

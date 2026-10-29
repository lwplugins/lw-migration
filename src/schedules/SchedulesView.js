/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import LoadError from '../components/LoadError';
import LoginNotice from '../components/LoginNotice';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import useRemote from '../data/useRemote';
import { isFinished } from '../job/phases';
import CronNotice from './CronNotice';
import DeleteDialog from './DeleteDialog';
import EmptyStarters from './EmptyStarters';
import ScheduleCard from './ScheduleCard';
import ScheduleEditor from './ScheduleEditor';
import ScheduleLog from './ScheduleLog';
import SchedulesLead from './SchedulesLead';
import SchedulesSkeleton from './SchedulesSkeleton';
import WeekRuler from './WeekRuler';
import { duplicateForm } from './scheduleForm';
import useEditor from './useEditor';
import useReloadWhenDone from './useReloadWhenDone';
import { laneOf } from './week';
import { runWriter } from './when';
import './schedules.scss';

/**
 * @param {string} id Schedule id.
 * @return {Element|null} The card's ⋯ toggle.
 */
const menuToggle = ( id ) =>
	Array.from( document.querySelectorAll( '[data-lw-schedule]' ) ).find(
		( el ) => el.dataset.lwSchedule === id
	) || null;

/**
 * A refused card action; a logout is the shared LoginNotice.
 *
 * @param {Object}      props
 * @param {Object|null} props.error Rejection of request().
 */
function ActionProblem( { error } ) {
	if ( ! error ) {
		return null;
	}
	if ( isLoggedOut( error ) ) {
		return <LoginNotice tab="schedules" />;
	}
	return (
		<Notice status="error" isDismissible={ false }>
			{ errorMessage( error ) }
		</Notice>
	);
}

/**
 * Schedules (spec 11, 17): the lead, the next 7 days of every schedule, the
 * WP-Cron problem when there is one, one card per schedule (or three
 * starters), the editor sheet, the run log, "Run now" (the job screen of the
 * Backup section drives it, like any backup).
 *
 * @param {Object}                props
 * @param {boolean}               props.busy     Another job runs.
 * @param {Object|null}           props.job      The job the page drives (App), or null.
 * @param {(job: Object) => void} props.onStart  Receives a started job.
 * @param {Object}                props.settings The Settings store (its default address).
 */
export default function SchedulesView( { busy, job, onStart, settings } ) {
	const remote = useRemote( api.schedules );
	const cloud = useRemote( api.destinations );
	const newButton = useRef();
	const editor = useEditor( () => newButton.current );
	const [ logOf, setLogOf ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );
	const [ pending, setPending ] = useState( '' );
	const [ failure, setFailure ] = useState( null );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const [ refocus, setRefocus ] = useState( false );
	// A run the page drove ended: the card's state and history are new.
	useReloadWhenDone( job, remote.reload );

	// After a delete the card (and its focused button) is gone: go to "New schedule".
	useEffect( () => {
		if ( refocus && remote.data ) {
			newButton.current?.focus();
			setRefocus( false );
		}
	}, [ refocus, remote.data ] );

	const act = async ( id, work ) => {
		setPending( id );
		setFailure( null );
		try {
			await work();
		} catch ( e ) {
			setFailure( e );
			if ( 'lw_migration_schedule_missing' === e?.code ) {
				remote.reload();
			}
		}
		setPending( '' );
	};
	const toggle = ( item, enabled ) =>
		act( item.id, async () => {
			await api.updateSchedule( item.id, { enabled } );
			await remote.reload();
		} );
	const run = ( item ) =>
		act( item.id, async () => onStart( await api.runSchedule( item.id ) ) );
	const remove = () =>
		act( deleting.id, async () => {
			await api.deleteSchedule( deleting.id );
			setDeleting( null );
			createSuccessNotice(
				__(
					'Schedule deleted. Its backups stay in the Backups section.',
					'lw-migration'
				),
				{ type: 'snackbar' }
			);
			await remote.reload();
			setRefocus( true );
		} );
	const cancelDelete = () => {
		const id = deleting.id;
		setDeleting( null );
		// The dialog gives focus back to the menu item, which is gone by then.
		window.setTimeout( () => menuToggle( id )?.focus(), 0 );
	};

	const lead = (
		<SchedulesLead
			ref={ newButton }
			onNew={
				remote.data
					? ( event ) =>
							editor.open(
								{ item: null, initial: null },
								event.currentTarget
							)
					: null
			}
		/>
	);

	if ( remote.error && ! remote.data ) {
		return (
			<LoadError
				error={ remote.error }
				tab="schedules"
				onRetry={ remote.reload }
			/>
		);
	}
	if ( ! remote.data ) {
		return <SchedulesSkeleton lead={ lead } />;
	}
	const { items, cron, timezone } = remote.data;
	const now = Math.floor( Date.now() / 1000 );
	const write = runWriter( now, timezone );
	const destinations = cloud.data?.items ?? null;
	// One job at a time: a running schedule owns the backup the page drives.
	const running =
		job && 'export' === job.type && ! isFinished( job ) ? job : null;

	return (
		<div className="lw-scheds">
			{ lead }
			{ items.length > 0 && (
				<WeekRuler items={ items } timezone={ timezone } now={ now } />
			) }
			<CronNotice cron={ cron } />
			<ActionProblem error={ failure } />
			{ 0 === items.length && (
				<EmptyStarters
					onStart={ ( initial, opener ) =>
						editor.open( { item: null, initial }, opener )
					}
				/>
			) }
			{ items.map( ( item, index ) => (
				<ScheduleCard
					key={ item.id }
					item={ item }
					lane={ laneOf( index ) }
					destinations={ destinations }
					now={ now }
					write={ write }
					job={ item.running ? running : null }
					busy={ busy }
					pending={ pending === item.id }
					onToggle={ ( enabled ) => toggle( item, enabled ) }
					onEdit={ ( event ) =>
						editor.open( { item }, event.currentTarget )
					}
					onRun={ () => run( item ) }
					onLog={ () => setLogOf( item ) }
					onDuplicate={ () =>
						editor.open(
							{ item: null, initial: duplicateForm( item ) },
							menuToggle( item.id )
						)
					}
					onDelete={ () => setDeleting( item ) }
				/>
			) ) }
			{ editor.editing && (
				<ScheduleEditor
					item={ editor.editing.item }
					initial={ editor.editing.initial }
					timezone={ timezone }
					fallback={ settings?.server?.notifications?.email || '' }
					onClose={ () => editor.close() }
					onSaved={ () => {
						createSuccessNotice(
							__( 'Schedule saved.', 'lw-migration' ),
							{ type: 'snackbar' }
						);
						editor.close( remote.reload() );
					} }
				/>
			) }
			{ logOf && (
				<ScheduleLog
					item={ logOf }
					onClose={ () => setLogOf( null ) }
				/>
			) }
			{ deleting && (
				<DeleteDialog
					item={ deleting }
					pending={ pending === deleting.id }
					onConfirm={ remove }
					onCancel={ cancelDelete }
				/>
			) }
		</div>
	);
}

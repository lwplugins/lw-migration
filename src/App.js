/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoadError from './components/LoadError';
import Notices from './components/Notices';
import RunningNotice from './components/RunningNotice';
import StatusBadge from './components/StatusBadge';
import AppSkeleton from './components/skeleton/AppSkeleton';
import { api } from './data/api';
import { INITIAL_TAB } from './data/boot';
import useDraftStore from './data/useDraftStore';
import JobScreen from './job/JobScreen';
import { isFinished, tabOf } from './job/phases';
import Footer from './shell/Footer';
import SideNav from './shell/SideNav';
import TopBar from './shell/TopBar';
import DestinationsView from './destinations/DestinationsView';
import RestoreView from './import/RestoreView';
import { EMPTY_DRAFT } from './import/sources';
import ConnectionsView from './links/ConnectionsView';
import OverviewView from './overview/OverviewView';
import ResetView from './reset/ResetView';
import SchedulesView from './schedules/SchedulesView';
import { TABS, TAB_IDS } from './shell/tabs';
import useSaveShortcut from './shell/useSaveShortcut';
import useTab from './shell/useTab';
import useUnsavedWarning from './shell/useUnsavedWarning';
import BackupsView from './backups/BackupsView';
import ExportView from './views/ExportView';
import SettingsView from './views/SettingsView';

const VIEWS = {
	overview: OverviewView,
	export: ExportView,
	import: RestoreView,
	backups: BackupsView,
	schedules: SchedulesView,
	destinations: DestinationsView,
	connections: ConnectionsView,
	reset: ResetView,
	settings: SettingsView,
};

/**
 * Shell: sidebar | top bar + section + footer. The job found on load (or
 * started here) owns its section until it is closed; the other sections say
 * that it runs.
 */
export default function App() {
	const tab = useTab( TAB_IDS, INITIAL_TAB );
	const current = TABS.find( ( item ) => item.id === tab );
	const settings = useDraftStore( api.settings, api.saveSettings, [
		'retention',
		'notifications',
	] );
	const [ active, setActive ] = useState( {
		job: undefined,
		file: null,
		resumed: false,
	} );
	const [ loadError, setLoadError ] = useState( null );
	// The Restore section's source step: kept while its check runs, so Back
	// returns to the same choice (an uploaded file, a typed address).
	const [ draft, setDraft ] = useState( EMPTY_DRAFT );

	const loadActive = useCallback( () => {
		setLoadError( null );
		api.activeJob().then(
			( response ) =>
				setActive( {
					job: response.job,
					file: null,
					resumed: Boolean( response.job ),
				} ),
			( e ) => {
				setLoadError( e );
				setActive( ( prev ) => ( { ...prev, job: null } ) );
			}
		);
	}, [] );

	useEffect( () => {
		loadActive();
	}, [ loadActive ] );

	const job = active.job;
	const hasJob = Boolean( job );

	// A source picked for restoring is forgotten once the user goes elsewhere
	// (not while its restore job waits at the check).
	useEffect( () => {
		if ( 'import' !== tab && ! hasJob ) {
			setDraft( EMPTY_DRAFT );
		}
	}, [ tab, hasJob ] );

	const working = Boolean( job ) && ! isFinished( job );
	const store = current.store ? settings : null;

	useUnsavedWarning( settings.hasEdits || ( working && ! active.resumed ) );
	useSaveShortcut(
		() => store?.save(),
		Boolean( store?.hasEdits && ! store.isSaving )
	);

	if ( undefined === job ) {
		return <AppSkeleton tab={ tab } />;
	}

	const start = ( next, file = null ) => {
		setActive( { job: next, file, resumed: false } );
		window.location.hash = tabOf( next );
	};
	// Back from the restore's check: the declined job is closed, the source stays.
	const back = () => setActive( { job: null, file: null, resumed: false } );
	const close = () => {
		// A finished restore forgets its source; a failed one keeps it to try again.
		if ( 'done' === job?.status ) {
			setDraft( EMPTY_DRAFT );
		}
		back();
	};
	const restore = ( backup ) => {
		setDraft( ( prev ) => ( {
			...prev,
			source: 'backup',
			wanted: { folder: backup.folder, name: backup.name },
		} ) );
		window.location.hash = 'import';
	};
	const showJob = Boolean( job ) && tab === tabOf( job );
	const View = VIEWS[ tab ] || null;

	return (
		<>
			<div className="lw-admin-shell">
				<SideNav
					tabs={ TABS }
					current={ tab }
					meta={
						working
							? {
									[ tabOf( job ) ]: (
										<StatusBadge status="info">
											{ __( 'running', 'lw-migration' ) }
										</StatusBadge>
									),
								}
							: {}
					}
				/>
				<div className="lw-admin-main">
					<TopBar title={ current.title } store={ store } />
					<main
						className="lw-admin-scroll"
						aria-label={ __( 'LW Migration', 'lw-migration' ) }
					>
						<div className="lw-admin-content">
							{ loadError && (
								<LoadError
									error={ loadError }
									tab={ tab }
									onRetry={ loadActive }
								/>
							) }
							{ working && ! showJob && 'overview' !== tab && (
								// The Overview's status block says it itself.
								<RunningNotice job={ job } />
							) }
							{ job && (
								// Stays mounted (hidden) on the other sections, so the
								// driver keeps going and its state is never lost.
								<div hidden={ ! showJob }>
									<JobScreen
										key={ job.id }
										job={ job }
										file={ active.file }
										resumed={ active.resumed }
										onChange={ ( next ) =>
											setActive( ( prev ) => ( {
												...prev,
												job: next,
											} ) )
										}
										onClose={ close }
										onBack={ back }
									/>
								</div>
							) }
							{ ! showJob && View && (
								<View
									settings={ settings }
									busy={ working }
									job={ job }
									onStart={ start }
									onRestore={ restore }
									draft={ draft }
									onDraft={ ( patch ) =>
										setDraft( ( prev ) => ( {
											...prev,
											...patch,
										} ) )
									}
								/>
							) }
						</div>
					</main>
					<Footer />
				</div>
			</div>
			<Notices />
		</>
	);
}

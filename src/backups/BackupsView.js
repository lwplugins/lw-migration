/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import LoadError from '../components/LoadError';
import { api, downloadBackup } from '../data/api';
import useCopiesCheck from '../data/useCopiesCheck';
import useRemote from '../data/useRemote';
import ProblemNotice, { describeProblem } from '../views/ProblemNotice';
import BackupDialogs from './BackupDialogs';
import BackupList from './BackupList';
import BackupsLead from './BackupsLead';
import BackupsSkeleton from './BackupsSkeleton';
import DetailPanel from './DetailPanel';
import FilterBar from './FilterBar';
import { chips as chipsOf, toItems, visibleItems } from './listing';
import StorageStrip from './StorageStrip';
import useBackupActions from './useBackupActions';
import './backups.scss';

/**
 * Moves focus once the closing dialog has given it back (its own focus return
 * points at a menu item that no longer exists).
 *
 * @param {() => (Element|null)} find Returns the element to focus.
 */
const focusSoon = ( find ) => window.setTimeout( () => find()?.focus(), 0 );

/**
 * @param {string} id Backup id.
 * @return {Element|null} The row's ⋯ toggle.
 */
const rowMenuToggle = ( id ) =>
	Array.from( document.querySelectorAll( '.lw-bk [data-lw-backup]' ) ).find(
		( el ) => el.dataset.lwBackup === id
	) || null;

/**
 * Every backup this site can restore (ours and the other .wpress tool's
 * folder): the storage strip, chips and search, the day-grouped list, and
 * the chosen backup's panel. Every action keeps its request: Restore hands
 * the backup to the Restore section, Download asks for a token link,
 * Upload…, Transfer link, Contents, Label and Delete open their dialogs.
 *
 * @param {Object}                   props
 * @param {boolean}                  props.busy      A job runs.
 * @param {(backup: Object) => void} props.onRestore Receives the backup.
 * @param {(job: Object) => void}    props.onStart   Receives a started upload job.
 * @param {(patch: Object) => void}  props.onDraft   Patches the Restore section's source.
 */
export default function BackupsView( { busy, onRestore, onStart, onDraft } ) {
	const list = useRemote( api.backups );
	const cloud = useRemote( api.destinations );
	const shares = useRemote( api.shares );
	useCopiesCheck( list, cloud );
	const { reload } = list;
	const reloadShares = shares.reload;
	const destinations = useMemo(
		() => cloud.data?.items || [],
		[ cloud.data ]
	);
	const items = useMemo( () => toItems( list.data?.items ), [ list.data ] );
	const [ filter, setFilter ] = useState( 'all' );
	const [ query, setQuery ] = useState( '' );
	const [ chosenId, setChosenId ] = useState( null );
	const [ open, setOpen ] = useState( null );
	const [ downloading, setDownloading ] = useState( null );
	const [ problem, setProblem ] = useState( null );
	const { createSuccessNotice } = useDispatch( noticesStore );

	const chips = useMemo( () => chipsOf( items ), [ items ] );
	// A chip whose last backup is gone falls back to All (and stays there).
	const shown = chips.some( ( chip ) => chip.id === filter ) ? filter : 'all';
	useEffect( () => {
		if ( shown !== filter ) {
			setFilter( 'all' );
		}
	}, [ shown, filter ] );
	const visible = useMemo(
		() => visibleItems( items, shown, query ),
		[ items, shown, query ]
	);
	// Until one is clicked, the newest backup: a chip or a search never
	// swaps the panel (and never asks for another backup's contents).
	const chosen =
		items.find( ( item ) => item.id === chosenId ) || items[ 0 ] || null;

	// A job that ends may have added (or used up) a backup.
	const wasBusy = useRef( busy );
	useEffect( () => {
		if ( wasBusy.current && ! busy ) {
			reload();
		}
		wasBusy.current = busy;
	}, [ busy, reload ] );

	const refresh = useCallback( () => {
		reload();
		reloadShares();
	}, [ reload, reloadShares ] );

	const download = useCallback(
		async ( backup ) => {
			setDownloading( backup.id );
			setProblem( null );
			try {
				await downloadBackup( backup );
			} catch ( e ) {
				const next = describeProblem( e );
				setProblem( next );
				if ( next.gone ) {
					reload();
				}
			}
			setDownloading( null );
		},
		[ reload ]
	);

	const actions = useBackupActions( {
		onRestore,
		download,
		setOpen,
		setProblem,
	} );

	// Dialogs opened from a row menu give focus back to its ⋯ toggle.
	const close = () => {
		const from = open;
		setOpen( null );
		if ( 'menu' === from?.from ) {
			focusSoon( () => rowMenuToggle( from.backup.id ) );
		}
	};
	const changed = () => {
		setProblem( null );
		close();
		refresh();
	};
	const deleted = () => {
		createSuccessNotice(
			sprintf(
				/* translators: %s: backup file name. */
				__( 'Deleted: %s', 'lw-migration' ),
				open.backup.name
			),
			{ type: 'snackbar' }
		);
		setProblem( null );
		setOpen( null );
		refresh();
		// The row is gone: focus goes to the search field above the list.
		focusSoon( () => document.querySelector( '.lw-bk-search input' ) );
	};
	const uploadFile = () => {
		onDraft?.( { source: 'upload' } );
		window.location.hash = 'import';
	};

	if ( ! list.data && list.isLoading ) {
		return <BackupsSkeleton />;
	}

	return (
		<div className="lw-bk">
			<BackupsLead onUploadFile={ uploadFile } />
			{ list.error && (
				<LoadError
					error={ list.error }
					tab="backups"
					onRetry={ reload }
				/>
			) }
			<ProblemNotice problem={ problem } />
			{ list.data && (
				<>
					<StorageStrip
						storage={ list.data.storage }
						count={ items.length }
					/>
					<FilterBar
						chips={ chips }
						filter={ shown }
						onFilter={ setFilter }
						query={ query }
						onQuery={ setQuery }
					/>
					<div className="lw-bk-split">
						<BackupList
							items={ visible }
							narrowed={ items.length > 0 }
							chosenId={ chosen?.id || null }
							onChoose={ setChosenId }
							destinations={ destinations }
							actions={ actions }
							busy={ busy }
							downloading={ downloading }
						/>
						{ chosen && (
							<DetailPanel
								key={ chosen.id }
								item={ chosen }
								destinations={ destinations }
								shares={ shares }
								actions={ actions }
								busy={ busy }
								downloading={ downloading }
								onChanged={ refresh }
								onGone={ refresh }
							/>
						) }
					</div>
				</>
			) }
			<BackupDialogs
				open={ open }
				destinations={ destinations }
				onClose={ close }
				onChanged={ changed }
				onDeleted={ deleted }
				onGone={ refresh }
				onStart={ ( job ) => {
					setOpen( null );
					onStart( job );
				} }
			/>
		</div>
	);
}

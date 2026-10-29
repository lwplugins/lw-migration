/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoadError from '../components/LoadError';
import { api } from '../data/api';
import { NETWORK } from '../data/boot';
import { formatBytes } from '../data/format';
import useRemote from '../data/useRemote';
import AttentionList from './AttentionList';
import DestinationList from './DestinationList';
import HistoryChart from './HistoryChart';
import LinkList from './LinkList';
import NextRun from './NextRun';
import OverviewSkeleton from './OverviewSkeleton';
import QuickActions from './QuickActions';
import StatusBlock from './StatusBlock';
import './overview.scss';

/**
 * The page under the blocks: the backups folder and its free space.
 *
 * @param {Object} props
 * @param {Object} props.folder GET /overview `folder`.
 */
function FolderNote( { folder } ) {
	const path =
		folder.path || __( 'the default backups folder', 'lw-migration' );
	return (
		<p className="lw-overview__foot">
			{ __(
				'Backups are .wpress files, so other tools that read the format can restore them too.',
				'lw-migration'
			) }{ ' ' }
			{ sprintf(
				/* translators: %s: the backups folder, e.g. wp-content/lw-migration-backups. */
				__( 'Backups folder: %s', 'lw-migration' ),
				path
			) }
			{ null !== folder.free &&
				` · ${ sprintf(
					/* translators: %s: free space, e.g. 41.2 GB. */
					__( '%s free', 'lw-migration' ),
					formatBytes( folder.free )
				) }` }
		</p>
	);
}

/**
 * Whether App's job has just ended (it ran, now nothing runs): the overview
 * read before is out of date then.
 *
 * @param {boolean} wasBusy A job ran at the last render.
 * @param {boolean} busy    A job runs now.
 * @return {boolean} Read the overview again.
 */
export const endedNow = ( wasBusy, busy ) => Boolean( wasBusy ) && ! busy;

/**
 * The plugin's home page (GET /overview, one read): how the site is backed
 * up, what needs a look, the three common actions, 30 days of backups, the
 * destinations, the next scheduled run and the connected sites. Every
 * action leads to the section that does it; "Back up now" starts the same
 * job as the Backup section and hands it to the job screen.
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    A job runs (App's own).
 * @param {Object|null}           props.job     App's job (null: none).
 * @param {(job: Object) => void} props.onStart Receives a started job.
 */
export default function OverviewView( { busy, job, onStart } ) {
	const remote = useRemote( api.overview );
	const wasBusy = useRef( busy );

	// A job that ends while the page is open changed what it shows: read it again.
	useEffect( () => {
		if ( endedNow( wasBusy.current, busy ) ) {
			remote.reload();
		}
		wasBusy.current = busy;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ busy ] );

	if ( remote.error ) {
		return (
			<LoadError
				error={ remote.error }
				tab="overview"
				onRetry={ remote.reload }
			/>
		);
	}
	if ( ! remote.data ) {
		return <OverviewSkeleton />;
	}
	return (
		<OverviewContent
			data={ remote.data }
			busy={ busy }
			job={ job }
			onStart={ onStart }
		/>
	);
}

/**
 * The loaded page (apart from the fetch, so a test renders it from a payload).
 *
 * @param {Object}                props
 * @param {Object}                props.data    GET /overview.
 * @param {boolean}               props.busy    A job runs (App's state decides:
 *                                              the payload's `running` is shown
 *                                              only while App says so).
 * @param {Object|null}           props.job     App's job.
 * @param {(job: Object) => void} props.onStart Receives a started job.
 * @param {number}                props.now     Unix seconds (tests).
 */
export function OverviewContent( {
	data,
	busy,
	job,
	onStart,
	now = Math.floor( Date.now() / 1000 ),
} ) {
	const network = Boolean( NETWORK ) || Boolean( data.site?.network );
	return (
		<div className="lw-overview">
			<StatusBlock
				data={ data }
				job={ busy ? job : null }
				running={ busy ? data.running : null }
				now={ now }
				network={ network }
			/>
			{ data.attention.length > 0 && (
				<AttentionList items={ data.attention } />
			) }
			<QuickActions
				busy={ busy }
				links={ data.links }
				network={ network }
				onStart={ onStart }
			/>
			<HistoryChart history={ data.history } />
			<div className="lw-overview__cols">
				<DestinationList
					items={ data.destinations }
					attention={ data.attention }
				/>
				<div className="lw-overview__stack">
					<NextRun schedule={ data.schedule } />
					{ null !== data.links && (
						<LinkList items={ data.links || [] } />
					) }
				</div>
			</div>
			<FolderNote folder={ data.folder } />
		</div>
	);
}

/**
 * WordPress dependencies
 */
// ConfirmDialog is experimental in core; the shared LW admin pattern uses it.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import { __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import LoadError from '../components/LoadError';
import LoginNotice from '../components/LoginNotice';
import Section from '../components/Section';
import { SkeletonFields, SkeletonRegion } from '../components/skeleton';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import useRemote from '../data/useRemote';
import ConnectionsAbout from './ConnectionsAbout';
import ConnectionsLead from './ConnectionsLead';
import LinkCard from './LinkCard';
import PairingSection from './PairingSection';
import PushPullDialog from './PushPullDialog';
import { incoming } from './links';
import './links.scss';

const LIST_TITLE = () => __( 'Connected sites', 'lw-migration' );
const LIST_TEXT = () =>
	__(
		'Each connection works in one direction: from the site where the code was entered.',
		'lw-migration'
	);

/**
 * What the snackbar says after a removal: an incoming connection is only
 * forgotten here (the other site's requests are refused from now on); an
 * outgoing one also tells the other site, which may fail.
 *
 * @param {Object} link   The removed link.
 * @param {Object} answer `{ removed, notified }`.
 * @return {string} Text.
 */
function removedText( link, answer ) {
	if ( 'in' === link.direction ) {
		return __(
			'Removed. The other site can no longer use it.',
			'lw-migration'
		);
	}
	return answer.notified
		? __( 'Removed. The other site was told.', 'lw-migration' )
		: __(
				'Removed here; the other site could not be told — remove it there too.',
				'lw-migration'
			);
}

/**
 * The question before a removal: an outgoing connection is what lets this
 * site send or copy; an incoming one is what lets the other site do so.
 *
 * @param {Object} link Link view.
 * @return {string} Text.
 */
function removeQuestion( link ) {
	const name = link.name || link.address;
	if ( 'out' === link.direction ) {
		return sprintf(
			/* translators: %s: name of the connected site. */
			__(
				'Remove the connection with %s? This site can no longer send itself there or copy that site; the other site is told.',
				'lw-migration'
			),
			name
		);
	}
	return sprintf(
		/* translators: %s: name of the connected site. */
		__(
			'Remove the connection with %s? The other site can no longer use it.',
			'lw-migration'
		),
		name
	);
}

/**
 * Connections (spec 19): the lead, the connected sites with their actions,
 * the pairing of a new one, and at the bottom what connections are for.
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    Another job runs on this site.
 * @param {(job: Object) => void} props.onStart Receives a started push or pull.
 */
export default function ConnectionsView( { busy, onStart } ) {
	const remote = useRemote( api.links );
	const [ pending, setPending ] = useState( '' );
	const [ asking, setAsking ] = useState( null );
	const [ removing, setRemoving ] = useState( null );
	// A logout is shown once, as the shared login notice (§D one error path).
	const [ loggedOut, setLoggedOut ] = useState( false );
	const { createNotice } = useDispatch( noticesStore );
	const say = ( text, status = 'success' ) =>
		createNotice( status, text, { type: 'snackbar' } );

	const act = async ( id, work ) => {
		setPending( id );
		setLoggedOut( false );
		try {
			await work();
		} catch ( e ) {
			if ( isLoggedOut( e ) ) {
				setLoggedOut( true );
			} else {
				say( errorMessage( e ), 'error' );
			}
			if ( 'lw_migration_no_link' === e?.code ) {
				remote.reload();
			}
		}
		setPending( '' );
	};
	const check = ( link ) =>
		act( link.id, async () => {
			const { peer } = await api.checkLink( link.id );
			say(
				sprintf(
					/* translators: 1: name of the connected site, 2: its WordPress version. */
					__( '%1$s answers (WordPress %2$s).', 'lw-migration' ),
					peer.name || link.name,
					peer.wordpress || '?'
				)
			);
		} );
	const start = ( safetyBackup ) => {
		const { link, kind } = asking;
		setAsking( null );
		act( link.id, async () =>
			onStart(
				'push' === kind
					? await api.pushTo( link.id, safetyBackup )
					: await api.pullFrom( link.id, safetyBackup )
			)
		);
	};
	const remove = () => {
		const link = removing;
		setRemoving( null );
		act( link.id, async () => {
			say( removedText( link, await api.removeLink( link.id ) ) );
			await remote.reload();
		} );
	};

	if ( remote.error && ! remote.data ) {
		return (
			<LoadError
				error={ remote.error }
				tab="connections"
				onRetry={ remote.reload }
			/>
		);
	}
	if ( ! remote.data ) {
		return (
			<SkeletonRegion
				label={ __( 'Loading the connections…', 'lw-migration' ) }
				className="lw-links-page"
			>
				<ConnectionsLead />
				<Section title={ LIST_TITLE() } description={ LIST_TEXT() }>
					<SkeletonFields count={ 3 } />
				</Section>
			</SkeletonRegion>
		);
	}
	if ( false === remote.data.available ) {
		return (
			<div className="lw-links-page">
				<ConnectionsLead />
				<Callout>
					{ __(
						'This server cannot make connections (the sodium functions are missing).',
						'lw-migration'
					) }
				</Callout>
				<ConnectionsAbout />
			</div>
		);
	}
	const { items, code } = remote.data;

	return (
		<div className="lw-links-page">
			<ConnectionsLead />
			{ loggedOut && <LoginNotice tab="connections" /> }
			<Section title={ LIST_TITLE() } description={ LIST_TEXT() }>
				{ items.length ? (
					<ul className="lw-links">
						{ items.map( ( link ) => (
							<LinkCard
								key={ link.id }
								link={ link }
								busy={ busy }
								pending={ pending === link.id }
								onPush={ () =>
									setAsking( { link, kind: 'push' } )
								}
								onPull={ () =>
									setAsking( { link, kind: 'pull' } )
								}
								onCheck={ () => check( link ) }
								onRemove={ () => setRemoving( link ) }
							/>
						) ) }
					</ul>
				) : (
					<p className="lw-links__empty">
						{ __( 'No connections yet.', 'lw-migration' ) }
					</p>
				) }
			</Section>
			<PairingSection
				openCode={ code }
				inCount={ incoming( items ) }
				onChange={ remote.reload }
			/>
			<ConnectionsAbout />
			{ asking && (
				<PushPullDialog
					link={ asking.link }
					kind={ asking.kind }
					onConfirm={ start }
					onCancel={ () => setAsking( null ) }
				/>
			) }
			<ConfirmDialog
				className="lw-modal"
				isOpen={ Boolean( removing ) }
				onConfirm={ remove }
				onCancel={ () => setRemoving( null ) }
				confirmButtonText={ __( 'Remove', 'lw-migration' ) }
				cancelButtonText={ __( 'Cancel', 'lw-migration' ) }
			>
				{ removing && removeQuestion( removing ) }
			</ConfirmDialog>
		</div>
	);
}

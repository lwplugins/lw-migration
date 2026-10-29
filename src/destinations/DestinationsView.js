/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import LoadError from '../components/LoadError';
import { api } from '../data/api';
import useRemote from '../data/useRemote';
import AddShelf, { MAX_DESTINATIONS, focusShelf, tileId } from './AddShelf';
import { afterDelete, headingId } from './card';
import DestinationCard from './DestinationCard';
import DestinationsLead from './DestinationsLead';
import DestinationsSkeleton from './DestinationsSkeleton';
import DraftCard, { DRAFT_PREFIX } from './DraftCard';
import { isOAuth, readOAuthNotice, withoutOAuthParams } from './oauth';
import './destinations.scss';

/**
 * Célhelyek (spec 21.6): the lead, the destinations with their state, then the
 * shelf of places to add one; each card holds its connection test, edit,
 * the backups there and delete, and a picked place opens as a draft card
 * below the shelf (nothing opens a dialog). A saved server or storage gets
 * its connection checked on its new card at once. Deleting keeps the
 * backups already there and moves the focus to the next card (else the one
 * before it, else the shelf); when the record of its uploads could not be
 * cleared, or the access to a connected account could not be withdrawn at
 * the provider, the answer's warnings are shown here. A destination of a
 * type this site does not know (any more) has no Edit. Back from a
 * provider's sign-in, the result of the connect is shown once (the
 * callback's code only, never a text from the address).
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    A job runs (no restore or download from the cloud).
 * @param {(job: Object) => void} props.onStart Receives a started job.
 */
export default function DestinationsView( { busy, onStart } ) {
	const remote = useRemote( api.destinations );
	const [ draft, setDraft ] = useState( null );
	const [ warnings, setWarnings ] = useState( [] );
	const [ checkId, setCheckId ] = useState( null );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const drafts = useRef( 0 );
	const [ refocus, setRefocus ] = useState( null ); // null | true (shelf) | id
	const [ oauthNotice, setOAuthNotice ] = useState( () =>
		readOAuthNotice( window.location.search )
	);

	// The callback's parameters are shown once; a reload must not repeat them.
	useEffect( () => {
		if ( oauthNotice ) {
			window.history.replaceState(
				null,
				'',
				withoutOAuthParams( window.location.href )
			);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// After a delete the card (and its focused button) is gone: go to the
	// next card's heading, else the shelf. A new card gets the focus too
	// (its own first check has started by now).
	useEffect( () => {
		if ( refocus && remote.data ) {
			const heading =
				true === refocus
					? null
					: document.getElementById( headingId( refocus ) );
			if ( heading ) {
				heading.focus();
			} else {
				focusShelf( false );
			}
			setRefocus( null );
			setCheckId( null );
		}
	}, [ refocus, remote.data ] );

	if ( remote.error && ! remote.data ) {
		return (
			<LoadError
				error={ remote.error }
				tab="destinations"
				onRetry={ remote.reload }
			/>
		);
	}
	if ( ! remote.data ) {
		return <DestinationsSkeleton />;
	}
	const { items, types } = remote.data;
	const unavailable = types.filter( ( t ) => ! t.available );
	const typeOf = ( key ) => types.find( ( t ) => t.type === key ) ?? null;
	const empty = 0 === items.length;
	const full = items.length >= MAX_DESTINATIONS;
	const draftType = draft ? typeOf( draft.typeKey ) : null;

	const isPressed = ( tile ) =>
		Boolean( draft ) &&
		draft.typeKey === tile.typeKey &&
		( undefined === tile.preset || tile.preset === draft.preset );
	const pick = ( tile ) => {
		if ( isPressed( tile ) ) {
			document.getElementById( `${ DRAFT_PREFIX }-name` )?.focus();
			return;
		}
		// An account saved there whose connect failed is in the list now.
		if ( draftType && isOAuth( draftType ) ) {
			remote.reload();
		}
		drafts.current += 1;
		setDraft( {
			key: drafts.current,
			typeKey: tile.typeKey,
			preset: tile.preset ?? '',
			name: tile.label,
		} );
	};
	// The draft is gone with its buttons: back to the tile that opened it.
	const discard = () => {
		document
			.getElementById(
				tileId( {
					typeKey: draft.typeKey,
					preset: 's3' === draft.typeKey ? draft.preset : '',
				} )
			)
			?.focus();
		setDraft( null );
	};
	const created = async ( saved, { connected } ) => {
		setDraft( null );
		await remote.reload();
		if ( connected && ! isOAuth( typeOf( saved.type ) ) ) {
			setCheckId( saved.id );
		}
		setRefocus( saved.id );
	};
	const remove = async ( item ) => {
		let answer = null;
		try {
			answer = await api.deleteDestination( item.id );
		} catch ( e ) {
			// Already gone: nothing left to delete.
			if ( 'lw_migration_destination_missing' !== e?.code ) {
				throw e;
			}
		}
		const next = afterDelete( items, item.id );
		setWarnings( answer?.warnings || [] );
		await remote.reload();
		setRefocus( next ?? true );
	};

	return (
		<>
			<DestinationsLead />
			{ oauthNotice && (
				<Notice
					status={ oauthNotice.status }
					onRemove={ () => setOAuthNotice( null ) }
					className="lw-destinations__notice"
				>
					{ oauthNotice.text }
				</Notice>
			) }
			{ unavailable.map( ( t ) => (
				<Notice
					key={ t.type }
					status="warning"
					isDismissible={ false }
					className="lw-destinations__notice"
				>
					{ sprintf(
						/* translators: 1: destination type, e.g. S3-compatible storage; 2: why it cannot be used. */
						__( '%1$s cannot be used here: %2$s', 'lw-migration' ),
						t.label,
						t.reason
					) }
				</Notice>
			) ) }
			{ warnings.map( ( text ) => (
				<Notice
					key={ text }
					status="warning"
					onRemove={ () =>
						setWarnings( warnings.filter( ( w ) => w !== text ) )
					}
					className="lw-destinations__notice"
				>
					{ text }
				</Notice>
			) ) }
			{ ! empty && (
				<ul className="lw-destinations">
					{ items.map( ( item ) => (
						<DestinationCard
							key={ item.id }
							item={ item }
							type={ typeOf( item.type ) }
							busy={ busy }
							checkOnMount={ item.id === checkId }
							onStart={ onStart }
							onDelete={ remove }
							onChanged={ remote.reload }
							onWorks={ ( name ) =>
								createSuccessNotice(
									sprintf(
										/* translators: %s: destination name. */
										__(
											'The connection to %s works.',
											'lw-migration'
										),
										name
									),
									{ type: 'snackbar' }
								)
							}
						/>
					) ) }
				</ul>
			) }
			<AddShelf
				types={ types }
				empty={ empty }
				full={ full }
				isPressed={ isPressed }
				onPick={ pick }
			>
				{ draft && draftType && (
					<DraftCard
						key={ draft.key }
						type={ draftType }
						draft={ draft }
						onPreset={ ( preset ) =>
							setDraft( ( open ) =>
								open && open.key === draft.key
									? { ...open, preset }
									: open
							)
						}
						onSaved={ created }
						onDiscard={ discard }
					/>
				) }
			</AddShelf>
		</>
	);
}

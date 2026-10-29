/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { awaitsConnect } from '../backups/cloudStatus';
import { DestinationLabel, typeText } from '../components/DestinationIcon';
import LoadError from '../components/LoadError';
import { SkeletonBlock, SkeletonRegion } from '../components/skeleton';
import { api } from '../data/api';
import { formatDatetime } from '../data/format';
import useRemote from '../data/useRemote';
import PickList from './PickList';
import { alsoHere } from './backupRows';

const loading = ( label ) => (
	<SkeletonRegion label={ label }>
		<SkeletonBlock height={ 64 } />
	</SkeletonRegion>
);

/**
 * The backups at one destination, newest first, as rows to choose from.
 *
 * @param {Object}                 props
 * @param {Object}                 props.destination Destination view.
 * @param {Object[]}               props.local       Items of `GET /backups` ("also here").
 * @param {string}                 props.value       Chosen file name.
 * @param {(name: string) => void} props.onChange    Receives a file name.
 */
function RemoteFiles( { destination, local, value, onChange } ) {
	const fetcher = useCallback(
		() => api.remoteFiles( destination.id ),
		[ destination.id ]
	);
	const files = useRemote( fetcher );
	if ( files.isLoading ) {
		return loading( __( 'Loading the backups there…', 'lw-migration' ) );
	}
	if ( files.error ) {
		return (
			<LoadError
				error={ files.error }
				tab="import"
				onRetry={ files.reload }
			/>
		);
	}
	const list = files.data?.files || [];
	return (
		<>
			{ files.data?.insecure && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This destination sends backups unencrypted.',
						'lw-migration'
					) }
				</Notice>
			) }
			{ list.length ? (
				<PickList
					label={ __( 'Backups at the destination', 'lw-migration' ) }
					rows={ list.map( ( file ) => ( {
						id: file.name,
						name: file.name,
						meta: `${ formatDatetime( file.mtime ) } · ${ typeText(
							destination
						) }`,
						tags: alsoHere( file.name, local )
							? [
									{
										key: 'here',
										kind: 'here',
										text: __( 'Also here', 'lw-migration' ),
									},
								]
							: [],
						size: file.size,
					} ) ) }
					value={ value }
					onChange={ onChange }
				/>
			) : (
				<p className="lw-restore__empty">
					{ __( 'No backup there yet.', 'lw-migration' ) }
				</p>
			) }
		</>
	);
}

/**
 * "From the cloud": the destinations as picks (one waiting for its account
 * is offered disabled, with the reason), then the backups at the chosen one.
 *
 * @param {Object}                                                 props
 * @param {Object}                                                 props.remote   useRemote( api.destinations ).
 * @param {Object|null}                                            props.current  The destination read (pickDestination()).
 * @param {string}                                                 props.name     Chosen file name.
 * @param {Object[]}                                               props.local    Items of `GET /backups`.
 * @param {(choice: {cloudId: string, cloudName: string}) => void} props.onChange Receives the choice.
 */
export default function CloudPanel( {
	remote,
	current,
	name,
	local,
	onChange,
} ) {
	const items = remote.data?.items || [];
	if ( remote.isLoading && ! remote.data ) {
		return loading( __( 'Loading the destinations…', 'lw-migration' ) );
	}
	if ( remote.error && ! remote.data ) {
		return (
			<LoadError
				error={ remote.error }
				tab="import"
				onRetry={ remote.reload }
			/>
		);
	}
	if ( ! items.length ) {
		return (
			<p className="lw-restore__empty">
				{ __(
					'No destination yet. Add one in the Destinations section to restore from the cloud.',
					'lw-migration'
				) }
			</p>
		);
	}
	const waiting = items.some( awaitsConnect );
	return (
		<>
			<div
				className="lw-cloud-picks"
				role="group"
				aria-label={ __( 'Destination', 'lw-migration' ) }
			>
				{ items.map( ( item ) => (
					<button
						key={ item.id }
						type="button"
						className="lw-cloud-pick"
						aria-pressed={ current?.id === item.id }
						disabled={ awaitsConnect( item ) }
						aria-describedby={
							awaitsConnect( item )
								? 'lw-cloud-picks-hint'
								: undefined
						}
						onClick={ () =>
							onChange( { cloudId: item.id, cloudName: '' } )
						}
					>
						<DestinationLabel item={ item } />
					</button>
				) ) }
			</div>
			{ waiting && (
				<p id="lw-cloud-picks-hint" className="lw-restore__hint">
					{ __(
						'A destination without its account is off: connect it first in Destinations.',
						'lw-migration'
					) }
				</p>
			) }
			{ current && (
				<RemoteFiles
					key={ current.id }
					destination={ current }
					local={ local }
					value={ name }
					onChange={ ( file ) =>
						onChange( { cloudId: current.id, cloudName: file } )
					}
				/>
			) }
		</>
	);
}

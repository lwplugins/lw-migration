/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoadError from '../components/LoadError';
import { SkeletonBlock, SkeletonRegion } from '../components/skeleton';
import { formatDatetime } from '../data/format';
import PickList from './PickList';
import { backupKind, backupTags, kindText } from './backupRows';

/**
 * The line under a backup's name: when, how it was made, its label, and the
 * other site it came from.
 *
 * @param {Object} item Item of `GET /backups`.
 * @return {string} Text.
 */
function metaLine( item ) {
	return [
		formatDatetime( item.mtime ),
		kindText( backupKind( item ) ),
		item.label
			? sprintf(
					/* translators: %s: the backup's label, in quotes. */
					__( '“%s”', 'lw-migration' ),
					item.label
				)
			: '',
		item.fromSite
			? sprintf(
					/* translators: %s: address of the site the backup was made on. */
					__( 'from %s', 'lw-migration' ),
					item.fromSite
				)
			: '',
	]
		.filter( Boolean )
		.join( ' · ' );
}

/**
 * "From a backup": the backups of this site (and of the older .wpress
 * folder), newest first. A skeleton while the list loads, the load error
 * with a retry, a line when there is none.
 *
 * @param {Object}                 props
 * @param {Object}                 props.list         useRemote( api.backups ).
 * @param {Object[]}               props.destinations Destination views (for the cloud tags).
 * @param {Object|null}            props.chosen       Chosen item.
 * @param {(item: Object) => void} props.onChoose     Receives the item.
 * @param {string}                 props.error        Refusal of the start ('' = none).
 */
export default function BackupPanel( {
	list,
	destinations,
	chosen,
	onChoose,
	error,
} ) {
	const items = list.data?.items || [];
	if ( list.isLoading && ! list.data ) {
		return (
			<SkeletonRegion label={ __( 'Loading backups…', 'lw-migration' ) }>
				<SkeletonBlock height={ 64 } />
				<SkeletonBlock height={ 64 } />
			</SkeletonRegion>
		);
	}
	if ( list.error && ! list.data ) {
		return (
			<LoadError
				error={ list.error }
				tab="import"
				onRetry={ list.reload }
			/>
		);
	}
	if ( ! items.length ) {
		// A refusal (the chosen backup is gone) stays readable after the reload.
		return (
			<>
				{ error && (
					<p className="lw-restore__error" role="alert">
						{ error }
					</p>
				) }
				<p className="lw-restore__empty">
					{ __(
						'There are no backups on this site yet.',
						'lw-migration'
					) }
				</p>
			</>
		);
	}
	return (
		<PickList
			label={ __( 'Backups', 'lw-migration' ) }
			rows={ items.map( ( item ) => ( {
				id: item.id,
				name: item.name,
				meta: metaLine( item ),
				tags: backupTags( item, destinations ),
				size: item.size,
			} ) ) }
			value={ chosen?.id || '' }
			onChange={ ( id ) =>
				onChoose( items.find( ( item ) => item.id === id ) || null )
			}
			error={ error }
		/>
	);
}

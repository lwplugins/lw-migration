/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { humanTimeDiff } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import { cloudUpload, download, trash, upload } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatBytes } from '../data/format';
import DetailContents from './DetailContents';
import DetailCopies from './DetailCopies';
import DetailFacts from './DetailFacts';
import DetailLinks from './DetailLinks';
import { kindText } from './listing';
import useContents from './useContents';
import './detail.scss';

/**
 * The chosen backup: its actions, details, where it lives, its transfer
 * links, what it holds (read for this backup only), and Delete.
 *
 * @param {Object}      props
 * @param {Object}      props.item         Backup row.
 * @param {Object[]}    props.destinations Destination views.
 * @param {Object}      props.shares       useRemote() of api.shares.
 * @param {Object}      props.actions      The view's backup actions.
 * @param {boolean}     props.busy         A job runs (no restore, no upload).
 * @param {string|null} props.downloading  Id of the backup whose download is being prepared.
 * @param {() => void}  props.onChanged    Something changed (read the lists again).
 * @param {() => void}  props.onGone       The backup is not there any more.
 */
export default function DetailPanel( {
	item,
	destinations,
	shares,
	actions,
	busy,
	downloading,
	onChanged,
	onGone,
} ) {
	const contents = useContents( item, onGone );
	const preparing = downloading === item.id;
	const open = ( what ) => actions.open( what, item, 'panel' );

	return (
		<aside
			className="lw-bk-detail"
			aria-label={ __( 'The chosen backup', 'lw-migration' ) }
		>
			<header className="lw-bk-detail__top">
				<h3 className="lw-bk-detail__name">{ item.name }</h3>
				<p className="lw-bk-detail__meta">
					{ [
						kindText( item.kind ),
						humanTimeDiff( item.mtime * 1000 ),
						formatBytes( item.size ),
						true === item.encrypted &&
							__( 'encrypted', 'lw-migration' ),
					]
						.filter( Boolean )
						.join( ' · ' ) }
				</p>
				<div className="lw-bk-detail__actions">
					<Button
						__next40pxDefaultSize
						variant="primary"
						icon={ upload }
						disabled={ busy }
						accessibleWhenDisabled
						onClick={ () => actions.restore( item ) }
					>
						{ __( 'Restore', 'lw-migration' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						icon={ download }
						isBusy={ preparing }
						disabled={ preparing }
						accessibleWhenDisabled
						onClick={ () => actions.download( item ) }
					>
						{ __( 'Download', 'lw-migration' ) }
					</Button>
					{ destinations.length > 0 && (
						<Button
							__next40pxDefaultSize
							variant="secondary"
							icon={ cloudUpload }
							disabled={ busy }
							accessibleWhenDisabled
							onClick={ () => open( 'upload' ) }
						>
							{ __( 'Upload…', 'lw-migration' ) }
						</Button>
					) }
				</div>
			</header>
			<DetailFacts item={ item } onLabel={ () => open( 'label' ) } />
			<DetailCopies item={ item } destinations={ destinations } />
			<DetailLinks
				backup={ item }
				shares={ shares }
				onCreate={ () => open( 'share' ) }
				onChanged={ onChanged }
			/>
			<DetailContents
				answer={ contents.answer }
				error={ contents.error }
				onBrowse={ () => open( 'browse' ) }
				onRetry={ contents.retry }
			/>
			<section className="lw-bk-detail__section lw-bk-detail__delete">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					isDestructive
					icon={ trash }
					onClick={ () => open( 'delete' ) }
				>
					{ __( 'Delete', 'lw-migration' ) }
				</Button>
				<span className="lw-bk-detail__note">
					{ __(
						'Its copies in the cloud are not deleted.',
						'lw-migration'
					) }
				</span>
			</section>
		</aside>
	);
}

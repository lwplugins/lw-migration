/**
 * WordPress dependencies
 */
// ConfirmDialog is experimental in core; the shared LW admin pattern uses it.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Button,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { humanTimeDiff } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SkeletonRegion, SkeletonText } from '../components/skeleton';
import { formatDatetime, formatDayTime } from '../data/format';
import { usesLabel } from './shareLink';

/**
 * The live transfer links of one backup, each with its expiry, its uses and
 * Revoke (the share dialog and the detail panel).
 *
 * @param {Object}                 props
 * @param {Array}                  props.links    Live links (liveLinksFor()).
 * @param {boolean}                props.loading  The list is loading.
 * @param {(item: Object) => void} props.onRevoke Asks to revoke a link.
 * @param {boolean}                props.compact  A text button (the detail panel).
 */
export default function LiveLinkList( {
	links,
	loading,
	onRevoke,
	compact = false,
} ) {
	if ( loading ) {
		return (
			<SkeletonRegion
				label={ __( 'Loading the links…', 'lw-migration' ) }
			>
				<SkeletonText lines={ 2 } />
			</SkeletonRegion>
		);
	}
	if ( ! links.length ) {
		return (
			<p className="lw-modal__text lw-share-links__meta">
				{ __( 'This backup has no live link.', 'lw-migration' ) }
			</p>
		);
	}
	return (
		<ul className={ `lw-share-links ${ compact ? 'is-compact' : '' }` }>
			{ links.map( ( item ) => (
				<li key={ item.id }>
					<span className="lw-share-links__text">
						<span>
							{ sprintf(
								/* translators: 1: date and time, 2: how far away it is, e.g. "in 23 hours". */
								__( 'Expires: %1$s (%2$s)', 'lw-migration' ),
								formatDayTime( item.expiresAt ),
								humanTimeDiff( item.expiresAt * 1000 )
							) }
						</span>
						<span className="lw-share-links__meta">
							{ usesLabel( item, formatDatetime ) }
						</span>
						{ item.revoked && (
							<span className="lw-share-links__meta lw-share-links__dead">
								{ __(
									'No longer works: the file was replaced. Revoke it to remove it.',
									'lw-migration'
								) }
							</span>
						) }
					</span>
					<Button
						__next40pxDefaultSize={ ! compact }
						variant={ compact ? 'link' : 'secondary' }
						isDestructive
						onClick={ () => onRevoke( item ) }
					>
						{ __( 'Revoke', 'lw-migration' ) }
					</Button>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Asks before a link is revoked.
 *
 * @param {Object}     props
 * @param {boolean}    props.isOpen    Shown.
 * @param {() => void} props.onConfirm Revoke.
 * @param {() => void} props.onCancel  Keep it.
 */
export function RevokeDialog( { isOpen, onConfirm, onCancel } ) {
	return (
		<ConfirmDialog
			className="lw-modal"
			isOpen={ isOpen }
			onConfirm={ onConfirm }
			onCancel={ onCancel }
			confirmButtonText={ __( 'Revoke', 'lw-migration' ) }
			cancelButtonText={ __( 'Cancel', 'lw-migration' ) }
		>
			{ __(
				'Revoke this link? Every later request with it is refused: a restore that is downloading from it now stops at its next part.',
				'lw-migration'
			) }
		</ConfirmDialog>
	);
}

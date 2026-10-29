/**
 * WordPress dependencies
 */
import { Button, Modal, TextControl } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { copy, link } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import Segmented from '../components/Segmented';
import { api } from '../data/api';
import useRemote from '../data/useRemote';
import ProblemNotice, { describeProblem } from '../views/ProblemNotice';
import LiveLinkList, { RevokeDialog } from './LiveLinkList';
import { DEFAULT_EXPIRY, expiryOptions, liveLinksFor } from './shareLink';

const now = () => Math.floor( Date.now() / 1000 );

/**
 * Creates a transfer link for one backup (shown once, with Copy), and lists
 * and revokes the backup's live links. Another site restores from the link
 * without a login here.
 *
 * @param {Object}     props
 * @param {Object}     props.backup  Backup row ({ folder, name }).
 * @param {() => void} props.onClose Close.
 * @param {() => void} props.onGone  The backup is not there any more.
 */
export default function ShareModal( { backup, onClose, onGone } ) {
	const list = useRemote( api.shares );
	const [ expiry, setExpiry ] = useState( String( DEFAULT_EXPIRY ) );
	const [ creating, setCreating ] = useState( false );
	const [ created, setCreated ] = useState( null );
	const [ problem, setProblem ] = useState( null );
	const [ revoking, setRevoking ] = useState( null );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const copyRef = useCopyToClipboard(
		() => created?.url || '',
		() =>
			createSuccessNotice( __( 'Copied', 'lw-migration' ), {
				type: 'snackbar',
			} )
	);
	const links = liveLinksFor( list.data?.items, backup, now() );

	const fail = ( e ) => {
		const next = describeProblem( e );
		setProblem( next );
		if ( next.gone ) {
			onGone();
		}
	};

	const create = async () => {
		setCreating( true );
		setProblem( null );
		try {
			setCreated( await api.createShare( backup, Number( expiry ) ) );
			list.reload();
		} catch ( e ) {
			fail( e );
		}
		setCreating( false );
	};

	const revoke = async () => {
		const item = revoking;
		setRevoking( null );
		setProblem( null );
		try {
			await api.revokeShare( item.id );
		} catch ( e ) {
			fail( e );
		}
		list.reload();
	};

	return (
		<Modal
			title={ __( 'Transfer link', 'lw-migration' ) }
			onRequestClose={ onClose }
			className="lw-modal lw-share-modal"
			size="medium"
		>
			<div className="lw-modal-form">
				<p className="lw-modal__text">
					{ sprintf(
						/* translators: %s: backup file name. */
						__(
							'Another site can restore %s from this link: in Restore, choose "From an address". Anyone who has the link can download the backup until it expires.',
							'lw-migration'
						),
						backup.name
					) }
				</p>
				{ created ? (
					<>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Transfer link', 'lw-migration' ) }
							help={ __(
								'Copy it now: it is shown only once.',
								'lw-migration'
							) }
							value={ created.url }
							readOnly
							onChange={ () => {} }
							onFocus={ ( event ) => event.target.select() }
						/>
						<div className="lw-actions">
							<Button
								__next40pxDefaultSize
								ref={ copyRef }
								variant="primary"
								icon={ copy }
							>
								{ __( 'Copy', 'lw-migration' ) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ onClose }
							>
								{ __( 'Close', 'lw-migration' ) }
							</Button>
						</div>
					</>
				) : (
					<>
						<div className="lw-share-modal__expiry">
							<p className="lw-modal__text" id="lw-share-expiry">
								{ __( 'The link works for', 'lw-migration' ) }
							</p>
							<Segmented
								label={ __(
									'The link works for',
									'lw-migration'
								) }
								describedBy="lw-share-expiry"
								options={ expiryOptions() }
								value={ expiry }
								onChange={ ( next ) =>
									setExpiry( String( next ) )
								}
							/>
						</div>
						<div className="lw-actions">
							<Button
								__next40pxDefaultSize
								variant="primary"
								icon={ link }
								isBusy={ creating }
								disabled={ creating }
								accessibleWhenDisabled
								onClick={ create }
							>
								{ __( 'Create link', 'lw-migration' ) }
							</Button>
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ onClose }
							>
								{ __( 'Cancel', 'lw-migration' ) }
							</Button>
						</div>
					</>
				) }
				<ProblemNotice problem={ problem } />
				<h3 className="lw-share-modal__heading">
					{ __( 'Live links of this backup', 'lw-migration' ) }
				</h3>
				<ProblemNotice
					problem={
						list.error ? describeProblem( list.error ) : null
					}
				/>
				<LiveLinkList
					links={ links }
					loading={ list.isLoading && ! list.data }
					onRevoke={ setRevoking }
				/>
			</div>
			<RevokeDialog
				isOpen={ null !== revoking }
				onConfirm={ revoke }
				onCancel={ () => setRevoking( null ) }
			/>
		</Modal>
	);
}

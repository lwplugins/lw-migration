/**
 * WordPress dependencies
 */
import { Button, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import ProblemNotice, { describeProblem } from './ProblemNotice';

/**
 * Asks before a backup is deleted for good; a refusal (a restore reads it,
 * the folder is read-only) stays in the dialog.
 *
 * @param {Object}     props
 * @param {Object}     props.backup    Backup row.
 * @param {() => void} props.onClose   Close without deleting.
 * @param {() => void} props.onDeleted After the delete.
 * @param {() => void} props.onGone    The backup is not there any more.
 */
export default function DeleteModal( { backup, onClose, onDeleted, onGone } ) {
	const [ deleting, setDeleting ] = useState( false );
	const [ problem, setProblem ] = useState( null );

	const remove = async () => {
		setDeleting( true );
		setProblem( null );
		try {
			await api.deleteBackup( backup );
			onDeleted();
		} catch ( e ) {
			const next = describeProblem( e );
			setProblem( next );
			setDeleting( false );
			if ( next.gone ) {
				onGone();
			}
		}
	};

	return (
		<Modal
			title={ __( 'Delete this backup?', 'lw-migration' ) }
			onRequestClose={ onClose }
			className="lw-modal"
			size="medium"
		>
			<div className="lw-modal-form">
				<p className="lw-modal__text">
					{ sprintf(
						/* translators: %s: backup file name. */
						__(
							'%s is deleted for good. This cannot be undone.',
							'lw-migration'
						),
						backup.name
					) }
				</p>
				<ProblemNotice problem={ problem } />
				<div className="lw-actions">
					<Button
						__next40pxDefaultSize
						variant="primary"
						isDestructive
						isBusy={ deleting }
						disabled={ deleting }
						accessibleWhenDisabled
						onClick={ remove }
					>
						{ __( 'Delete', 'lw-migration' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'lw-migration' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}

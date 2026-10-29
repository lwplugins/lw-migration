/**
 * WordPress dependencies
 */
import { Button, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * "Delete this schedule?": its runs stop and its log goes; its backups stay.
 *
 * @param {Object}     props
 * @param {Object}     props.item      The schedule.
 * @param {boolean}    props.pending   The delete request is on its way.
 * @param {() => void} props.onConfirm Deletes it.
 * @param {() => void} props.onCancel  Closes the dialog.
 */
export default function DeleteDialog( { item, pending, onConfirm, onCancel } ) {
	return (
		<Modal
			title={ __( 'Delete this schedule?', 'lw-migration' ) }
			onRequestClose={ onCancel }
			className="lw-modal"
			size="medium"
		>
			<p>
				{ sprintf(
					/* translators: %s: schedule name. */
					__(
						'"%s" stops running and its run log is deleted. Its backups stay; delete them in the Backups section when you no longer need them.',
						'lw-migration'
					),
					item.title
				) }
			</p>
			<div className="lw-actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					isBusy={ pending }
					disabled={ pending }
					accessibleWhenDisabled
					onClick={ onConfirm }
				>
					{ __( 'Delete schedule', 'lw-migration' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'lw-migration' ) }
				</Button>
			</div>
		</Modal>
	);
}

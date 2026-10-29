/**
 * WordPress dependencies
 */
import { Button, Modal, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { api } from '../data/api';
import ProblemNotice, { describeProblem } from './ProblemNotice';

/** Backups\Labels::MAX_LENGTH: the server cuts a longer label. */
const MAX_LENGTH = 200;

/**
 * Our hint for a refused label request, by the field the server names.
 *
 * @param {string} field `data.field`.
 * @return {string} Hint.
 */
const hintFor = ( field ) =>
	'name' === field
		? __( 'This backup cannot carry a label.', 'lw-migration' )
		: sprintf(
				/* translators: %d: maximum number of characters. */
				__(
					'Write the label as plain text, up to %d characters.',
					'lw-migration'
				),
				MAX_LENGTH
			);

/**
 * Sets (or removes) the label of one backup. The server stores it as plain
 * text, without control characters, cut to MAX_LENGTH.
 *
 * @param {Object}                 props
 * @param {Object}                 props.backup  Backup row.
 * @param {() => void}             props.onClose Close without saving.
 * @param {(item: Object) => void} props.onSaved Receives the saved item.
 * @param {() => void}             props.onGone  The backup is not there any more.
 */
export default function LabelModal( { backup, onClose, onSaved, onGone } ) {
	const [ value, setValue ] = useState( backup.label || '' );
	const [ saving, setSaving ] = useState( false );
	const [ problem, setProblem ] = useState( null );

	const save = async ( event ) => {
		event.preventDefault();
		setSaving( true );
		setProblem( null );
		try {
			onSaved( await api.label( backup, value.trim() ) );
		} catch ( e ) {
			const next = describeProblem(
				e,
				hintFor( e?.data?.field || 'label' )
			);
			setProblem( next );
			setSaving( false );
			if ( next.gone ) {
				onGone();
			}
		}
	};

	return (
		<Modal
			title={ __( 'Label', 'lw-migration' ) }
			onRequestClose={ onClose }
			className="lw-modal"
			size="medium"
		>
			<form className="lw-modal-form" onSubmit={ save }>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					maxLength={ MAX_LENGTH }
					label={ sprintf(
						/* translators: %s: backup file name. */
						__( 'Label of %s', 'lw-migration' ),
						backup.name
					) }
					help={ __(
						'For example: before the theme change. Leave it empty to remove the label.',
						'lw-migration'
					) }
					value={ value }
					onChange={ ( next ) => {
						setValue( next );
						setProblem( null );
					} }
				/>
				<ProblemNotice problem={ problem } />
				<div className="lw-actions">
					<Button
						__next40pxDefaultSize
						type="submit"
						variant="primary"
						isBusy={ saving }
						disabled={ saving }
						accessibleWhenDisabled
					>
						{ __( 'Save', 'lw-migration' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'lw-migration' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

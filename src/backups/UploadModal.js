/**
 * WordPress dependencies
 */
import { Button, CheckboxControl, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { DestinationLabel } from '../components/DestinationIcon';
import { api } from '../data/api';
import ProblemNotice, { describeProblem } from '../views/ProblemNotice';
import { awaitsConnect, choiceText } from './cloudStatus';

/**
 * "Feltöltés…" (spec 21.1/21.6): uploads one backup to the checked
 * destinations as a job; old backups there are not removed.
 *
 * @param {Object}                props
 * @param {Object}                props.backup       Backup row.
 * @param {Object[]}              props.destinations Destination views.
 * @param {() => void}            props.onClose      Close.
 * @param {(job: Object) => void} props.onStart      Receives the started job.
 */
export default function UploadModal( {
	backup,
	destinations,
	onClose,
	onStart,
} ) {
	const [ ids, setIds ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ problem, setProblem ] = useState( null );

	const start = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setProblem( null );
		try {
			onStart( await api.uploadBackup( backup, ids ) );
		} catch ( e ) {
			setProblem( describeProblem( e ) );
			setBusy( false );
		}
	};

	return (
		<Modal
			title={ __( 'Upload to the cloud', 'lw-migration' ) }
			onRequestClose={ onClose }
			className="lw-modal"
			size="medium"
		>
			<form className="lw-modal-form" onSubmit={ start }>
				<p className="lw-modal__text">
					{ sprintf(
						/* translators: %s: backup file name. */
						__( 'Upload %s to:', 'lw-migration' ),
						backup.name
					) }
				</p>
				{ destinations.map( ( d ) => (
					<CheckboxControl
						key={ d.id }
						__nextHasNoMarginBottom
						label={ <DestinationLabel item={ d } /> }
						help={ choiceText( d ) }
						disabled={ awaitsConnect( d ) }
						checked={ ids.includes( d.id ) }
						onChange={ ( on ) =>
							setIds(
								on
									? [ ...ids, d.id ]
									: ids.filter( ( id ) => id !== d.id )
							)
						}
					/>
				) ) }
				<p className="lw-modal__text">
					{ __(
						'Older backups there stay: an upload from here never removes any.',
						'lw-migration'
					) }
				</p>
				<ProblemNotice problem={ problem } />
				<div className="lw-actions">
					<Button
						__next40pxDefaultSize
						type="submit"
						variant="primary"
						isBusy={ busy }
						disabled={ busy || 0 === ids.length }
						accessibleWhenDisabled
					>
						{ __( 'Upload', 'lw-migration' ) }
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

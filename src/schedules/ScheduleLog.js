/**
 * WordPress dependencies
 */
import { Button, Modal, Notice } from '@wordpress/components';
import { useCallback, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { cloudLogText } from '../backups/cloudStatus';
import LoginNotice from '../components/LoginNotice';
import StatusBadge from '../components/StatusBadge';
import { SkeletonFields, SkeletonRegion } from '../components/skeleton';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import { formatBytes, formatDatetime } from '../data/format';
import useRemote from '../data/useRemote';
import { triggerLabel } from './history';
import { mailLabel, statusLabel, statusTone } from './timing';

/**
 * The last 30 runs of a schedule, newest first, with "Clear log".
 *
 * @param {Object}     props
 * @param {Object}     props.item    ScheduleView item.
 * @param {() => void} props.onClose Close.
 */
export default function ScheduleLog( { item, onClose } ) {
	const load = useCallback( () => api.scheduleLog( item.id ), [ item.id ] );
	const log = useRemote( load );
	const [ failure, setFailure ] = useState( null );
	const [ clearing, setClearing ] = useState( false );
	const entries = log.data?.entries || [];
	const error = failure || log.error;
	// Column names; on a phone each cell shows its own (data-label).
	const head = {
		ended: __( 'Ended', 'lw-migration' ),
		result: __( 'Result', 'lw-migration' ),
		trigger: __( 'Started by', 'lw-migration' ),
		backup: __( 'Backup', 'lw-migration' ),
		mail: __( 'E-mail', 'lw-migration' ),
	};

	const closeButton = useRef();
	const clear = async () => {
		setClearing( true );
		setFailure( null );
		try {
			await api.clearScheduleLog( item.id );
			await log.reload();
			// "Clear log" is gone with the entries: focus moves to Close.
			closeButton.current?.focus();
		} catch ( e ) {
			setFailure( e );
		}
		setClearing( false );
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: schedule name. */
				__( 'Run log: %s', 'lw-migration' ),
				item.title
			) }
			onRequestClose={ onClose }
			className="lw-modal lw-schedule-log"
			size="large"
		>
			{ error && isLoggedOut( error ) && <LoginNotice tab="schedules" /> }
			{ error && ! isLoggedOut( error ) && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage( error ) }
				</Notice>
			) }
			{ ! log.data && ! log.error && (
				<SkeletonRegion
					label={ __( 'Loading the run log…', 'lw-migration' ) }
				>
					<SkeletonFields count={ 3 } />
				</SkeletonRegion>
			) }
			{ log.data && 0 === entries.length && (
				<p className="lw-log__empty">
					{ __( 'No runs yet.', 'lw-migration' ) }
				</p>
			) }
			{ entries.length > 0 && (
				<table className="lw-log">
					<thead>
						<tr>
							{ Object.values( head ).map( ( label ) => (
								<th key={ label } scope="col">
									{ label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ entries.map( ( entry, index ) => (
							<tr
								key={ `${ entry.job }-${ entry.endedAt }-${ index }` }
							>
								<td data-label={ head.ended }>
									{ formatDatetime( entry.endedAt ) }
								</td>
								<td data-label={ head.result }>
									<span>
										<StatusBadge
											status={ statusTone(
												entry.status
											) }
										>
											{ statusLabel( entry.status ) }
										</StatusBadge>
										{ entry.error && (
											<span className="lw-log__error">
												{ entry.error }
											</span>
										) }
										{ ( entry.cloud || [] )
											.filter(
												( c ) => 'ok' !== c.status
											)
											.map( ( c, i ) => (
												<span
													key={ `${ i }-${ c.name }` }
													className="lw-log__error"
												>
													{ cloudLogText( c ) }
												</span>
											) ) }
									</span>
								</td>
								<td data-label={ head.trigger }>
									{ triggerLabel( entry.trigger ) }
								</td>
								<td data-label={ head.backup }>
									<span className="lw-log__file">
										{ entry.backup
											? `${ entry.backup } (${ formatBytes( entry.size ) })`
											: '—' }
									</span>
								</td>
								<td data-label={ head.mail }>
									{ mailLabel( entry.mail ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			<div className="lw-actions">
				{ entries.length > 0 && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isDestructive
						isBusy={ clearing }
						disabled={ clearing }
						accessibleWhenDisabled
						onClick={ clear }
					>
						{ __( 'Clear log', 'lw-migration' ) }
					</Button>
				) }
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					ref={ closeButton }
					onClick={ onClose }
				>
					{ __( 'Close', 'lw-migration' ) }
				</Button>
			</div>
		</Modal>
	);
}

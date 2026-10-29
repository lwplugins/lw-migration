/**
 * WordPress dependencies
 */
import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import FilePicker from '../components/FilePicker';
import LoginNotice from '../components/LoginNotice';
import Section from '../components/Section';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import { refusalText } from '../data/refusal';
import useCopiesCheck from '../data/useCopiesCheck';
import useRemote from '../data/useRemote';
import {
	buildImportRequest,
	findBackup,
	startProblem,
} from '../views/importRequest';
import BackupPanel from './BackupPanel';
import CloudPanel from './CloudPanel';
import RestoreLead from './RestoreLead';
import SourceTiles from './SourceTiles';
import { pickDestination, readyToCheck } from './sources';
import './restore.scss';

/**
 * Our text for a refused field, used when the server only says "not accepted".
 *
 * @param {string|null} field Request field.
 * @return {string} Hint.
 */
const hintFor = ( field ) => {
	switch ( field ) {
		case 'url':
			return __(
				'Enter the full address of the .wpress file, starting with https:// or http://.',
				'lw-migration'
			);
		case 'name':
			return __(
				'This file name is not accepted. Rename the file to a shorter, plain name ending in .wpress and choose it again.',
				'lw-migration'
			);
		case 'size':
			return __(
				'This file is too small to be a .wpress archive.',
				'lw-migration'
			);
		case 'folder':
			return __( 'Choose a backup from the list.', 'lw-migration' );
		default:
			return '';
	}
};

/**
 * The control a refused start points at (ruling P3).
 *
 * @param {string} text The server's text ('' = valid).
 * @return {Object} Props for a core control.
 */
const marked = ( text ) =>
	text ? { 'aria-invalid': true, className: 'is-invalid' } : {};

/**
 * "Restore or move a site", step 1: the source. Four tiles (a backup here,
 * a file, an address, a destination), the chosen one's panel and Continue,
 * which starts the restore job: it receives the archive and stops at its
 * check (the job screen shows it). The choices live in App (`draft`), so
 * Back from the check returns to them.
 *
 * @param {Object}                                 props
 * @param {boolean}                                props.busy    Another job runs.
 * @param {(job: Object, file: File|null) => void} props.onStart Receives the new job (and the file to upload).
 * @param {Object}                                 props.draft   The source step's state (sources.js EMPTY_DRAFT).
 * @param {(patch: Object) => void}                props.onDraft Receives a change of it.
 */
export default function RestoreView( { busy, onStart, draft, onDraft } ) {
	const [ starting, setStarting ] = useState( false );
	const [ problem, setProblem ] = useState( null );
	const list = useRemote( api.backups );
	const destinations = useRemote( api.destinations );
	useCopiesCheck( list, destinations );
	const items = list.data?.items || [];
	const backup = findBackup( items, draft.wanted );
	const cloud = pickDestination(
		destinations.data?.items || [],
		draft.cloudId
	);
	const { source } = draft;
	const ready = readyToCheck( source, draft, cloud, backup );
	const fieldError = ( control ) =>
		control === problem?.control ? problem.text : '';

	// Any change clears the last refusal: it was about the old input.
	const edit = ( patch ) => {
		onDraft( patch );
		setProblem( null );
	};

	const start = async () => {
		setStarting( true );
		setProblem( null );
		try {
			const job =
				'cloud' === source
					? await api.restoreRemote( cloud.id, draft.cloudName, true )
					: await api.startJob(
							buildImportRequest( source, {
								file: draft.file,
								url: draft.url,
								backup,
							} )
						);
			onStart( job, 'upload' === source ? draft.file : null );
		} catch ( e ) {
			const where =
				'cloud' === source
					? { control: null, field: null, reload: false }
					: startProblem( e );
			setProblem( {
				...where,
				text: where.control
					? refusalText( e, hintFor( where.field ) )
					: '',
				loggedOut: isLoggedOut( e ),
				notice: where.control ? '' : errorMessage( e ),
			} );
			if ( where.reload ) {
				list.reload();
			}
			setStarting( false );
		}
	};

	const chosen = {
		backup: backup?.name,
		upload: draft.file?.name,
		url: draft.url.trim(),
		cloud: draft.cloudName,
	}[ source ];

	return (
		<div className="lw-restore">
			<RestoreLead step={ 0 } />
			<Section
				className="lw-restore__card"
				title={ __( 'Where to restore from?', 'lw-migration' ) }
			>
				<SourceTiles
					value={ source }
					count={ list.data ? items.length : null }
					onChange={ ( value ) => edit( { source: value } ) }
				/>
				{ 'backup' === source && (
					<BackupPanel
						list={ list }
						destinations={ destinations.data?.items || [] }
						chosen={ backup }
						onChoose={ ( item ) =>
							edit( {
								wanted: item
									? { folder: item.folder, name: item.name }
									: null,
							} )
						}
						error={ fieldError( 'backup' ) }
					/>
				) }
				{ 'upload' === source && (
					<div>
						<FilePicker
							file={ draft.file }
							onFile={ ( file ) => edit( { file } ) }
							describedBy={
								fieldError( 'upload' )
									? 'lw-restore-upload-error'
									: undefined
							}
						/>
						<p className="lw-restore__hint">
							{ __(
								'A big file is fine: it is sent in parts, and an interrupted upload goes on where it stopped.',
								'lw-migration'
							) }
						</p>
						{ fieldError( 'upload' ) && (
							<p
								id="lw-restore-upload-error"
								className="lw-restore__error"
								role="alert"
							>
								{ fieldError( 'upload' ) }
							</p>
						) }
					</div>
				) }
				{ 'url' === source && (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="url"
						label={ __( 'Address of the backup', 'lw-migration' ) }
						placeholder="https://example.com/shared-backup.wpress"
						help={
							fieldError( 'url' ) ||
							__(
								'A direct download link, or the public share link of a cloud storage. Addresses on internal networks are refused, and every redirect is checked again.',
								'lw-migration'
							)
						}
						value={ draft.url }
						onChange={ ( url ) => edit( { url } ) }
						{ ...marked( fieldError( 'url' ) ) }
					/>
				) }
				{ 'cloud' === source && (
					<CloudPanel
						remote={ destinations }
						current={ cloud }
						name={ draft.cloudName }
						local={ items }
						onChange={ edit }
					/>
				) }
				{ problem?.loggedOut && (
					<LoginNotice tab="import" className="lw-restore__notice" />
				) }
				{ problem?.notice && ! problem.loggedOut && (
					<Notice
						className="lw-restore__notice"
						status="error"
						isDismissible={ false }
					>
						{ problem.notice }
					</Notice>
				) }
				<div className="lw-restore__actions">
					{ chosen && (
						<p className="lw-restore__chosen">
							{ __( 'Chosen:', 'lw-migration' ) }{ ' ' }
							<strong>{ chosen }</strong>
						</p>
					) }
					<Button
						__next40pxDefaultSize
						variant="primary"
						isBusy={ starting }
						disabled={ busy || ! ready || starting }
						accessibleWhenDisabled
						onClick={ start }
					>
						{ __( 'Continue to the check', 'lw-migration' ) }
					</Button>
				</div>
			</Section>
		</div>
	);
}

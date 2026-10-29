/**
 * WordPress dependencies
 */
// ConfirmDialog is experimental in core; the shared LW admin pattern uses it.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Button,
	Notice,
	TextControl,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import LoadError from '../components/LoadError';
import LoginNotice from '../components/LoginNotice';
import Section from '../components/Section';
import SettingRow from '../components/SettingRow';
import { SkeletonFields, SkeletonRegion } from '../components/skeleton';
import { api } from '../data/api';
import { errorMessage, invalidField, isLoggedOut } from '../data/errors';
import { formatBytes } from '../data/format';
import { refusalText } from '../data/refusal';
import useRemote from '../data/useRemote';
import {
	actionsFor,
	confirmText,
	movingTarget,
	targetLabel,
} from './backupFolder';

const FIELD = 'lw-backup-dir-path';

/**
 * The section's static header (the skeleton shows it too).
 *
 * @param {Object}  props
 * @param {Element} props.children Body.
 */
const FolderSection = ( { children } ) => (
	<Section
		title={ __( 'Backups folder', 'lw-migration' ) }
		description={ __(
			'Where the backups are kept. Best is a folder outside the website, for example in your home folder or on another disk. The backups always go into a new folder with a random name made inside the folder you choose. Moving copies every backup there; the originals are removed only after their copy is checked.',
			'lw-migration'
		) }
	>
		{ children }
	</Section>
);

/**
 * The folder in use: a folder of your own as the server shows it (a random
 * name already masked), the default one never with its path.
 *
 * @param {Object} props
 * @param {Object} props.view GET /backup-dir.
 */
function CurrentFolder( { view } ) {
	return (
		<SettingRow
			title={ __( 'Now', 'lw-migration' ) }
			help={
				view.hidden
					? __(
							'a folder with a random name, made by the plugin',
							'lw-migration'
						)
					: undefined
			}
		>
			<p className="lw-backup-folder__now">
				{ '' === view.custom ? (
					targetLabel( '' )
				) : (
					<code>{ view.custom }</code>
				) }
			</p>
		</SettingRow>
	);
}

/**
 * Where the backups are kept, and moving them (a job). Not part of the
 * settings draft and the top bar's Save button: every action starts a move
 * job, which the job screen then drives in this section.
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    Another job runs.
 * @param {(job: Object) => void} props.onStart Receives the new job.
 */
export default function BackupFolderSection( { busy, onStart } ) {
	const remote = useRemote( api.backupDir );
	const [ path, setPath ] = useState( '' );
	// The body waiting for the confirmation, or null.
	const [ asking, setAsking ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const [ refused, setRefused ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ loggedOut, setLoggedOut ] = useState( false );

	if ( remote.error && ! remote.data ) {
		return (
			<FolderSection>
				<LoadError
					error={ remote.error }
					tab="settings"
					onRetry={ remote.reload }
				/>
			</FolderSection>
		);
	}
	if ( ! remote.data ) {
		return (
			<FolderSection>
				<SkeletonRegion
					label={ __(
						'Loading the backups folder…',
						'lw-migration'
					) }
				>
					<SkeletonFields count={ 2 } />
				</SkeletonRegion>
			</FolderSection>
		);
	}

	const view = remote.data;
	const { show, why, disabled: held } = actionsFor( view, busy );
	const has = ( action ) => show.includes( action );
	const disabled = held || starting;
	const button = {
		__next40pxDefaultSize: true,
		disabled,
		accessibleWhenDisabled: true,
	};

	const ask = ( body ) => {
		setRefused( '' );
		setError( '' );
		setLoggedOut( false );
		setAsking( body );
	};
	const send = async () => {
		const body = asking;
		setAsking( null );
		setStarting( true );
		try {
			onStart( await api.moveBackups( body ) );
		} catch ( e ) {
			setStarting( false );
			if ( isLoggedOut( e ) ) {
				setLoggedOut( true );
			} else if ( 'path' === invalidField( e ) ) {
				setRefused(
					refusalText(
						e,
						__( 'This folder cannot be used.', 'lw-migration' )
					)
				);
			} else {
				setError( errorMessage( e ) );
			}
		}
	};
	const field = has( 'move' );

	return (
		<FolderSection>
			{ loggedOut && <LoginNotice tab="settings" /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ refused && ! field && (
				<Notice status="error" isDismissible={ false }>
					{ refused }
				</Notice>
			) }
			<CurrentFolder view={ view } />
			{ view.problemText && (
				<Callout tone="warning">{ view.problemText }</Callout>
			) }
			{ view.moving && (
				<Callout tone="warning">
					<p className="lw-backup-folder__moving">
						{ sprintf(
							/* translators: 1: number of backups, 2: size, 3: the new folder (or "the default folder") */
							_n(
								'Moving the backups to %3$s did not finish: %1$d backup (%2$s) is still in the old folder. Both folders are listed under Backups.',
								'Moving the backups to %3$s did not finish: %1$d backups (%2$s) are still in the old folder. Both folders are listed under Backups.',
								view.moving.left,
								'lw-migration'
							),
							view.moving.left,
							formatBytes( view.moving.leftBytes ),
							movingTarget( view.moving )
						) }
					</p>
					{ show.length > 0 && (
						<div className="lw-actions">
							{ has( 'finish' ) && (
								<Button
									{ ...button }
									variant="primary"
									onClick={ () =>
										ask( { action: 'finish' } )
									}
								>
									{ __( 'Finish moving', 'lw-migration' ) }
								</Button>
							) }
							{ has( 'undo' ) && (
								<Button
									{ ...button }
									variant="secondary"
									onClick={ () => ask( { action: 'undo' } ) }
								>
									{ __( 'Move them back', 'lw-migration' ) }
								</Button>
							) }
						</div>
					) }
				</Callout>
			) }
			{ 'locked' === why && (
				<Callout>
					{ __(
						'The backups folder cannot be changed on this site.',
						'lw-migration'
					) }
				</Callout>
			) }
			{ 'unsupported' === why && (
				<Callout>
					{ __(
						'A folder of your own is not available on this server.',
						'lw-migration'
					) }
				</Callout>
			) }
			{ field && (
				<SettingRow
					title={ __( 'New folder', 'lw-migration' ) }
					htmlFor={ FIELD }
					stacked
					error={ refused }
				>
					<TextControl
						id={ FIELD }
						{ ...( refused
							? {
									'aria-invalid': true,
									'aria-describedby': `${ FIELD }__help ${ FIELD }-error`,
								}
							: {} ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="off"
						spellCheck={ false }
						value={ path }
						help={ __(
							'Full path. Outside the website: a new or empty folder, for example /home/you/lw-backups. Inside the website (for example wp-content): the folder in which a new, randomly named folder is made.',
							'lw-migration'
						) }
						onChange={ ( value ) => {
							setPath( value );
							setRefused( '' );
						} }
					/>
				</SettingRow>
			) }
			{ ! view.moving && show.length > 0 && (
				<div className="lw-actions">
					{ field && (
						<Button
							{ ...button }
							variant="primary"
							isBusy={ starting }
							disabled={ disabled || '' === path.trim() }
							onClick={ () => ask( { path } ) }
						>
							{ __( 'Move the backups here', 'lw-migration' ) }
						</Button>
					) }
					{ has( 'backToDefault' ) && (
						<Button
							{ ...button }
							variant="secondary"
							onClick={ () => ask( { path: '' } ) }
						>
							{ __(
								'Back to the default folder',
								'lw-migration'
							) }
						</Button>
					) }
				</div>
			) }
			<ConfirmDialog
				className="lw-modal"
				isOpen={ null !== asking }
				onConfirm={ send }
				onCancel={ () => setAsking( null ) }
				confirmButtonText={ __( 'Move', 'lw-migration' ) }
				cancelButtonText={ __( 'Cancel', 'lw-migration' ) }
			>
				{ asking && confirmText( asking ) }
			</ConfirmDialog>
		</FolderSection>
	);
}

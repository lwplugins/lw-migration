/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { cloudDownload, trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import StatusBadge from '../components/StatusBadge';
import { SkeletonFields, SkeletonRegion } from '../components/skeleton';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import { formatBytes, formatDatetime } from '../data/format';
import useRemote from '../data/useRemote';
import InlineConfirm from './InlineConfirm';

/**
 * "Mentések ott" (spec 21.6), open inside the destination's card: the
 * backups at one destination, newest first; this site's own are marked.
 * Restore starts the restore (it downloads into the backups folder first
 * and asks before replacing the site, like any restore; an encrypted one
 * asks for its password there); Download here puts it in the backups list;
 * Delete asks in its own row, then removes it at the destination. Cancel or
 * Escape there puts the focus back on the row's Delete; after a delete the
 * focus goes to the panel's heading.
 *
 * @param {Object}                props
 * @param {string}                props.id      Panel id (the toggle's aria-controls).
 * @param {Object}                props.item    Destination view.
 * @param {boolean}               props.busy    Another job runs (no restore or download).
 * @param {(job: Object) => void} props.onStart Receives a started job.
 */
export default function RemoteFilesPanel( { id, item, busy, onStart } ) {
	const fetcher = useCallback(
		() => api.remoteFiles( item.id ),
		[ item.id ]
	);
	const remote = useRemote( fetcher );
	const [ confirming, setConfirming ] = useState( null );
	const [ failure, setFailure ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const [ giveBack, setGiveBack ] = useState( null );
	const heading = useRef( null );
	const deleteButtons = useRef( {} );

	// The focus goes back once the row (or the list) shows again.
	useEffect( () => {
		if ( ! giveBack ) {
			return;
		}
		const target =
			'heading' === giveBack.to
				? heading.current
				: deleteButtons.current[ giveBack.to ];
		target?.focus();
		setGiveBack( null );
	}, [ giveBack ] );

	const start = async ( call ) => {
		setFailure( null );
		setStarting( true );
		try {
			onStart( await call() );
		} catch ( e ) {
			setFailure( e );
			setStarting( false );
		}
	};
	const problem = failure || ( ! remote.data && remote.error );
	const blocked = busy || starting;
	const headingId = `${ id }-title`;

	const row = ( f ) => {
		if ( confirming === f.name ) {
			return (
				<InlineConfirm
					key={ f.name }
					tag="li"
					className="lw-remote-files__row is-confirm"
					lead={
						<span className="lw-remote-files__name">
							<code>{ f.name }</code>
						</span>
					}
					text={ __(
						'It is deleted at the destination for good.',
						'lw-migration'
					) }
					confirmLabel={ __( 'Delete', 'lw-migration' ) }
					onConfirm={ async () => {
						await api.deleteRemote( item.id, f.name );
						setConfirming( null );
						setGiveBack( { to: 'heading' } );
						remote.reload();
					} }
					onCancel={ () => {
						setConfirming( null );
						setGiveBack( { to: f.name } );
					} }
				/>
			);
		}
		return (
			<li key={ f.name } className="lw-remote-files__row">
				<span className="lw-remote-files__name">
					<code>{ f.name }</code>
					{ f.ours && (
						<span className="lw-cell-badge">
							<StatusBadge status="info">
								{ __( 'this site', 'lw-migration' ) }
							</StatusBadge>
						</span>
					) }
				</span>
				<span className="lw-remote-files__meta">
					{ formatDatetime( f.mtime ) } · { formatBytes( f.size ) }
				</span>
				<span className="lw-row-actions">
					<Button
						size="compact"
						variant="tertiary"
						disabled={ blocked }
						accessibleWhenDisabled
						label={ sprintf(
							/* translators: %s: backup file name. */
							__( 'Restore the site from %s', 'lw-migration' ),
							f.name
						) }
						onClick={ () =>
							start( () =>
								api.restoreRemote( item.id, f.name, true )
							)
						}
					>
						{ __( 'Restore', 'lw-migration' ) }
					</Button>
					<Button
						size="compact"
						variant="tertiary"
						icon={ cloudDownload }
						disabled={ blocked }
						accessibleWhenDisabled
						label={ sprintf(
							/* translators: %s: backup file name. */
							__(
								'Download %s into the backups list',
								'lw-migration'
							),
							f.name
						) }
						showTooltip
						onClick={ () =>
							start( () => api.fetchRemote( item.id, f.name ) )
						}
					/>
					<Button
						ref={ ( node ) => {
							deleteButtons.current[ f.name ] = node;
						} }
						size="compact"
						variant="tertiary"
						isDestructive
						icon={ trash }
						disabled={ blocked }
						accessibleWhenDisabled
						label={ sprintf(
							/* translators: %s: backup file name. */
							__(
								'Delete %s at the destination',
								'lw-migration'
							),
							f.name
						) }
						showTooltip
						onClick={ () => setConfirming( f.name ) }
					/>
				</span>
			</li>
		);
	};

	return (
		<div
			id={ id }
			className="lw-dest__panel lw-remote-files"
			role="region"
			aria-labelledby={ headingId }
		>
			<h4
				id={ headingId }
				ref={ heading }
				tabIndex={ -1 }
				className="lw-dest__panel-title"
			>
				{ sprintf(
					/* translators: %s: destination name. */
					__( 'Backups at %s', 'lw-migration' ),
					item.name
				) }
			</h4>
			{ ! remote.data && ! remote.error && (
				<SkeletonRegion
					label={ __( 'Loading the backups there…', 'lw-migration' ) }
				>
					<SkeletonFields count={ 3 } />
				</SkeletonRegion>
			) }
			{ problem && isLoggedOut( problem ) && (
				<LoginNotice tab="destinations" />
			) }
			{ problem && ! isLoggedOut( problem ) && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage( problem ) }
				</Notice>
			) }
			{ remote.data?.insecure && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This destination sends backups unencrypted.',
						'lw-migration'
					) }
				</Notice>
			) }
			{ remote.data && 0 === remote.data.files.length && (
				<p className="lw-remote-files__empty">
					{ __( 'No backup there yet.', 'lw-migration' ) }
				</p>
			) }
			{ remote.data && remote.data.files.length > 0 && (
				<ul className="lw-remote-files__list">
					{ remote.data.files.map( row ) }
				</ul>
			) }
		</div>
	);
}

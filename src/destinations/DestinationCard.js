/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, DropdownMenu, Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { link, moreVertical, pencil, trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import DestinationIcon, { presetOf } from '../components/DestinationIcon';
import LoginNotice from '../components/LoginNotice';
import StatusBadge from '../components/StatusBadge';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import { headingId, togglePanel } from './card';
import DestinationEditor from './DestinationEditor';
import { deleteText, statusLabel, statusTone } from './destinations';
import { cardFacts } from './facts';
import { checkConnection, trustHostKey } from './hostKeyFlow';
import HostKeyNotice from './HostKeyNotice';
import InlineConfirm from './InlineConfirm';
import { connectLabel, goToProvider, isOAuth, needsConnect } from './oauth';
import RemoteFilesPanel from './RemoteFilesPanel';

/**
 * One destination, as a list item: its tile, name and state, where it
 * keeps the backups and the facts (last upload, schedules, what it keeps,
 * the account); Connect while an account needs one, the connection check,
 * "Backups there" and the ⋯ menu (Edit, only for a type this site knows,
 * and Delete). Everything opens inside the card, one panel at a time
 * (never a dialog): the backups there, the edit form in place of the row,
 * the delete confirmation, and an SFTP host key or an FTPS certificate to
 * confirm (its codes only, P-I9, issue #5; hostKeyFlow.js) until the
 * address or the fingerprint setting changes. A saved edit of a server or
 * a storage checks the connection at once. A failed check is recorded on
 * the destination, so the list is read again and the card shows the stored
 * message, spoken at once (a check is asked for, so it is announced
 * assertively; a message already there when the page loads is not); only a
 * refusal that recorded nothing (logged out, no connection) is shown under
 * the row. A destination that sends backups
 * unencrypted says so (D37). A message on a new destination (an old
 * sign-in that could not be withdrawn at the provider, issue #6) is a
 * warning, not an error. A destination just added from the shelf checks
 * its connection as soon as its card is there (checkOnMount).
 *
 * @param {Object}                          props
 * @param {Object}                          props.item         Destination view.
 * @param {Object|null}                     props.type         Its schema type (null: unknown here, no Edit).
 * @param {boolean}                         props.busy         A job runs (no restore or download from it).
 * @param {(job: Object) => void}           props.onStart      Receives a started job.
 * @param {(item: Object) => Promise<void>} props.onDelete     Deletes it (rejects like request()).
 * @param {() => Promise<void>}             props.onChanged    The list is out of date (read it again).
 * @param {(name: string) => void}          props.onWorks      The connection test passed (the name it has now).
 * @param {boolean}                         props.checkOnMount Check the connection now (a new destination).
 */
export default function DestinationCard( {
	item,
	type = null,
	busy,
	onStart,
	onDelete,
	onChanged,
	onWorks,
	checkOnMount = false,
} ) {
	const [ panel, setPanel ] = useState( null );
	const [ hostKey, setHostKey ] = useState( null );
	const [ testing, setTesting ] = useState( false );
	const [ trusting, setTrusting ] = useState( false );
	const [ failure, setFailure ] = useState( null );
	const [ connecting, setConnecting ] = useState( false );
	const [ giveBack, setGiveBack ] = useState( null );
	const [ recorded, setRecorded ] = useState( false );
	const checkButton = useRef( null );
	const menuButton = useRef( null );
	const editId = `lw-destination-${ item.id }`; // Another card may be in edit too.
	const oauth = Boolean( item.connection );
	const titleId = headingId( item.id );
	const filesId = `lw-dest-${ item.id }-files`;

	// A fingerprint checked against another address or setting is not this one's.
	const address = [ 'host', 'port', 'security', 'hostKey', 'certFingerprint' ]
		.map( ( key ) => item.settings?.[ key ] ?? '' )
		.join( ' ' );
	useEffect( () => {
		setHostKey( null );
		setPanel( ( open ) => ( 'hostKey' === open ? null : open ) );
	}, [ address ] );

	// A closed panel gives the focus back to what opened it.
	useEffect( () => {
		if ( giveBack ) {
			( 'check' === giveBack
				? checkButton
				: menuButton
			).current?.focus();
			setGiveBack( null );
		}
	}, [ giveBack ] );

	const show = ( next ) => {
		setPanel( next );
		if ( 'hostKey' !== next ) {
			setHostKey( null );
		}
	};
	const close = ( back ) => {
		show( null );
		setGiveBack( back );
	};

	// A failure the server recorded reaches the card with the list read
	// again: by the render that carries `recorded`, the item is the new one.
	useEffect( () => {
		if ( recorded ) {
			setRecorded( false );
			if ( 'ok' !== item.status && item.message ) {
				speak( item.message, 'assertive' );
			}
		}
	}, [ recorded, item ] );

	/**
	 * @param {string} name The destination's name now (just renamed, maybe).
	 * @return {{card: Object, worked: () => boolean}} The flow's callbacks.
	 */
	const callbacks = ( name ) => {
		let worked = false;
		return {
			card: {
				onChanged,
				onWorks: () => {
					worked = true;
					onWorks( name );
				},
			},
			worked: () => worked,
		};
	};

	const test = async ( name = item.name ) => {
		setTesting( true );
		setFailure( null );
		setHostKey( null );
		setPanel( ( open ) => ( 'hostKey' === open ? null : open ) );
		const flow = callbacks( name );
		const shown = await checkConnection( item.id, flow.card );
		if ( shown.hostKey ) {
			setHostKey( shown.hostKey );
			setPanel( 'hostKey' );
		}
		setFailure( shown.failure );
		setTesting( false );
		setRecorded( ! flow.worked() && ! shown.hostKey && ! shown.failure );
	};

	const connectNow = async () => {
		setConnecting( true );
		setFailure( null );
		try {
			goToProvider( await api.connectDestination( item.id ) );
		} catch ( e ) {
			setFailure( e );
			setConnecting( false );
		}
	};

	const trust = async () => {
		setTrusting( true );
		setFailure( null );
		const flow = callbacks( item.name );
		const shown = await trustHostKey( item.id, hostKey, flow.card );
		setTrusting( false );
		setFailure( shown.failure );
		setRecorded( ! flow.worked() && ! shown.hostKey && ! shown.failure );
		if ( shown.hostKey ) {
			setHostKey( shown.hostKey );
		} else {
			close( 'check' ); // The panel that held the focus is gone.
		}
	};

	// Added just now: its first check runs here, so the result (a host key
	// to confirm included) lands on this card.
	useEffect( () => {
		if ( checkOnMount ) {
			test();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ checkOnMount ] );

	const saved = async ( view, { connected } ) => {
		close( 'menu' );
		await onChanged();
		if ( connected && ! isOAuth( type ) ) {
			await test( view.name ); // This render's item still has the old name.
		}
	};

	if ( 'edit' === panel && type ) {
		return (
			<li className="lw-dest is-editing" aria-labelledby={ titleId }>
				<DestinationEditor
					mode="edit"
					type={ type }
					item={ item }
					idPrefix={ editId }
					onSaved={ saved }
					onCancel={ () => close( 'menu' ) }
					onMissing={ onChanged }
					renderHead={ ( { nameInput, where, preset } ) => (
						<div className="lw-dest__row is-head">
							<span className="lw-dest__tile">
								<DestinationIcon
									type={ item.type }
									preset={ preset }
									size={ 22 }
								/>
							</span>
							<div className="lw-dest__main">
								<div className="lw-dest__title">
									<h3
										id={ titleId }
										tabIndex={ -1 }
										className="screen-reader-text"
									>
										{ item.name }
									</h3>
									{ nameInput }
									<StatusBadge status="info">
										{ __( 'Editing', 'lw-migration' ) }
									</StatusBadge>
								</div>
								<p
									className="lw-dest__where is-live"
									aria-live="polite"
								>
									{ where || item.where }
								</p>
							</div>
						</div>
					) }
				/>
			</li>
		);
	}

	const message = 'hostKey' !== panel && 'ok' !== item.status && item.message;

	return (
		<li className="lw-dest" aria-labelledby={ titleId }>
			<div className="lw-dest__row">
				<span className="lw-dest__tile">
					<DestinationIcon
						type={ item.type }
						preset={ presetOf( item ) }
						size={ 22 }
					/>
				</span>
				<div className="lw-dest__main">
					<div className="lw-dest__title">
						<h3
							id={ titleId }
							tabIndex={ -1 }
							className="lw-dest__name"
						>
							{ item.name }
						</h3>
						<StatusBadge status={ statusTone( item.status ) }>
							{ statusLabel( item.status ) }
						</StatusBadge>
					</div>
					{ item.where && (
						<p className="lw-dest__where">{ item.where }</p>
					) }
					<ul className="lw-dest__facts">
						{ cardFacts( item ).map( ( [ label, value ] ) => (
							<li key={ label }>
								{ label } <b>{ value }</b>
							</li>
						) ) }
					</ul>
					{ message && (
						<p
							className={ `lw-dest__message ${
								'error' === item.status
									? 'is-error'
									: 'is-warning'
							}` }
						>
							{ item.message }
						</p>
					) }
				</div>
				<div className="lw-dest__actions">
					{ oauth && needsConnect( item ) && (
						<Button
							size="compact"
							variant="primary"
							icon={ link }
							isBusy={ connecting }
							disabled={
								connecting || false === item.connection.eligible
							}
							accessibleWhenDisabled
							onClick={ connectNow }
						>
							{ connectLabel( item ) }
						</Button>
					) }
					<Button
						ref={ checkButton }
						size="compact"
						variant="secondary"
						isBusy={ testing }
						disabled={
							testing ||
							trusting ||
							( oauth && ! item.connection.connected )
						}
						accessibleWhenDisabled
						aria-describedby={ titleId }
						onClick={ () => test() }
					>
						{ __( 'Check connection', 'lw-migration' ) }
					</Button>
					<Button
						size="compact"
						variant="tertiary"
						aria-expanded={ 'files' === panel }
						aria-controls={ filesId }
						aria-describedby={ titleId }
						onClick={ () => show( togglePanel( panel, 'files' ) ) }
					>
						{ __( 'Backups there', 'lw-migration' ) }
					</Button>
					<DropdownMenu
						icon={ moreVertical }
						label={ sprintf(
							/* translators: %s: destination name. */
							__( 'More for %s', 'lw-migration' ),
							item.name
						) }
						toggleProps={ { size: 'compact', ref: menuButton } }
						popoverProps={ { placement: 'bottom-end' } }
						controls={ [
							...( type
								? [
										{
											title: __( 'Edit', 'lw-migration' ),
											icon: pencil,
											onClick: () => show( 'edit' ),
										},
									]
								: [] ),
							{
								title: __( 'Delete', 'lw-migration' ),
								icon: trash,
								onClick: () => show( 'delete' ),
							},
						] }
					/>
				</div>
			</div>
			{ ( ( oauth && false === item.connection.eligible ) ||
				( oauth && item.connection.redirectChanged ) ||
				item.insecure ||
				failure ) && (
				<div className="lw-dest__notices">
					{ oauth && false === item.connection.eligible && (
						<Notice status="error" isDismissible={ false }>
							{ item.connection.text }
						</Notice>
					) }
					{ oauth && item.connection.redirectChanged && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								"This site's address changed since the account was connected. Register the new redirect address at the provider before you connect again.",
								'lw-migration'
							) }
						</Notice>
					) }
					{ item.insecure && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This destination sends backups unencrypted.',
								'lw-migration'
							) }
						</Notice>
					) }
					{ failure && isLoggedOut( failure ) && (
						<LoginNotice tab="destinations" />
					) }
					{ failure && ! isLoggedOut( failure ) && (
						<Notice status="error" isDismissible={ false }>
							{ errorMessage( failure ) }
						</Notice>
					) }
				</div>
			) }
			{ 'files' === panel && (
				<RemoteFilesPanel
					id={ filesId }
					item={ item }
					busy={ busy }
					onStart={ ( job ) => {
						show( null );
						onStart( job );
					} }
				/>
			) }
			{ 'delete' === panel && (
				<InlineConfirm
					className="lw-dest__panel is-error"
					title={ __( 'Delete this destination?', 'lw-migration' ) }
					text={ deleteText( item ) }
					confirmLabel={ __( 'Delete', 'lw-migration' ) }
					onConfirm={ () => onDelete( item ) }
					onCancel={ () => close( 'menu' ) }
				/>
			) }
			{ 'hostKey' === panel && hostKey && (
				<HostKeyNotice
					id={ `lw-dest-${ item.id }-key` }
					state={ hostKey }
					busy={ trusting }
					onTrust={ trust }
					onLater={ () => close( 'check' ) }
				/>
			) }
		</li>
	);
}

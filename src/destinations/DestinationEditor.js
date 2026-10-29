/**
 * WordPress dependencies
 */
import { Button, Notice, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { presetOf } from '../components/DestinationIcon';
import LoginNotice from '../components/LoginNotice';
import { api } from '../data/api';
import { errorMessage, invalidField, isLoggedOut } from '../data/errors';
import { refusalText } from '../data/refusal';
import { movesLocation, saveLabel } from './card';
import Field from './DestinationField';
import {
	checkForm,
	emptyForm,
	fromItem,
	toRequest,
	visible,
	withSetting,
} from './destinations';
import KeepSentence from './KeepSentence';
import {
	CLIENT_SECRET,
	clientChanged,
	connectsOnSave,
	forgetsConnection,
	isConnectMarker,
	isOAuth,
	isOwnApp,
	saveDestination,
} from './oauth';
import { OAuthIntro, OwnAppGuide } from './OAuthPanel';
import { nameForPreset, previewWhere } from './shelf';

/**
 * @param {Object} type   Schema type.
 * @param {string} preset S3 provider to start with ('' = the schema's default).
 * @param {string} name   The name to start with.
 * @return {Object} An empty form.
 */
const startForm = ( type, preset, name ) => {
	const form = { ...emptyForm( type ), name };
	return preset ? withSetting( form, 'preset', preset ) : form;
};

/**
 * A field that takes the whole width of the field grid (a key or a switch).
 *
 * @param {Object} field Schema field.
 * @return {boolean} Wide.
 */
const isWide = ( field ) =>
	'secret_text' === field.kind || 'toggle' === field.kind;

/**
 * A destination's settings form, inside its card (never a dialog). The
 * fields come from the server's schema; a secret field stays empty (typing
 * replaces the stored value, empty keeps it). A refusal that names a field
 * is shown under it and focused; any other refusal above the buttons. The
 * name sits in the card's title (renderHead), next to the live line of
 * where the backups go. A server or a storage saves and the caller checks
 * the connection; a cloud account saves and connects in one go when it
 * needs to: the connect block comes first, the own app's fields are under
 * "Advanced". The saved destination is remembered, so a second Save after a
 * failed connect updates it instead of creating another one, and Cancel
 * (keptLabel, with keptNote beside it) then hands it over as saved. A new
 * destination that is gone meanwhile is created again by the next Save. A
 * new S3 destination still named after its provider follows the provider
 * select.
 *
 * @param {Object}                                                                 props
 * @param {'new'|'edit'}                                                           props.mode        A new destination or an edit.
 * @param {Object}                                                                 props.type        Its schema type.
 * @param {Object|null}                                                            props.item        The stored destination (edit).
 * @param {string}                                                                 props.preset      New S3 destination: the provider to start with.
 * @param {string}                                                                 props.startName   New destination: the name to start with.
 * @param {(preset: string) => void}                                               props.onPreset    Receives the provider (the icon's preset) as it changes.
 * @param {string}                                                                 props.footNote    A line beside the buttons.
 * @param {(item: Object, result: {connected: boolean}) => void}                   props.onSaved     Receives the saved view.
 * @param {() => void}                                                             props.onCancel    Closes without saving.
 * @param {() => void}                                                             props.onMissing   The edited destination is gone (read the list again).
 * @param {string}                                                                 props.idPrefix    Field ids are `<idPrefix>-<key>`.
 * @param {string}                                                                 props.cancelLabel The cancel button's label.
 * @param {string}                                                                 props.keptLabel   The cancel button's label once something is stored.
 * @param {string}                                                                 props.keptNote    The line beside the buttons once something is stored.
 * @param {(head: {nameInput: Element, where: string, preset: string}) => Element} props.renderHead  The card's title row.
 */
export default function DestinationEditor( {
	mode,
	type,
	item = null,
	preset = '',
	startName = '',
	onPreset,
	footNote = '',
	onSaved,
	onCancel,
	onMissing = () => {},
	idPrefix = 'lw-destination',
	cancelLabel,
	keptLabel,
	keptNote = '',
	renderHead = ( { nameInput } ) => nameInput,
} ) {
	const initial = 'edit' === mode ? item : null;
	const [ form, setForm ] = useState( () =>
		initial
			? fromItem( initial, type )
			: startForm( type, preset, startName )
	);
	const [ saving, setSaving ] = useState( false );
	const [ problem, setProblem ] = useState( null );
	const [ stored, setStored ] = useState( initial );
	const [ advanced, setAdvanced ] = useState( () =>
		isOwnApp( form.settings )
	);
	const formRef = useRef( null );
	const fieldId = ( key ) => `${ idPrefix }-${ key }`;
	const focusField = ( key ) => {
		const target = formRef.current?.querySelector( `#${ fieldId( key ) }` );
		const closed = target?.closest( 'details:not([open])' );
		if ( closed ) {
			closed.open = true; // A refused own-app field is never hidden.
		}
		target?.focus();
	};

	// The name first; later the field a refusal names.
	useEffect( () => {
		focusField( 'name' );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	useEffect( () => {
		if ( problem?.field ) {
			focusField( problem.field );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ problem ] );

	const iconPreset = presetOf( { type: type.type, settings: form.settings } );
	useEffect( () => {
		onPreset?.( iconPreset );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ iconPreset ] );

	const oauth = isOAuth( type );
	const connects = connectsOnSave( type, stored, form );
	const forgets = oauth && forgetsConnection( form, stored );
	// An account that is stored (edit, or saved before its connect failed)
	// keeps the server's line: the preview only knows a new account's folder.
	const where =
		oauth && stored
			? ( stored.where ?? '' )
			: previewWhere( type.type, form, type );
	const bigButton = oauth && 'new' === mode;
	const shownFields = type.fields.filter(
		( field ) =>
			! isConnectMarker( field ) && visible( field, form.settings )
	);
	const shown = [ 'name', ...shownFields.map( ( field ) => field.key ) ];
	const fieldError = ( key ) =>
		problem?.field === key ? problem.text : '';
	const kept = stored !== initial; // Saved here; its connect failed.
	const cancel = () =>
		kept ? onSaved( stored, { connected: false } ) : onCancel();
	const note = kept && keptNote ? keptNote : footNote;
	const setting = ( key, value ) => {
		const next =
			'new' === mode && 'preset' === key
				? { ...form, name: nameForPreset( form, type, value ) }
				: form;
		setForm( withSetting( next, key, value ) );
	};

	const save = async ( event ) => {
		event.preventDefault();
		const local = checkForm( form, type, stored );
		if ( local ) {
			setProblem( {
				field: local.field,
				text:
					'newClient' === local.reason
						? __(
								'A new Client ID needs the client secret of that app. Enter it here.',
								'lw-migration'
							)
						: __( 'Fill this in.', 'lw-migration' ),
			} );
			return;
		}
		setSaving( true );
		setProblem( null );
		try {
			const saved = await saveDestination(
				api,
				stored,
				toRequest( form, type, ! stored ),
				type,
				setStored
			);
			if ( saved ) {
				onSaved( saved, {
					connected:
						! oauth || Boolean( saved.connection?.connected ),
				} );
			}
		} catch ( e ) {
			setSaving( false );
			if ( 'lw_migration_destination_missing' === e?.code ) {
				if ( 'new' === mode ) {
					setStored( null ); // Gone: the next Save creates it again.
				} else {
					onMissing();
				}
			}
			const field = invalidField( e );
			if ( field && shown.includes( field ) ) {
				setProblem( {
					field,
					text: refusalText(
						e,
						__( 'This value is not accepted.', 'lw-migration' )
					),
				} );
				return;
			}
			setProblem( {
				field: null,
				text: errorMessage( e ),
				loggedOut: isLoggedOut( e ),
			} );
		}
	};

	const submit = ( big ) => (
		<Button
			__next40pxDefaultSize
			type="submit"
			variant="primary"
			isBusy={ saving }
			disabled={ saving }
			accessibleWhenDisabled
		>
			{ saveLabel( type, connects, big ) }
		</Button>
	);
	const nameError = fieldError( 'name' );
	const nameInput = (
		<div
			className={ [ 'lw-dest-form__name', nameError && 'is-invalid' ]
				.filter( Boolean )
				.join( ' ' ) }
		>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={ fieldId( 'name' ) }
				label={ __( 'Name', 'lw-migration' ) }
				hideLabelFromVision
				value={ form.name }
				maxLength={ 100 }
				onChange={ ( name ) => setForm( { ...form, name } ) }
				aria-invalid={ nameError ? true : undefined }
				aria-describedby={
					nameError ? `${ fieldId( 'name' ) }-error` : undefined
				}
			/>
			{ nameError && (
				<p
					className="lw-dest-form__error"
					id={ `${ fieldId( 'name' ) }-error` }
				>
					{ nameError }
				</p>
			) }
		</div>
	);
	const fields = (
		<div className="lw-dest-form__fields">
			{ shownFields.map( ( field ) => (
				<div
					key={ field.key }
					className={
						isWide( field )
							? 'lw-dest-form__field is-wide'
							: 'lw-dest-form__field'
					}
				>
					<Field
						field={ field }
						form={ form }
						type={ type }
						item={ stored }
						idPrefix={ idPrefix }
						stored={
							Boolean( stored?.secrets?.[ field.key ]?.set ) &&
							! (
								CLIENT_SECRET === field.key &&
								clientChanged( form, stored )
							)
						}
						error={ fieldError( field.key ) }
						onSetting={ ( value ) => setting( field.key, value ) }
						onSecret={ ( value ) =>
							setForm( {
								...form,
								secrets: {
									...form.secrets,
									[ field.key ]: value,
								},
							} )
						}
					/>
				</div>
			) ) }
		</div>
	);
	const keep = (
		<KeepSentence
			id={ fieldId( 'keep' ) }
			retention={ form.retention }
			onChange={ ( retention ) => setForm( { ...form, retention } ) }
		/>
	);

	return (
		<form
			ref={ formRef }
			className="lw-dest-form"
			onSubmit={ save }
			noValidate
		>
			{ renderHead( {
				nameInput,
				where,
				preset: iconPreset,
			} ) }
			<div className="lw-dest-form__body">
				{ oauth && (
					<div className="lw-connect">
						<OAuthIntro
							type={ type }
							item={ stored }
							settings={ form.settings }
						/>
						{ bigButton && submit( true ) }
					</div>
				) }
				{ ! oauth && fields }
				{ keep }
				{ oauth && (
					<details
						className="lw-dest-form__advanced"
						open={ advanced }
						onToggle={ ( event ) =>
							setAdvanced( event.currentTarget.open )
						}
					>
						<summary>
							{ __(
								'Advanced: use your own app',
								'lw-migration'
							) }
						</summary>
						{ fields }
						{ isOwnApp( form.settings ) && (
							<div className="lw-oauth">
								<OwnAppGuide type={ type } item={ stored } />
							</div>
						) }
					</details>
				) }
				{ movesLocation( where, stored ) && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'If you change the location, the backups already there stay at the old one.',
							'lw-migration'
						) }
					</Notice>
				) }
				{ forgets && (
					<Notice status="warning" isDismissible={ false }>
						{ connects
							? __(
									'Saving these settings disconnects the account, because it was connected with the old ones. It is connected again right after saving.',
									'lw-migration'
								)
							: __(
									'Saving these settings disconnects the account, because it was connected with the old ones. After saving, sign in at the provider again.',
									'lw-migration'
								) }
					</Notice>
				) }
				{ problem?.loggedOut && <LoginNotice tab="destinations" /> }
				{ problem && ! problem.field && ! problem.loggedOut && (
					<Notice status="error" isDismissible={ false }>
						{ problem.text }
					</Notice>
				) }
				<div className="lw-dest-form__foot">
					{ ! bigButton && submit( false ) }
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ cancel }
					>
						{ ( kept && keptLabel ) ||
							( cancelLabel ?? __( 'Cancel', 'lw-migration' ) ) }
					</Button>
					{ note && <p className="lw-dest-form__note">{ note }</p> }
				</div>
			</div>
		</form>
	);
}

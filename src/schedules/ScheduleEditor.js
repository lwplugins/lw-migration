/**
 * WordPress dependencies
 */
import {
	Button,
	FormToggle,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import { typeText } from '../components/DestinationIcon';
import { api } from '../data/api';
import { NETWORK } from '../data/boot';
import { errorMessage, invalidField, isLoggedOut } from '../data/errors';
import { refusalText } from '../data/refusal';
import useRemote from '../data/useRemote';
import { fieldText } from '../views/ExportView';
import LiveSentence from './LiveSentence';
import {
	NotifyFields,
	ScopeFields,
	TimingFields,
	marked,
} from './ScheduleFields';
import WhatSection from './WhatSection';
import WhereSection from './WhereSection';
import { EXPORT_FIELDS, FOLDED_FIELDS, ownText } from './refusals';
import {
	NEW_SCHEDULE,
	checkSchedule,
	fromItem,
	keepsPassword,
	toRequest,
	withKnownDestinations,
} from './scheduleForm';

/**
 * New or existing schedule in a sheet from the right (a Modal: focus moves
 * in on open, Escape closes it). The live sentence on top says what the form
 * would do; then Name, When, What, Where, E-mail; the switch and the save
 * button stay at the bottom. The password lives only here and goes into the
 * save request; a stored one stays unless a new one is typed.
 *
 * @param {Object}                 props
 * @param {Object|null}            props.item     ScheduleView item to edit, or null for a new schedule.
 * @param {Object|null}            props.initial  Form state a new schedule starts from (Duplicate, a starter).
 * @param {string}                 props.timezone Site time zone name.
 * @param {string}                 props.fallback The default address of the Settings ('' = none).
 * @param {() => void}             props.onClose  Close without saving.
 * @param {(item: Object) => void} props.onSaved  Receives the saved item.
 */
export default function ScheduleEditor( {
	item,
	initial = null,
	timezone,
	fallback,
	onClose,
	onSaved,
} ) {
	const [ form, setForm ] = useState( () => {
		if ( item ) {
			return fromItem( item );
		}
		return initial || NEW_SCHEDULE;
	} );
	const [ password, setPassword ] = useState( '' );
	const [ again, setAgain ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ errors, setErrors ] = useState( {} );
	const [ problem, setProblem ] = useState( '' );
	const [ loggedOut, setLoggedOut ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const formRef = useRef();
	const cloud = useRemote( api.destinations );
	const destinations = cloud.data?.items || [];

	// Move to the control a refusal marked (scrolls the dialog to it).
	useEffect( () => {
		if ( Object.keys( errors ).length ) {
			formRef.current
				?.querySelector(
					'[aria-invalid="true"], [data-refused="true"]'
				)
				?.focus();
		}
	}, [ errors ] );

	const change = ( patch ) => {
		setForm( ( prev ) => ( { ...prev, ...patch } ) );
		setErrors( {} );
		setProblem( '' );
	};
	const changeExport = ( patch ) => {
		change( { export: { ...form.export, ...patch } } );
		if ( false === patch.encrypt ) {
			setPassword( '' );
			setAgain( '' );
		}
	};

	/**
	 * Marks a refused control (ours or the options panel's).
	 *
	 * @param {string}      field  Field.
	 * @param {string}      reason checkSchedule() reason, or 'server'.
	 * @param {string}      said   The server's own text ('' = ours).
	 * @param {number|null} index  Refused replace pair.
	 */
	const refuse = ( field, reason, said = '', index = null ) => {
		if ( EXPORT_FIELDS.includes( field ) ) {
			if ( FOLDED_FIELDS.includes( field ) ) {
				setOpen( true );
			}
			setErrors( {
				[ field ]:
					( 'server' === reason && said ) ||
					fieldText( field, reason ),
				...( 'replace' === field ? { replaceIndex: index } : {} ),
			} );
			return;
		}
		const own = ownText( field, form );
		if ( own ) {
			setErrors( { [ field ]: ( 'server' === reason && said ) || own } );
			return;
		}
		setProblem(
			said || __( 'The schedule could not be saved.', 'lw-migration' )
		);
	};

	const save = async ( event ) => {
		event.preventDefault();
		setLoggedOut( false );
		const found = checkSchedule( form, password, again );
		if ( found ) {
			refuse( found.field, found.reason, '', found.index ?? null );
			return;
		}
		setSaving( true );
		// A destination deleted since is dropped (the server refuses its id).
		const body = toRequest(
			withKnownDestinations( form, cloud.data?.items ?? null ),
			keepsPassword( form, password, again ) ? '' : password
		);
		try {
			onSaved(
				item
					? await api.updateSchedule( item.id, body )
					: await api.createSchedule( body )
			);
		} catch ( e ) {
			setSaving( false );
			if ( isLoggedOut( e ) ) {
				setLoggedOut( true );
				return;
			}
			const field = invalidField( e );
			if ( field ) {
				refuse( field, 'server', refusalText( e, '' ) );
				return;
			}
			setProblem( errorMessage( e ) );
		}
	};

	const names = form.destinations.map( ( chosen ) => {
		const known = destinations.find( ( d ) => d.id === chosen.id );
		return known ? known.name || typeText( known ) : '';
	} );

	return (
		<Modal
			title={
				item
					? __( 'Edit schedule', 'lw-migration' )
					: __( 'New schedule', 'lw-migration' )
			}
			onRequestClose={ onClose }
			focusOnMount="firstContentElement"
			className="lw-modal lw-sheet lw-schedule-editor"
			overlayClassName="lw-sheet-overlay"
		>
			<form
				ref={ formRef }
				className="lw-sheet__form"
				onSubmit={ save }
				noValidate
			>
				<LiveSentence
					form={ form }
					names={ names.filter( Boolean ) }
					timezone={ timezone }
				/>
				<div className="lw-sheet__body">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'lw-migration' ) }
						maxLength={ 100 }
						value={ form.title }
						onChange={ ( title ) => change( { title } ) }
						{ ...marked(
							errors.title,
							__( 'For example: Nightly backup.', 'lw-migration' )
						) }
					/>
					<TimingFields
						form={ form }
						timezone={ timezone }
						errors={ errors }
						onChange={ change }
					/>
					{ NETWORK && (
						<ScopeFields
							value={ form.export }
							error={ errors.sites || '' }
							onChange={ changeExport }
						/>
					) }
					<WhatSection
						form={ form }
						errors={ errors }
						onExport={ changeExport }
						password={ password }
						again={ again }
						onPassword={ ( value ) => {
							setPassword( value );
							setErrors( {} );
						} }
						onAgain={ ( value ) => {
							setAgain( value );
							setErrors( {} );
						} }
						open={ open }
						onToggle={ () => setOpen( ! open ) }
					/>
					<WhereSection
						form={ form }
						errors={ errors }
						destinations={ destinations }
						loaded={ Boolean( cloud.data ) }
						onChange={ change }
					/>
					<NotifyFields
						notify={ form.notify }
						errors={ errors }
						onChange={ ( notify ) => change( { notify } ) }
						fallback={ fallback }
					/>
					{ loggedOut && <LoginNotice tab="schedules" /> }
					{ problem && (
						<Notice status="error" isDismissible={ false }>
							{ problem }
						</Notice>
					) }
				</div>
				<div className="lw-sheet__foot">
					<span className="lw-admin-switch">
						<FormToggle
							id="lw-schedule-enabled"
							checked={ form.enabled }
							onChange={ ( event ) =>
								change( { enabled: event.target.checked } )
							}
						/>
						<label htmlFor="lw-schedule-enabled">
							{ __( 'Runs by itself', 'lw-migration' ) }
						</label>
					</span>
					<span className="lw-sheet__spacer" />
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'lw-migration' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						type="submit"
						variant="primary"
						isBusy={ saving }
						disabled={ saving }
						accessibleWhenDisabled
					>
						{ __( 'Save schedule', 'lw-migration' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

/**
 * WordPress dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import LoginNotice from '../components/LoginNotice';
import { api } from '../data/api';
import { errorMessage, invalidField, isLoggedOut } from '../data/errors';
import { refusalText } from '../data/refusal';
import BackupStep from './BackupStep';
import { ICONS } from './ResetChoices';
import ResetLock from './ResetLock';
import Step from './Step';
import { lockMinutes, needsPhrase, stepsReady, titleOf } from './reset';

/**
 * What a failed start means for the steps: the field to mark with its text,
 * a text for the whole panel, the login notice, or the lock.
 *
 * @param {Object} e Rejection of api.startReset().
 * @return {Object} `{ field, text, loggedOut, locked }` (`locked`: minutes, 0 = no lock).
 */
function problemOf( e ) {
	const none = { field: '', text: '', loggedOut: false, locked: 0 };
	if ( isLoggedOut( e ) ) {
		return { ...none, loggedOut: true };
	}
	const field = invalidField( e );
	if ( 'password' === field || 'confirm' === field ) {
		const hints = {
			password: __( 'This is not your password.', 'lw-migration' ),
			confirm: __(
				'Type the site address exactly as shown.',
				'lw-migration'
			),
		};
		return { ...none, field, text: refusalText( e, hints[ field ] ) };
	}
	const minutes = lockMinutes( e );
	if ( minutes > 0 ) {
		return { ...none, locked: minutes };
	}
	// A refused reset, a busy site, an unusable backups folder, a refused
	// type or backup value: the server's own text, for the whole panel.
	return { ...none, text: errorMessage( e ) };
}

/**
 * @param {string} type    Reset type.
 * @param {Object} summary `GET /reset`.
 * @return {string} The note on the default theme's download ('' = none).
 */
function themeNote( type, summary ) {
	const source = summary.types?.themes?.target?.source;
	if ( ( 'themes' !== type && 'full' !== type ) || 'download' !== source ) {
		return '';
	}
	return (
		__(
			'The default theme is not installed: it is downloaded from wordpress.org first.',
			'lw-migration'
		) +
		' ' +
		__( 'This is the only network call of a reset.', 'lw-migration' )
	);
}

/**
 * @param {string} type Reset type.
 * @return {Object} What a choice starts with: the backup on, nothing typed.
 */
const fresh = ( type ) => ( { type, backup: true, phrase: '' } );

/**
 * "Before you start": the backup switch, the password and, for the database
 * and everything, the site address, as numbered steps that tick when done;
 * then the one destructive button. The password lives only here, is cleared
 * after every attempt and is kept when the choice changes. Another choice
 * starts over otherwise: the backup on again, the typed address and any
 * refusal gone (also when you come back). Too many wrong passwords lock the
 * start: the lock replaces the steps until its time passes.
 *
 * @param {Object}                      props
 * @param {string}                      props.type       Chosen reset type.
 * @param {Object}                      props.summary    `GET /reset`.
 * @param {boolean}                     props.busy       Another job runs on this site.
 * @param {(job: Object) => void}       props.onStart    Receives the started job.
 * @param {(starting: boolean) => void} props.onStarting Told when a start is sent and when it was refused.
 */
export default function ResetSteps( {
	type,
	summary,
	busy,
	onStart,
	onStarting = () => {},
} ) {
	const id = useInstanceId( ResetSteps, 'lw-reset-steps' );
	const [ password, setPassword ] = useState( '' );
	const [ choice, setChoice ] = useState( () => fresh( type ) );
	const [ starting, setStarting ] = useState( false );
	const [ problem, setProblem ] = useState( null );
	const [ locked, setLocked ] = useState( 0 );
	const passwordRef = useRef( null );
	const confirmRef = useRef( null );
	const alertRef = useRef( null );
	// Read for the choice on screen at once, so another choice never shows
	// the last one's switch or address, not even for one frame.
	const current = choice.type === type ? choice : fresh( type );
	const { backup, phrase } = current;
	const shown = problem?.type === type ? problem : null;
	const steps = stepsReady(
		{
			type,
			backup,
			backupReady: summary.backupReady,
			password,
			phrase,
		},
		summary
	);
	const why = busy
		? __( 'Another job is running.', 'lw-migration' )
		: steps.why;
	const enabled = ! starting && ! busy && ! locked && steps.ready;
	const note = themeNote( type, summary );
	// When the backup step itself says why the start is off, the note under
	// the button does not say it again; the button still points to it.
	const blocked = ! busy && ! steps.backup;
	const describedBy = blocked ? `${ id }-backup-error` : `${ id }-why`;

	// Another choice starts over: the last one's switch, address and
	// refusal are dropped for good, so coming back never restores them.
	useEffect( () => {
		setChoice( ( last ) => ( last.type === type ? last : fresh( type ) ) );
		setProblem( null );
	}, [ type ] );

	// The lock ends on its own once its time has passed (the server checks
	// again on the next attempt).
	useEffect( () => {
		if ( ! locked ) {
			return undefined;
		}
		const timer = setTimeout( () => setLocked( 0 ), locked * 60000 );
		return () => clearTimeout( timer );
	}, [ locked ] );

	// A refused start: focus the field in error, else the panel's message
	// (the login notice when the session ended).
	useEffect( () => {
		if ( ! problem ) {
			return;
		}
		const target = {
			password: passwordRef,
			confirm: confirmRef,
		}[ problem.field ];
		( target ?? alertRef ).current?.focus();
	}, [ problem ] );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! enabled ) {
			return;
		}
		setStarting( true );
		onStarting( true );
		setProblem( null );
		try {
			const job = await api.startReset( {
				type,
				password,
				backup,
				...( needsPhrase( type ) ? { confirm: phrase } : {} ),
			} );
			setPassword( '' );
			onStart( job );
		} catch ( e ) {
			const next = problemOf( e );
			setPassword( '' );
			setLocked( next.locked );
			setProblem( { ...next, type } );
			setStarting( false );
			onStarting( false );
		}
	};
	const fieldError = ( field ) =>
		shown && field === shown.field ? shown.text : '';
	const invalid = ( field ) =>
		fieldError( field )
			? {
					'aria-invalid': true,
					'aria-describedby': `${ id }-${ field }-error`,
				}
			: {};
	const errorLine = ( field ) =>
		fieldError( field ) && (
			<p
				id={ `${ id }-${ field }-error` }
				className="lw-reset-step__error"
			>
				{ fieldError( field ) }
			</p>
		);
	const wide = shown && shown.text && ! shown.field;

	if ( locked ) {
		return <ResetLock minutes={ locked } focusRef={ alertRef } />;
	}

	return (
		<form className="lw-reset-run" onSubmit={ submit }>
			<ol className="lw-reset-steps">
				<BackupStep
					backup={ backup }
					ready={ steps.backup }
					errorId={ `${ id }-backup-error` }
					onChange={ ( next ) =>
						setChoice( { ...current, backup: next } )
					}
				/>
				<Step number={ 2 } done={ steps.password }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						ref={ passwordRef }
						type="password"
						autoComplete="current-password"
						label={ __( 'Your password', 'lw-migration' ) }
						help={
							fieldError( 'password' )
								? undefined
								: __(
										'Checked once before starting, never stored.',
										'lw-migration'
									)
						}
						value={ password }
						onChange={ ( next ) => {
							setPassword( next );
							setProblem( null );
						} }
						{ ...invalid( 'password' ) }
					/>
					{ errorLine( 'password' ) }
				</Step>
				{ needsPhrase( type ) && (
					<Step number={ 3 } done={ steps.phrase }>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							ref={ confirmRef }
							autoComplete="off"
							spellCheck={ false }
							label={ createInterpolateElement(
								sprintf(
									/* translators: %s: the site address, e.g. example.com */
									__( 'Type %s to confirm', 'lw-migration' ),
									'<code />'
								),
								{ code: <code>{ summary.phrase }</code> }
							) }
							value={ phrase }
							onChange={ ( next ) => {
								setChoice( { ...current, phrase: next } );
								setProblem( null );
							} }
							{ ...invalid( 'confirm' ) }
						/>
						{ errorLine( 'confirm' ) }
					</Step>
				) }
			</ol>
			{ shown?.loggedOut && (
				<div tabIndex={ -1 } ref={ alertRef }>
					<LoginNotice tab="reset" />
				</div>
			) }
			{ wide && (
				<div role="alert" tabIndex={ -1 } ref={ alertRef }>
					<Callout tone="warning">{ shown.text }</Callout>
				</div>
			) }
			<div className="lw-reset-go">
				<Button
					__next40pxDefaultSize
					type="submit"
					variant="primary"
					isDestructive
					icon={ ICONS[ type ] }
					isBusy={ starting }
					disabled={ ! enabled }
					accessibleWhenDisabled
					aria-describedby={ why ? describedBy : undefined }
				>
					{ titleOf( type ) }
				</Button>
				{ blocked && (
					<p className="lw-reset-go__note">
						{ __(
							'Fill in the steps and the button turns on.',
							'lw-migration'
						) }
					</p>
				) }
				{ why && ! blocked && (
					<p id={ `${ id }-why` } className="lw-reset-go__note">
						{ why }
					</p>
				) }
				{ ! why && (
					<p className="lw-reset-go__note">
						{ __(
							'After starting, keep this tab open: the steps run from here.',
							'lw-migration'
						) }
					</p>
				) }
				{ note && <p className="lw-reset-go__note">{ note }</p> }
			</div>
		</form>
	);
}

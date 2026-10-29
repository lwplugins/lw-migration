/**
 * WordPress dependencies
 */
import { Button, CheckboxControl, TextControl } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import LoginNotice from '../components/LoginNotice';
import Section from '../components/Section';
import { api } from '../data/api';
import { errorMessage, invalidField, isLoggedOut } from '../data/errors';
import { formatDatetime } from '../data/format';
import { refusalText } from '../data/refusal';
import { httpWarning } from './LinkCard';
import {
	clock,
	codeAddress,
	codeLeft,
	codeOutcome,
	isHttp,
	looksLikeCode,
	normalizeCode,
} from './links';

const now = () => Date.now() / 1000;

/**
 * Seconds left until `expiresAt`, refreshed every second while above 0.
 *
 * @param {number} expiresAt Unix seconds (0 = none).
 * @return {number} Seconds left.
 */
function useSecondsLeft( expiresAt ) {
	const [ left, setLeft ] = useState( () => codeLeft( expiresAt, now() ) );
	useEffect( () => {
		setLeft( codeLeft( expiresAt, now() ) );
		if ( ! expiresAt ) {
			return undefined;
		}
		const timer = setInterval( () => {
			const next = codeLeft( expiresAt, now() );
			setLeft( next );
			if ( 0 === next ) {
				clearInterval( timer );
			}
		}, 1000 );
		return () => clearInterval( timer );
	}, [ expiresAt ] );
	return left;
}

/** How often the list is read again while a made code is open (to see it used). */
const USED_CHECK_MS = 10000;

/**
 * A failed request of the pairing: a logout is the shared login notice,
 * anything else an alert under the part.
 *
 * @param {Object}      props
 * @param {Object|null} props.error Rejection of request().
 */
function Problem( { error } ) {
	if ( ! error ) {
		return null;
	}
	if ( isLoggedOut( error ) ) {
		return <LoginNotice tab="connections" />;
	}
	return (
		<p className="lw-pairing__error" role="alert">
			{ errorMessage( error ) }
		</p>
	);
}

/**
 * How the code made here ended: '' while it is open (or the list has not
 * shown it yet), `used` when the list stopped showing it and an incoming
 * connection was added, `closed` when it went without one. While it is open,
 * the list is read again every few seconds.
 *
 * @param {Object|null} created  Answer of createCode, or null.
 * @param {Object|null} openCode The list's open code.
 * @param {number}      inCount  Incoming connections in the list.
 * @param {number}      left     Seconds left.
 * @param {() => void}  onChange Reads the list again.
 * @return {string} '', `used` or `closed`.
 */
function useCodeEnd( created, openCode, inCount, left, onChange ) {
	const [ ended, setEnded ] = useState( '' );
	const before = useRef( null );
	const expiresAt = created?.expiresAt ?? 0;
	const open = openCode?.expiresAt === expiresAt;
	useEffect( () => {
		setEnded( '' );
		before.current = null;
	}, [ expiresAt ] );
	useEffect( () => {
		if ( ! expiresAt ) {
			return;
		}
		if ( open ) {
			before.current = inCount;
		} else if ( null !== before.current && left > 0 ) {
			setEnded( codeOutcome( before.current, inCount ) );
		}
	}, [ expiresAt, open, inCount, left ] );
	const waiting = expiresAt > 0 && ! ended && left > 0;
	useEffect( () => {
		if ( ! waiting ) {
			return undefined;
		}
		const timer = setInterval( onChange, USED_CHECK_MS );
		return () => clearInterval( timer );
	}, [ waiting, onChange ] );
	return ended;
}

/**
 * The code just made: shown once, with Copy, its countdown and the warnings.
 *
 * @param {Object}     props
 * @param {Object}     props.created  Answer of createCode ({ code, expiresAt, insecure }).
 * @param {number}     props.left     Seconds left.
 * @param {string}     props.ended    '', `used` or `closed` (useCodeEnd()).
 * @param {boolean}    props.dropping The code is being cancelled.
 * @param {() => void} props.onDrop   Cancels the code.
 */
function MadeCode( { created, left, ended, dropping, onDrop } ) {
	const { createSuccessNotice } = useDispatch( noticesStore );
	const copyRef = useCopyToClipboard( created.code, () =>
		createSuccessNotice( __( 'Copied', 'lw-migration' ), {
			type: 'snackbar',
		} )
	);
	let status = __( 'This code expired.', 'lw-migration' );
	if ( 'used' === ended ) {
		status = __( 'This code was used: a site connected.', 'lw-migration' );
	} else if ( 'closed' === ended ) {
		status = __(
			'This code is no longer open (used, cancelled or replaced).',
			'lw-migration'
		);
	} else if ( left > 0 ) {
		status = sprintf(
			/* translators: %s: minutes and seconds left, e.g. 14:59. */
			__( 'Expires in %s', 'lw-migration' ),
			clock( left )
		);
	}

	return (
		<>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				className="lw-pairing__code"
				label={ __( 'Pairing code', 'lw-migration' ) }
				value={ created.code }
				readOnly
				autoComplete="off"
				spellCheck={ false }
				onChange={ () => {} }
				onFocus={ ( event ) => event.target.select() }
			/>
			<div className="lw-actions">
				<Button
					__next40pxDefaultSize
					ref={ copyRef }
					variant="primary"
					icon={ copy }
				>
					{ __( 'Copy', 'lw-migration' ) }
				</Button>
				{ ! ended && left > 0 && (
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						isBusy={ dropping }
						disabled={ dropping }
						accessibleWhenDisabled
						onClick={ onDrop }
					>
						{ __( 'Cancel the code', 'lw-migration' ) }
					</Button>
				) }
			</div>
			<p className="lw-pairing__left">{ status }</p>
			{ /* Said once when it ends, never every second. */ }
			<p className="screen-reader-text" aria-live="polite">
				{ ended || 0 === left ? status : '' }
			</p>
			<p className="lw-pairing__note">
				{ __(
					'Valid for 15 minutes, once. Whoever has it can connect to this site — share it only with yourself.',
					'lw-migration'
				) }
			</p>
			<p className="lw-pairing__note">
				{ __(
					"This site's admins get an e-mail when a site connects.",
					'lw-migration'
				) }
			</p>
			{ created.insecure && (
				<Callout tone="warning">{ httpWarning() }</Callout>
			) }
		</>
	);
}

/**
 * The site that receives or gives the content makes a code (push only when
 * ticked, user ruling U2).
 *
 * @param {Object}      props
 * @param {Object|null} props.openCode `{ expiresAt }` of a code made earlier, or null.
 * @param {number}      props.inCount  Incoming connections in the list.
 * @param {() => void}  props.onChange The list (and its open code) changed.
 */
function MakeCode( { openCode, inCount, onChange } ) {
	const [ pull, setPull ] = useState( true );
	const [ push, setPush ] = useState( false );
	const [ created, setCreated ] = useState( null );
	const [ working, setWorking ] = useState( '' );
	const [ error, setError ] = useState( null );
	const left = useSecondsLeft( created?.expiresAt ?? 0 );
	const ended = useCodeEnd( created, openCode, inCount, left, onChange );
	// The rights belong to the open code: fixed until it is used, expires or is cancelled.
	const codeOpen = created ? ! ended && left > 0 : Boolean( openCode );
	const allow = [
		...( pull ? [ 'pull' ] : [] ),
		...( push ? [ 'push' ] : [] ),
	];

	const make = async () => {
		setWorking( 'make' );
		setError( null );
		try {
			setCreated( await api.createCode( allow ) );
			onChange();
		} catch ( e ) {
			setError( e );
		}
		setWorking( '' );
	};
	const drop = async () => {
		setWorking( 'drop' );
		setError( null );
		try {
			await api.dropCode();
			setCreated( null );
			onChange();
		} catch ( e ) {
			setError( e );
		}
		setWorking( '' );
	};

	return (
		<div className="lw-pairing__part">
			<h4 className="lw-pairing__heading">
				{ __(
					'On the site that receives or gives the content:',
					'lw-migration'
				) }
			</h4>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'The other site may copy this site',
					'lw-migration'
				) }
				checked={ pull }
				disabled={ codeOpen }
				onChange={ setPull }
			/>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					"The other site may replace this site's content",
					'lw-migration'
				) }
				checked={ push }
				disabled={ codeOpen }
				onChange={ setPush }
			/>
			{ push && (
				<Callout tone="warning">
					{ __(
						'Only tick this for a site you will restore from, never for a site that must stay as it is.',
						'lw-migration'
					) }
				</Callout>
			) }
			<div className="lw-actions">
				<Button
					__next40pxDefaultSize
					variant={ created ? 'secondary' : 'primary' }
					isBusy={ 'make' === working }
					disabled={ ! allow.length || '' !== working }
					accessibleWhenDisabled
					onClick={ make }
				>
					{ __( 'Make a pairing code', 'lw-migration' ) }
				</Button>
			</div>
			<Problem error={ error } />
			{ created && (
				<MadeCode
					created={ created }
					left={ left }
					ended={ ended }
					dropping={ 'drop' === working }
					onDrop={ drop }
				/>
			) }
			{ ! created && openCode && (
				<>
					<p className="lw-pairing__note">
						{ sprintf(
							/* translators: %s: date and time. */
							__(
								'A code made earlier is open until %s. It is not shown again; make a new one if you need it.',
								'lw-migration'
							),
							formatDatetime( openCode.expiresAt )
						) }
					</p>
					<div className="lw-actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							isBusy={ 'drop' === working }
							disabled={ '' !== working }
							accessibleWhenDisabled
							onClick={ drop }
						>
							{ __( 'Cancel the code', 'lw-migration' ) }
						</Button>
					</div>
				</>
			) }
		</div>
	);
}

/**
 * The site that sends or brings the content types the other site's code.
 *
 * @param {Object}     props
 * @param {() => void} props.onChange The list changed.
 */
function EnterCode( { onChange } ) {
	const [ code, setCode ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const [ fieldError, setFieldError ] = useState( '' );
	const [ error, setError ] = useState( null );
	const field = useRef();
	const { createSuccessNotice } = useDispatch( noticesStore );
	// After a refusal the field is marked; focus goes there so it is read out.
	useEffect( () => {
		if ( fieldError ) {
			field.current?.focus();
		}
	}, [ fieldError ] );
	const http =
		isHttp( codeAddress( code ) ) || 'http:' === window.location.protocol;

	const connect = async () => {
		setAdding( true );
		setFieldError( '' );
		setError( null );
		try {
			const link = await api.addLink( normalizeCode( code ) );
			setCode( '' );
			createSuccessNotice(
				sprintf(
					/* translators: %s: name of the connected site. */
					__( 'Connected with %s.', 'lw-migration' ),
					link.name || link.address
				),
				{ type: 'snackbar' }
			);
			onChange();
		} catch ( e ) {
			if ( 'code' === invalidField( e ) ) {
				setFieldError(
					refusalText(
						e,
						__(
							"This is not a pairing code. Copy the whole code from the other site's Connections page.",
							'lw-migration'
						)
					)
				);
			} else {
				setError( e );
			}
		}
		setAdding( false );
	};

	return (
		<div className="lw-pairing__part">
			<h4 className="lw-pairing__heading">
				{ __(
					'On the site that sends or brings the content:',
					'lw-migration'
				) }
			</h4>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				className={ `lw-pairing__code ${
					fieldError ? 'is-invalid' : ''
				}` }
				ref={ field }
				label={ __( 'Pairing code', 'lw-migration' ) }
				value={ code }
				autoComplete="off"
				spellCheck={ false }
				onChange={ ( value ) => {
					setCode( value );
					setFieldError( '' );
				} }
				help={ fieldError || undefined }
				aria-invalid={ fieldError ? true : undefined }
			/>
			{ http && <Callout tone="warning">{ httpWarning() }</Callout> }
			<div className="lw-actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ adding }
					disabled={ adding || ! looksLikeCode( code ) }
					accessibleWhenDisabled
					onClick={ connect }
				>
					{ __( 'Connect', 'lw-migration' ) }
				</Button>
			</div>
			<Problem error={ error } />
		</div>
	);
}

/**
 * New connection: make a code here, or type the other site's code here.
 *
 * @param {Object}      props
 * @param {Object|null} props.openCode `{ expiresAt }` of a code made earlier, or null.
 * @param {number}      props.inCount  Incoming connections in the list.
 * @param {() => void}  props.onChange The list (or the open code) changed.
 */
export default function PairingSection( { openCode, inCount, onChange } ) {
	return (
		<Section
			title={ __( 'New connection', 'lw-migration' ) }
			description={ __(
				'Make a code on one site and type it on the other. A code connects one site, once, within 15 minutes.',
				'lw-migration'
			) }
		>
			<div className="lw-pairing">
				<MakeCode
					openCode={ openCode }
					inCount={ inCount }
					onChange={ onChange }
				/>
				<EnterCode onChange={ onChange } />
			</div>
		</Section>
	);
}

/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import Section from '../components/Section';
import { api } from '../data/api';
import { NETWORK } from '../data/boot';
import { errorMessage, invalidField, isLoggedOut } from '../data/errors';
import { refusalText } from '../data/refusal';
import useRemote from '../data/useRemote';
import AdvancedOptions from '../export/AdvancedOptions';
import CompositionBar from '../export/CompositionBar';
import ContentChoices from '../export/ContentChoices';
import DestinationTiles from '../export/DestinationTiles';
import FileOptions from '../export/FileOptions';
import SummaryDock from '../export/SummaryDock';
import { excludesByName } from '../export/choices';
import { composition, estimateStatus } from '../export/estimate';
import useEstimate from '../export/useEstimate';
import '../export/export.scss';
import SiteScope from '../network/SiteScope';
import { scopeRequest } from '../network/sitePicker';
import { DEFAULTS, buildRequest, checkForm } from './exportRequest';

/** The refusable fields that live in the folded Advanced card. */
const ADVANCED_FIELDS = [
	'replace',
	'excludeFiles',
	'excludeTables',
	'includeTables',
];

/**
 * The text shown at a refused field (ours, by field and reason); a field the
 * form has no control for gets none, and the server's text goes to the notice.
 *
 * @param {string} field  Field name.
 * @param {string} reason checkForm() reason, or 'server'.
 * @return {string} Text, or ''.
 */
export function fieldText( field, reason ) {
	switch ( field ) {
		case 'replace':
			return __(
				'Fill in both sides of every pair, or remove the pair.',
				'lw-migration'
			);
		case 'excludeFiles':
			return __(
				'Each line must name a folder or file inside wp-content, for example: uploads/videos',
				'lw-migration'
			);
		case 'excludeTables':
		case 'includeTables':
			return __(
				'Check this list: one table name per line.',
				'lw-migration'
			);
		case 'sites':
			return __( 'Choose at least one site.', 'lw-migration' );
		case 'password':
			if ( 'empty' === reason ) {
				return __(
					'Enter a password, or turn off the protection.',
					'lw-migration'
				);
			}
			return 'mismatch' === reason
				? __( 'The two passwords differ.', 'lw-migration' )
				: __( 'This password is not accepted.', 'lw-migration' );
		default:
			return '';
	}
}

/**
 * "Back up this site" (on a network "Back up this network": the whole
 * network or the chosen sites), in the order of the page: what goes in, to
 * scale (the size estimate, asked again while it counts), the choices with
 * their sizes, where it goes, the file, the folded Advanced card, and the
 * summary dock with Start. The password lives only in this component's state
 * and goes into the start request; the view unmounts once the job screen
 * takes over.
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    Another job runs.
 * @param {(job: Object) => void} props.onStart Receives the new job.
 */
export default function ExportView( { busy, onStart } ) {
	const [ options, setOptions ] = useState( DEFAULTS );
	const [ password, setPassword ] = useState( '' );
	const [ again, setAgain ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ starting, setStarting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ loggedOut, setLoggedOut ] = useState( false );
	const [ errors, setErrors ] = useState( {} );
	const optionsRef = useRef();
	const destinations = useRemote( api.destinations );
	const { estimate, error: estimateError } = useEstimate();

	// Move to the first control a refusal named (scrolls it into view).
	useEffect( () => {
		if ( Object.keys( errors ).length ) {
			optionsRef.current
				?.querySelector( '[data-refused="true"]' )
				?.focus();
		}
	}, [ errors ] );

	/**
	 * Marks the refused control and opens the Advanced card when it is there.
	 *
	 * @param {string}      field  Field name.
	 * @param {string}      reason checkForm() reason, or 'server'.
	 * @param {number|null} index  Refused pair.
	 * @param {string}      said   The server's own text (reason 'server') when
	 *                             it names the exact problem; '' = our text.
	 * @return {boolean} Whether the form has a control for the field.
	 */
	const refuse = ( field, reason, index = null, said = '' ) => {
		const text = fieldText( field, reason );
		if ( ! text ) {
			return false;
		}
		if ( ADVANCED_FIELDS.includes( field ) ) {
			setOpen( true );
		}
		setErrors( {
			[ field ]: ( 'server' === reason && said ) || text,
			...( 'replace' === field ? { replaceIndex: index } : {} ),
		} );
		setError( __( 'Check the marked setting.', 'lw-migration' ) );
		return true;
	};

	const start = async () => {
		setError( '' );
		setLoggedOut( false );
		setErrors( {} );
		const problem = checkForm( options, password, again );
		if ( problem ) {
			refuse( problem.field, problem.reason, problem.index );
			return;
		}
		setStarting( true );
		try {
			onStart( await api.startJob( buildRequest( options, password ) ) );
		} catch ( e ) {
			setStarting( false );
			if ( isLoggedOut( e ) ) {
				setLoggedOut( true );
				return;
			}
			const field = invalidField( e );
			if ( ! refuse( field, 'server', null, refusalText( e, '' ) ) ) {
				setError( errorMessage( e ) );
			}
		}
	};

	const change = ( patch ) => {
		setOptions( ( prev ) => ( { ...prev, ...patch } ) );
		// Switching the protection off forgets the password typed for it.
		if ( false === patch.encrypt ) {
			setPassword( '' );
			setAgain( '' );
		}
		setErrors( {} );
		setError( '' );
	};
	const noSite =
		Boolean( NETWORK ) &&
		null === scopeRequest( options.scope, options.sites );
	const status = estimateStatus( estimate, estimateError );
	const comp = composition( estimate, options );
	const chosen = useMemo(
		() =>
			( destinations.data?.items || [] ).filter( ( d ) =>
				( options.destinations || [] ).includes( d.id )
			),
		[ destinations.data, options.destinations ]
	);

	return (
		<div className="lw-export">
			<section className="lw-export__lead">
				<h2 className="lw-export__title">
					{ NETWORK
						? __( 'Back up this network', 'lw-migration' )
						: __( 'Back up this site', 'lw-migration' ) }
				</h2>
				<p className="lw-export__intro">
					{ NETWORK
						? __(
								'One .wpress file of the whole network or the sites you choose. With it you can restore them, or move them to another WordPress.',
								'lw-migration'
							)
						: __(
								'One .wpress file of the whole site. With it you can restore this site, or move it to another WordPress.',
								'lw-migration'
							) }
				</p>
			</section>
			<div ref={ optionsRef } className="lw-export__body">
				{ NETWORK && (
					<Section
						title={ __( 'Which sites', 'lw-migration' ) }
						description={ sprintf(
							/* translators: %d: number of sites in this network. */
							_n(
								'This network has %d site.',
								'This network has %d sites.',
								NETWORK.siteCount,
								'lw-migration'
							),
							NETWORK.siteCount
						) }
						className="lw-scope-card"
					>
						<SiteScope
							value={ options }
							onChange={ change }
							error={ errors.sites || '' }
							tab="export"
							idBase="lw-export-picker"
						/>
					</Section>
				) }
				<CompositionBar
					status={ status }
					estimate={ estimate }
					comp={ comp }
					compression={ options.compression }
					byName={ excludesByName( options ) }
					chosen={ 'sites' === options.scope }
				/>
				<ContentChoices
					options={ options }
					onChange={ change }
					estimate={ estimate }
					status={ status }
				/>
				<DestinationTiles
					value={ options }
					onChange={ change }
					remote={ destinations }
				/>
				<FileOptions
					options={ options }
					onChange={ change }
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
					errors={ errors }
				/>
				<AdvancedOptions
					open={ open }
					onToggle={ () => setOpen( ! open ) }
					options={ options }
					onChange={ change }
					errors={ errors }
				/>
			</div>
			<SummaryDock
				comp={ comp }
				options={ options }
				wholeNetwork={
					'network' === estimate?.scope && 'sites' === options.scope
				}
				chosen={ chosen }
				starting={ starting }
				disabled={ busy || starting || noSite }
				describedBy={ noSite ? 'lw-export-picker-hint' : undefined }
				onStart={ start }
			>
				{ loggedOut && (
					<LoginNotice tab="export" className="lw-export-notice" />
				) }
				{ error && (
					<Notice
						className="lw-export-notice"
						status="error"
						isDismissible={ false }
					>
						{ error }
					</Notice>
				) }
			</SummaryDock>
		</div>
	);
}

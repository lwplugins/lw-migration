/**
 * WordPress dependencies
 */
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, download, link, upload } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { awaitsConnect, choiceText } from '../backups/cloudStatus';
import { DestinationLabel } from '../components/DestinationIcon';
import LoginNotice from '../components/LoginNotice';
import { SkeletonBlock, SkeletonRegion } from '../components/skeleton';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import useRemote from '../data/useRemote';
import { DEFAULTS, buildRequest } from '../views/exportRequest';

/**
 * The destinations a quick backup is uploaded to at first: those that
 * work and need no account.
 *
 * @param {Object[]} items Destination views (GET /destinations).
 * @return {string[]} Ids.
 */
export const defaultChoice = ( items ) =>
	items
		.filter( ( d ) => 'ok' === d.status && ! awaitsConnect( d ) )
		.map( ( d ) => d.id );

/**
 * One quick action card.
 *
 * @param {Object}  props
 * @param {Object}  props.icon     Icon (from the WordPress icon set).
 * @param {string}  props.title    Title.
 * @param {string}  props.text     Lead.
 * @param {Element} props.children Body and buttons.
 */
function Action( { icon, title, text, children } ) {
	return (
		<div className="lw-overview-action">
			<h3>
				<Icon icon={ icon } size={ 24 } />
				{ title }
			</h3>
			<p>{ text }</p>
			{ children }
		</div>
	);
}

/**
 * "Back up now": the Backup section's own request with its defaults
 * (exportRequest.js), uploaded to the ticked destinations (chips; one whose
 * account is not connected is offered disabled, as in the Backup section).
 * The started job goes to the app, which opens its job screen.
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    A job runs.
 * @param {boolean}               props.network The page runs on a network.
 * @param {(job: Object) => void} props.onStart Receives the started job.
 */
function BackupNow( { busy, network, onStart } ) {
	const remote = useRemote( api.destinations );
	const items = useMemo( () => remote.data?.items || [], [ remote.data ] );
	const [ picked, setPicked ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const [ failure, setFailure ] = useState( null );
	const chosen = picked ?? defaultChoice( items );
	const waiting = items.filter( awaitsConnect );
	// A click before the list arrives would upload nowhere; a failed list
	// lets the backup start (it stays in the backups folder, as it says).
	const loading = remote.isLoading && ! remote.data;

	const start = async () => {
		setStarting( true );
		setFailure( null );
		try {
			onStart(
				await api.startJob(
					buildRequest(
						{
							...DEFAULTS,
							destinations: chosen.filter( ( id ) =>
								items.some( ( d ) => d.id === id )
							),
						},
						''
					)
				)
			);
		} catch ( e ) {
			setStarting( false );
			setFailure( e );
		}
	};

	let chips = null;
	if ( loading ) {
		chips = (
			<SkeletonRegion
				label={ __( 'Loading the destinations…', 'lw-migration' ) }
			>
				<SkeletonBlock height={ 28 } width="70%" round />
			</SkeletonRegion>
		);
	} else if ( remote.error && isLoggedOut( remote.error ) ) {
		// The start failure below says the same; show it once.
		chips =
			failure && isLoggedOut( failure ) ? null : (
				<LoginNotice tab="overview" />
			);
	} else if ( remote.error ) {
		// The Backup section's own words for the same failure.
		chips = (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'The destinations could not be loaded. The backup stays in the backups folder.',
					'lw-migration'
				) }
			</Notice>
		);
	} else if ( items.length ) {
		chips = (
			<>
				<div
					className="lw-overview-chips"
					role="group"
					aria-label={ __( 'Upload it to', 'lw-migration' ) }
				>
					{ items.map( ( d ) => (
						<CheckboxControl
							key={ d.id }
							__nextHasNoMarginBottom
							className={ `lw-overview-chip ${
								awaitsConnect( d ) ? 'is-off' : ''
							}` }
							label={ <DestinationLabel item={ d } /> }
							disabled={ awaitsConnect( d ) }
							checked={ chosen.includes( d.id ) }
							onChange={ ( on ) =>
								setPicked(
									on
										? [ ...chosen, d.id ]
										: chosen.filter( ( id ) => id !== d.id )
								)
							}
						/>
					) ) }
				</div>
				{ waiting.map( ( d ) => (
					<p key={ d.id } className="lw-overview-action__note">
						{ `${ d.name }: ${ choiceText( d ) }` }
					</p>
				) ) }
			</>
		);
	}

	let text;
	if ( items.length ) {
		text = network
			? __(
					'A full backup of this network. When it is done, it is also uploaded to:',
					'lw-migration'
				)
			: __(
					'A full backup of this site. When it is done, it is also uploaded to:',
					'lw-migration'
				);
	} else {
		text = network
			? __(
					'A full backup of this network, kept in the protected backups folder.',
					'lw-migration'
				)
			: __(
					'A full backup of this site, kept in the protected backups folder.',
					'lw-migration'
				);
	}

	return (
		<Action
			icon={ download }
			title={ __( 'Back up now', 'lw-migration' ) }
			text={ text }
		>
			{ chips }
			{ failure && isLoggedOut( failure ) && (
				<LoginNotice tab="overview" />
			) }
			{ failure && ! isLoggedOut( failure ) && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage( failure ) }
				</Notice>
			) }
			<div className="lw-overview-action__buttons">
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ starting }
					disabled={ busy || starting || loading }
					accessibleWhenDisabled
					onClick={ start }
				>
					{ __( 'Start backup', 'lw-migration' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					href="#export"
				>
					{ __( 'Backup settings', 'lw-migration' ) }
				</Button>
			</div>
		</Action>
	);
}

/**
 * What "Move between sites" says: with a ready connection this site uses
 * (not one the other site uses on it), what it can do with that site.
 *
 * @param {Object[]} links GET /overview `links`.
 * @return {{text: string, button: string}} Texts.
 */
export function moveText( links ) {
	const usable = links.find( ( l ) => 'ready' === l.status && ! l.incoming );
	if ( ! usable ) {
		return {
			text: __(
				'Pair this site with another one of yours, then copy or send a whole site in one step.',
				'lw-migration'
			),
			button: __( 'Pair a site', 'lw-migration' ),
		};
	}
	const texts = {
		pull: sprintf(
			/* translators: %s: a connected site's host, e.g. dev.example.com. */
			__( 'Copy %s here in one step.', 'lw-migration' ),
			usable.host
		),
		push: sprintf(
			/* translators: %s: a connected site's host, e.g. dev.example.com. */
			__( 'Send this site to %s in one step.', 'lw-migration' ),
			usable.host
		),
		both: sprintf(
			/* translators: %s: a connected site's host, e.g. dev.example.com. */
			__(
				'Copy %s here, or send this site there, in one step.',
				'lw-migration'
			),
			usable.host
		),
	};
	return {
		text: texts[ usable.direction ] || texts.both,
		button: __( 'Open Connections', 'lw-migration' ),
	};
}

/**
 * The three common actions: back up now, restore, move between sites (the
 * last one only on a single site: connections join two single sites).
 *
 * @param {Object}                props
 * @param {boolean}               props.busy    A job runs.
 * @param {Object[]|null}         props.links   GET /overview `links`.
 * @param {boolean}               props.network The page runs on a network.
 * @param {(job: Object) => void} props.onStart Receives the started job.
 */
export default function QuickActions( { busy, links, network, onStart } ) {
	const move = null === links ? null : moveText( links );
	return (
		<section
			className={ `lw-overview-actions ${ move ? '' : 'has-two' }` }
			aria-label={ __( 'Quick actions', 'lw-migration' ) }
		>
			<BackupNow busy={ busy } network={ network } onStart={ onStart } />
			<Action
				icon={ upload }
				title={ __( 'Restore', 'lw-migration' ) }
				text={ __(
					'From a backup here, an uploaded .wpress file, an address or a destination. You can make a safety backup first.',
					'lw-migration'
				) }
			>
				<div className="lw-overview-action__buttons">
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href="#import"
					>
						{ __( 'Choose a backup', 'lw-migration' ) }
					</Button>
				</div>
			</Action>
			{ move && (
				<Action
					icon={ link }
					title={ __( 'Move between sites', 'lw-migration' ) }
					text={ move.text }
				>
					<div className="lw-overview-action__buttons">
						<Button
							__next40pxDefaultSize
							variant="secondary"
							href="#connections"
						>
							{ move.button }
						</Button>
					</div>
				</Action>
			) }
		</section>
	);
}

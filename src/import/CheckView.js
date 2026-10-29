/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { caution } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import Section from '../components/Section';
import ToggleRow from '../components/ToggleRow';
import { errorMessage, isLoggedOut } from '../data/errors';
import { formatBytes } from '../data/format';
import SiteAddressRows from '../network/SiteAddressRows';
import { answerSites, initialAddresses } from '../network/siteAddresses';
import CheckActions from './CheckActions';
import CompareTable from './CompareTable';
import ReplaceGrid from './ReplaceGrid';
import { confirmHost, isLow, neededSpace, safetyFits } from './check';
import { addressNote, loginText } from './notes';
import './restore.scss';

const hasFreeSpace = ( summary ) =>
	Number.isFinite( summary.diskFree ) && null !== summary.diskFree;

/**
 * The safety switch's line: what it backs up, or why it cannot be made.
 *
 * @param {Object}  summary Confirm summary.
 * @param {boolean} fits    safetyFits().
 * @param {string}  there   The connected site of a push ('' = this site).
 * @return {string} Text.
 */
function safetyHelp( summary, fits, there ) {
	if ( ! fits ) {
		return sprintf(
			/* translators: 1: space the restore needs with the safety backup, 2: free space. */
			__(
				'There is not enough free space for it: with it the restore needs about %1$s, but only %2$s is free.',
				'lw-migration'
			),
			formatBytes( neededSpace( summary, true ) ),
			formatBytes( summary.diskFree )
		);
	}
	const size = formatBytes( summary.safetyBytes );
	const free = hasFreeSpace( summary )
		? ' ' +
			sprintf(
				/* translators: %s: free disk space. */
				__( '%s is free.', 'lw-migration' ),
				formatBytes( summary.diskFree )
			)
		: '';
	return (
		( there
			? sprintf(
					/* translators: %s: estimated size. */
					__(
						'About %s. It appears under Backups on that site; restore it there if something goes wrong.',
						'lw-migration'
					),
					size
				)
			: sprintf(
					/* translators: %s: estimated size. */
					__(
						'A backup of the current database, about %s. If something goes wrong, restore it from Backups.',
						'lw-migration'
					),
					size
				) ) + free
	);
}

/**
 * "Check before restoring" (step 2): the archive next to this site, the
 * address that is replaced, the network's sites, what gets replaced, the
 * safety-backup switch (off, with the reason, when it does not fit), what
 * happens to the logins, then Back, the typed confirmation and "Restore
 * now". Nothing of the job's own message or progress is shown here: the
 * header walk leaves them behind while the job waits.
 *
 * A push asks the same on the pushing site, about the other site
 * (`target`): it restores there.
 *
 * @param {Object}                                                    props
 * @param {Object}                                                    props.summary   JobView confirm summary.
 * @param {Object|null}                                               props.target    The connected site a push restores (null = this site).
 * @param {boolean}                                                   props.back      Back to the source step (Restore section), else Cancel.
 * @param {(safetyBackup: boolean, sites: Object) => Promise<Object>} props.onConfirm Starts the restore; `sites` = changed addresses by index ({} = none).
 * @param {() => Promise<void>}                                       props.onDecline Declines (cancels the job), then goes back.
 */
export default function CheckView( {
	summary,
	target = null,
	back = false,
	onConfirm,
	onDecline,
} ) {
	const there = target ? target.name || target.address : '';
	const fits = safetyFits( summary );
	const [ safety, setSafety ] = useState(
		() => summary.safetyBackup && fits
	);
	const [ addresses, setAddresses ] = useState( () =>
		initialAddresses( summary )
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const note = addressNote( summary );
	const login = loginText( summary, Boolean( there ) );

	const act = async ( work ) => {
		setBusy( true );
		setError( '' );
		try {
			await work();
		} catch ( e ) {
			// A logout is shown once, by the job screen's login notice.
			setError( isLoggedOut( e ) ? '' : errorMessage( e ) );
			setBusy( false );
		}
	};

	let description = summary.network
		? __(
				"Read from the archive's headers. Nothing on this network has changed yet.",
				'lw-migration'
			)
		: __(
				"Read from the archive's headers. Nothing on this site has changed yet.",
				'lw-migration'
			);
	if ( there ) {
		description = sprintf(
			/* translators: %s: name of the connected site. */
			__(
				'Nothing on %s has changed yet. The restore there starts when you confirm.',
				'lw-migration'
			),
			there
		);
	}

	return (
		<Section
			className="lw-check"
			title={
				there
					? sprintf(
							/* translators: %s: name of the connected site. */
							__( 'Restore this site on %s?', 'lw-migration' ),
							there
						)
					: __( 'Check before restoring', 'lw-migration' )
			}
			description={ description }
		>
			<CompareTable
				summary={ summary }
				safety={ safety }
				there={ Boolean( there ) }
			/>
			{ note && (
				<Callout>
					{ sprintf(
						/* translators: 1: old site address (host), 2: new site address (host). */
						__(
							'The old address (%1$s) is replaced with %2$s everywhere, in serialized data too, with the right lengths.',
							'lw-migration'
						),
						note.from,
						note.to
					) }
				</Callout>
			) }
			{ null !== summary.subsite && undefined !== summary.subsite && (
				<Callout>
					{ sprintf(
						/* translators: %d: site ID in the network the archive came from. */
						__(
							'Site %d of a network becomes this site.',
							'lw-migration'
						),
						summary.subsite
					) }
				</Callout>
			) }
			{ summary.network && (
				<SiteAddressRows
					summary={ summary }
					addresses={ addresses }
					onChange={ ( index, value ) => {
						setAddresses( ( prev ) => ( {
							...prev,
							[ index ]: value,
						} ) );
						setError( '' );
					} }
				/>
			) }
			<ReplaceGrid summary={ summary } />
			<ToggleRow
				inline
				title={ __( 'Safety backup first', 'lw-migration' ) }
				help={ safetyHelp( summary, fits, there ) }
				checked={ safety }
				disabled={ ! fits }
				onChange={ setSafety }
			/>
			{ isLow( summary, safety ) && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: estimated space needed, 2: free space. */
						__(
							'The restore may need about %1$s of disk space, but only %2$s is free.',
							'lw-migration'
						),
						formatBytes( neededSpace( summary, safety ) ),
						formatBytes( summary.diskFree )
					) }
				</Notice>
			) }
			{ login && (
				<Callout tone="warning" icon={ caution }>
					{ login }
				</Callout>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<CheckActions
				host={ confirmHost( summary ) }
				back={ back }
				there={ Boolean( there ) }
				busy={ busy }
				onBack={ () => act( onDecline ) }
				onRestore={ () =>
					act( () =>
						onConfirm( safety, answerSites( summary, addresses ) )
					)
				}
			/>
		</Section>
	);
}

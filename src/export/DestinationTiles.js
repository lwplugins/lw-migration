/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { awaitsConnect, whereText } from '../backups/cloudStatus';
import DestinationIcon, {
	chipParts,
	presetOf,
} from '../components/DestinationIcon';
import Section from '../components/Section';
import ToggleRow from '../components/ToggleRow';
import { SkeletonBlock, SkeletonRegion } from '../components/skeleton';

/**
 * One destination as a tile with checkbox semantics: icon, type, its own
 * name and where it uploads to; one that waits for its account is offered
 * disabled with the reason.
 *
 * @param {Object}                props
 * @param {Object}                props.item     Destination view.
 * @param {boolean}               props.checked  Ticked.
 * @param {(on: boolean) => void} props.onChange Receives the new state.
 */
function Tile( { item, checked, onChange } ) {
	const id = useInstanceId( Tile, 'lw-dest-choice' );
	const off = awaitsConnect( item );
	const { type, name } = chipParts( item );
	const where = whereText( item );
	return (
		<label
			htmlFor={ id }
			className={ [
				'lw-dest-choice',
				checked && 'is-checked',
				off && 'is-off',
			]
				.filter( Boolean )
				.join( ' ' ) }
		>
			<input
				id={ id }
				type="checkbox"
				className="lw-dest-choice__input"
				checked={ checked }
				disabled={ off }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<span className="lw-dest-tile lw-dest-choice__icon">
				<DestinationIcon
					type={ item.type }
					preset={ presetOf( item ) }
					size={ 20 }
				/>
			</span>
			<span className="lw-dest-choice__text">
				<span className="lw-dest-choice__type">{ type }</span>
				{ ( name || where ) && (
					<span className="lw-dest-choice__where">
						{ [ name, where ].filter( Boolean ).join( ' \u00B7 ' ) }
					</span>
				) }
			</span>
			<span className="lw-dest-choice__tick" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="11" height="11">
					<path
						fill="none"
						stroke="currentColor"
						strokeWidth="3"
						strokeLinecap="round"
						strokeLinejoin="round"
						d="m5 12 5 5 9-10"
					/>
				</svg>
			</span>
			{ off && (
				<span className="lw-dest-choice__why">
					{ __(
						'Connect it first in Destinations.',
						'lw-migration'
					) }
				</span>
			) }
		</label>
	);
}

/**
 * The "Keep the local copy" help: what happens to the file in the backups
 * folder with these choices.
 *
 * @param {number}  chosen    Ticked destinations.
 * @param {boolean} keepLocal The local copy stays.
 * @return {string} Text.
 */
export function keepText( chosen, keepLocal ) {
	if ( ! chosen ) {
		return __(
			'Without a destination the backup stays in the backups folder.',
			'lw-migration'
		);
	}
	return keepLocal
		? __( 'The file stays in the backups folder too.', 'lw-migration' )
		: __(
				'Once every upload succeeded the local file is deleted. If one fails, it stays.',
				'lw-migration'
			);
}

/**
 * "Where it goes": the stored destinations (the same list the Upload…
 * dialog of the Backups list offers) as tiles, none ticked at first, and the
 * local copy switch, which only means something with a destination ticked.
 *
 * @param {Object}                  props
 * @param {Object}                  props.value    Form state (`destinations`, `keepLocal`).
 * @param {(patch: Object) => void} props.onChange Receives a form-state patch.
 * @param {Object}                  props.remote   useRemote( api.destinations ) state.
 */
export default function DestinationTiles( { value, onChange, remote } ) {
	const items = remote.data?.items || [];
	const chosen = value.destinations || [];
	const keepLocal = ! chosen.length || false !== value.keepLocal;

	let body;
	if ( remote.isLoading && ! remote.data ) {
		body = (
			<SkeletonRegion
				label={ __( 'Loading the destinations…', 'lw-migration' ) }
				className="lw-dest-choices"
			>
				<SkeletonBlock height={ 58 } />
				<SkeletonBlock height={ 58 } />
			</SkeletonRegion>
		);
	} else if ( remote.error || 0 === items.length ) {
		body = (
			<p className="lw-admin-row__help">
				{ remote.error
					? __(
							'The destinations could not be loaded. The backup stays in the backups folder.',
							'lw-migration'
						)
					: __(
							'No destination yet. Add one in the Destinations section to send backups to the cloud.',
							'lw-migration'
						) }
			</p>
		);
	} else {
		body = (
			<div
				className="lw-dest-choices"
				role="group"
				aria-label={ __( 'Destinations', 'lw-migration' ) }
			>
				{ items.map( ( d ) => (
					<Tile
						key={ d.id }
						item={ d }
						checked={ chosen.includes( d.id ) }
						onChange={ ( on ) =>
							onChange( {
								destinations: on
									? [ ...chosen, d.id ]
									: chosen.filter( ( id ) => id !== d.id ),
							} )
						}
					/>
				) ) }
			</div>
		);
	}

	return (
		<Section
			title={ __( 'Where it goes', 'lw-migration' ) }
			description={ __(
				'The backup is made in the backups folder first, then uploaded to the destinations you tick.',
				'lw-migration'
			) }
			className="lw-destinations-card"
		>
			{ body }
			<ToggleRow
				inline
				title={ __( 'Keep the local copy', 'lw-migration' ) }
				help={ keepText( chosen.length, keepLocal ) }
				checked={ keepLocal }
				disabled={ ! chosen.length }
				onChange={ ( on ) => onChange( { keepLocal: on } ) }
			/>
		</Section>
	);
}

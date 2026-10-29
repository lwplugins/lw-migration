/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import DestinationIcon from '../components/DestinationIcon';
import StatusBadge from '../components/StatusBadge';
import DestinationEditor from './DestinationEditor';
import { isOAuth } from './oauth';

/** The draft's field ids: `lw-destination-new-<key>` (an edit form may be open too). */
export const DRAFT_PREFIX = 'lw-destination-new';

/**
 * @param {boolean} oauth A cloud account (its place is known after the sign-in).
 * @return {string} The line before there is a place to show.
 */
const emptyWhere = ( oauth ) =>
	oauth
		? __( 'The folder is created after you sign in.', 'lw-migration' )
		: __(
				'Fill in the connection: you see here where the backups go.',
				'lw-migration'
			);

/**
 * A destination being added, as a card below the shelf: the tile's icon,
 * the name in the title (the place's name to start with), "New", and the
 * live line of where the backups will go; the settings under it. A server
 * or a storage saves and the view checks the connection on its new card;
 * a cloud account saves and goes to the provider's sign-in. Only one draft
 * is open: it lives in the page until it is saved or discarded. When an
 * account was saved but its connect failed, Discard becomes Close: the
 * destination stays, and its card connects it.
 *
 * @param {Object}                                               props
 * @param {Object}                                               props.type      Schema type.
 * @param {{preset: string, name: string}}                       props.draft     The picked tile: S3 provider and name.
 * @param {(preset: string) => void}                             props.onPreset  The provider changed in the form.
 * @param {(item: Object, result: {connected: boolean}) => void} props.onSaved   Receives the saved view.
 * @param {() => void}                                           props.onDiscard Closes it, nothing stored.
 */
export default function DraftCard( {
	type,
	draft,
	onPreset,
	onSaved,
	onDiscard,
} ) {
	const oauth = isOAuth( type );
	return (
		<section
			className="lw-dest is-draft"
			aria-label={ __( 'Setting up a new destination', 'lw-migration' ) }
		>
			<DestinationEditor
				mode="new"
				type={ type }
				preset={ draft.preset ?? '' }
				startName={ draft.name }
				idPrefix={ DRAFT_PREFIX }
				cancelLabel={ __( 'Discard', 'lw-migration' ) }
				keptLabel={ __( 'Close', 'lw-migration' ) }
				keptNote={ __(
					'The destination is saved; you can connect it from its card.',
					'lw-migration'
				) }
				footNote={
					oauth
						? ''
						: __(
								"You see the result of the connection on this card; if the server's key has to be confirmed, it asks here.",
								'lw-migration'
							)
				}
				onPreset={ onPreset }
				onSaved={ onSaved }
				onCancel={ onDiscard }
				renderHead={ ( { nameInput, where, preset } ) => (
					<div className="lw-dest__row is-head">
						<span className="lw-dest__tile">
							<DestinationIcon
								type={ type.type }
								preset={ preset }
								size={ 22 }
							/>
						</span>
						<div className="lw-dest__main">
							<div className="lw-dest__title">
								{ nameInput }
								<StatusBadge status="info">
									{ __( 'New', 'lw-migration' ) }
								</StatusBadge>
							</div>
							<p
								className={ `lw-dest__where ${
									where ? 'is-live' : 'is-empty'
								}` }
								aria-live="polite"
							>
								{ where || emptyWhere( oauth ) }
							</p>
						</div>
					</div>
				) }
			/>
		</section>
	);
}

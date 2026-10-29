/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import DestinationIcon, { presetOf } from '../components/DestinationIcon';
import Section from '../components/Section';
import StatusBadge from '../components/StatusBadge';
import { formatDatetime } from '../data/format';
import { statusLabel, statusTone } from '../destinations/destinations';

/**
 * A destination's state pill: the Destinations section's own words; a
 * fingerprint that waits (an attention item names it) says so.
 *
 * @param {Object}   item      GET /overview `destinations[]` row.
 * @param {Object[]} attention GET /overview `attention`.
 * @return {{tone: string, label: string}} Pill.
 */
export function destinationPill( item, attention ) {
	const confirm = attention.some(
		( a ) =>
			a.destination === item.id &&
			( 'hostKey' === a.kind || 'certificate' === a.kind )
	);
	if ( confirm ) {
		return {
			tone: 'warning',
			label: __( 'Confirm the fingerprint', 'lw-migration' ),
		};
	}
	// Overview `pending` = never checked: the section's "new".
	const status = 'pending' === item.status ? 'new' : item.status;
	return { tone: statusTone( status ), label: statusLabel( status ) };
}

/**
 * The line under a destination: where, the last upload, how many backups.
 *
 * @param {Object} item Row.
 * @return {string} Text.
 */
export function destinationMeta( item ) {
	const parts = [];
	if ( item.where ) {
		parts.push( item.where );
	}
	parts.push(
		item.lastUpload
			? sprintf(
					/* translators: %s: day and time, e.g. today 03:12. */
					__( 'last upload %s', 'lw-migration' ),
					formatDatetime( item.lastUpload )
				)
			: __( 'no upload yet', 'lw-migration' )
	);
	if ( item.count > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of backups there. */
				_n( '%d backup', '%d backups', item.count, 'lw-migration' ),
				item.count
			)
		);
	}
	return parts.join( ' · ' );
}

/**
 * The stored destinations with their state (GET /overview `destinations`);
 * none yet: what one is for and the button that adds it.
 *
 * @param {Object}   props
 * @param {Object[]} props.items     Rows.
 * @param {Object[]} props.attention Attention items (fingerprint waits).
 */
export default function DestinationList( { items, attention } ) {
	return (
		<Section
			title={ __( 'Destinations', 'lw-migration' ) }
			description={ __( 'Where the backups go', 'lw-migration' ) }
			className="lw-overview-panel"
			actions={
				items.length > 0 && (
					<Button variant="link" href="#destinations">
						{ __( 'Add a destination', 'lw-migration' ) }
					</Button>
				)
			}
		>
			{ items.length ? (
				<ul className="lw-overview-list">
					{ items.map( ( item ) => {
						const pill = destinationPill( item, attention );
						return (
							<li key={ item.id }>
								<span className="lw-overview-list__icon">
									<DestinationIcon
										type={ item.type }
										preset={ presetOf( item ) }
									/>
								</span>
								<span className="lw-overview-list__text">
									<span className="lw-overview-list__name">
										{ item.name }
									</span>
									<span className="lw-overview-list__meta">
										{ destinationMeta( item ) }
									</span>
								</span>
								<StatusBadge status={ pill.tone }>
									{ pill.label }
								</StatusBadge>
							</li>
						);
					} ) }
				</ul>
			) : (
				<div className="lw-overview-empty">
					<p>
						{ __(
							'No destination yet: the backups stay on this server only. Add a cloud account or another server to keep a copy away from it.',
							'lw-migration'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href="#destinations"
					>
						{ __( 'Add a destination', 'lw-migration' ) }
					</Button>
				</div>
			) }
		</Section>
	);
}

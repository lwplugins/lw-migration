/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Icon, link as linkIcon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import StatusBadge from '../components/StatusBadge';
import { linkRights } from './status';

/**
 * A connection's state pill.
 *
 * @param {string} status ready | pending | broken.
 * @return {{tone: string, label: string}} Pill.
 */
export const linkPill = ( status ) =>
	( {
		ready: { tone: 'ok', label: __( 'Ready', 'lw-migration' ) },
		pending: { tone: 'info', label: __( 'Waiting', 'lw-migration' ) },
		broken: { tone: 'critical', label: __( 'Broken', 'lw-migration' ) },
	} )[ status ] || { tone: 'idle', label: status };

/**
 * The connected sites (GET /overview `links`, single sites only): what each
 * lets do and from which side, https or not, its state; none yet: the
 * pairing code that makes one.
 *
 * @param {Object}   props
 * @param {Object[]} props.items Rows.
 */
export default function LinkList( { items } ) {
	return (
		<Section
			title={ __( 'Connections', 'lw-migration' ) }
			className="lw-overview-panel"
			actions={
				items.length > 0 && (
					<Button variant="link" href="#connections">
						{ __( 'Pairing code', 'lw-migration' ) }
					</Button>
				)
			}
		>
			{ items.length ? (
				<ul className="lw-overview-list">
					{ items.map( ( item ) => {
						const pill = linkPill( item.status );
						return (
							<li key={ item.id }>
								<span className="lw-overview-list__icon">
									<Icon icon={ linkIcon } size={ 20 } />
								</span>
								<span className="lw-overview-list__text">
									<span className="lw-overview-list__name">
										{ item.host }
									</span>
									<span className="lw-overview-list__meta">
										{ [
											linkRights( item ),
											item.secure
												? 'https'
												: __(
														'http, unencrypted',
														'lw-migration'
													),
										]
											.filter( Boolean )
											.join( ' · ' ) }
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
							'No connected site yet. Pair this site with another one of yours to copy or send a whole site in one step.',
							'lw-migration'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						href="#connections"
					>
						{ __( 'Create a pairing code', 'lw-migration' ) }
					</Button>
				</div>
			) }
		</Section>
	);
}

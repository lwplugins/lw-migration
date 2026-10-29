/**
 * WordPress dependencies
 */
import { Notice, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, arrowRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import StatusBadge from '../components/StatusBadge';
import { editable, rowNote } from './siteAddresses';
import './network.scss';

/**
 * Old address -> new address of one archive site.
 *
 * @param {Object}  props
 * @param {Object}  props.site     One site of the summary.
 * @param {Element} props.children What the site becomes.
 */
function Row( { site, children } ) {
	return (
		<li className={ `lw-address ${ site.error ? 'is-invalid' : '' }` }>
			<span className="lw-address__from">
				{ site.from.url || __( 'unknown address', 'lw-migration' ) }
			</span>
			<Icon icon={ arrowRight } size={ 24 } />
			<span className="screen-reader-text">
				{ ' ' }
				{
					/* translators: read aloud between the old and the new site address (the arrow). */
					__( 'to', 'lw-migration' )
				}{ ' ' }
			</span>
			<div className="lw-address__to">{ children }</div>
		</li>
	);
}

/**
 * The sites of an archive restored into a network. A sites or single-site
 * archive gets one editable address per site, prefilled with the proposal,
 * the server's reason at a refused row and the answer's own problem above
 * the rows; a whole-network archive keeps its sites' addresses and IDs.
 *
 * @param {Object}                                 props
 * @param {Object}                                 props.summary   Confirm summary (with `network`).
 * @param {Object}                                 props.addresses Index => typed address.
 * @param {(index: number, value: string) => void} props.onChange  Receives a typed address.
 */
export default function SiteAddressRows( { summary, addresses, onChange } ) {
	const network = summary.network;
	const open = editable( summary );

	return (
		<div className="lw-addresses">
			<h4 className="lw-addresses__title">
				{ open
					? __( 'Where each site goes', 'lw-migration' )
					: __(
							'The sites of this network archive',
							'lw-migration'
						) }
			</h4>
			{ network.error && (
				<Notice status="error" isDismissible={ false }>
					{ network.error }
				</Notice>
			) }
			<ul className="lw-addresses__list">
				{ network.sites.map( ( site ) => {
					if ( ! open ) {
						return (
							<Row key={ site.index } site={ site }>
								<span className="lw-address__kept">
									{ site.address }
								</span>
								<StatusBadge status="idle">
									{ sprintf(
										/* translators: %d: site ID, the same in the archive and on this network. */
										__(
											'Keeps its ID (%d)',
											'lw-migration'
										),
										site.from.blogId
									) }
								</StatusBadge>
							</Row>
						);
					}
					const id = `lw-address-${ site.index }`;
					const value = addresses[ site.index ] ?? '';
					const note = rowNote( site, value );
					return (
						<Row key={ site.index } site={ site }>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								id={ id }
								type="url"
								label={ __( 'New address', 'lw-migration' ) }
								value={ value }
								aria-invalid={ site.error ? true : undefined }
								aria-describedby={
									site.error ? `${ id }-error` : undefined
								}
								onChange={ ( text ) =>
									onChange( site.index, text )
								}
							/>
							{ site.error && (
								<p
									id={ `${ id }-error` }
									className="lw-address__error"
								>
									{ site.error }
								</p>
							) }
							{ note && ! site.error && (
								<StatusBadge
									status={
										null === site.existing
											? 'info'
											: 'warning'
									}
								>
									{ note }
								</StatusBadge>
							) }
						</Row>
					);
				} ) }
			</ul>
			{ network.unlisted.length > 0 && (
				<p className="lw-addresses__unlisted">
					{ sprintf(
						/* translators: %s: list of site addresses */
						__(
							'Not in the archive: %s. These sites stay on the network marked as deleted (Network Admin > Sites), with their tables and files kept.',
							'lw-migration'
						),
						network.unlisted
							.map( ( site ) => site.url )
							.join( ', ' )
					) }
				</p>
			) }
		</div>
	);
}

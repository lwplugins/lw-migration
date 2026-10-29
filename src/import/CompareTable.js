/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, arrowRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatBytes, formatDatetime } from '../data/format';
import { hostOf } from './check';
import { factRows } from './compare';

/**
 * The middle cell of a row: an arrow where the value changes, "=" where it
 * stays, nothing where it is not known or nothing is compared.
 *
 * @param {Object} props
 * @param {string} props.state same|changed|unknown|info.
 */
function Marker( { state } ) {
	if ( 'changed' === state ) {
		return (
			<>
				<Icon icon={ arrowRight } size={ 20 } />
				<span className="screen-reader-text">
					{ __( 'changes to', 'lw-migration' ) }
				</span>
			</>
		);
	}
	if ( 'same' === state ) {
		return (
			<>
				<span aria-hidden="true">=</span>
				<span className="screen-reader-text">
					{ __( 'same as', 'lw-migration' ) }
				</span>
			</>
		);
	}
	return null;
}

/**
 * The archive on the left, this site (or the connected site of a push) on
 * the right: their addresses in large, then the facts row by row, the
 * changed ones highlighted.
 *
 * @param {Object}  props
 * @param {Object}  props.summary Confirm summary.
 * @param {boolean} props.safety  The safety-backup switch (the space row).
 * @param {boolean} props.there   A push: the right side is the connected site.
 */
export default function CompareTable( { summary, safety, there } ) {
	const made = [
		summary.createdAt ? formatDatetime( summary.createdAt ) : '',
		formatBytes( summary.archiveSize ),
	]
		.filter( Boolean )
		.join( ' · ' );
	return (
		<div className="lw-compare">
			<div className="lw-compare__sides">
				<div className="lw-compare__side">
					<h4 className="lw-compare__label">
						{ __( 'In the archive', 'lw-migration' ) }
					</h4>
					<p className="lw-compare__host">
						{ hostOf( summary.from ) ||
							__( 'unknown address', 'lw-migration' ) }
					</p>
					<p className="lw-compare__hint">{ made }</p>
				</div>
				<span className="lw-compare__arrow" aria-hidden="true">
					<Icon icon={ arrowRight } size={ 28 } />
				</span>
				<div className="lw-compare__side">
					<h4 className="lw-compare__label">
						{ there
							? __( 'Goes there', 'lw-migration' )
							: __( 'Goes here', 'lw-migration' ) }
					</h4>
					<p className="lw-compare__host">{ hostOf( summary.to ) }</p>
					<p className="lw-compare__hint">
						{ there
							? __(
									"in place of that site's content",
									'lw-migration'
								)
							: __(
									'in place of the current content',
									'lw-migration'
								) }
					</p>
				</div>
			</div>
			<table className="lw-facts-table">
				<caption className="screen-reader-text">
					{ there
						? __(
								'The archive next to the connected site',
								'lw-migration'
							)
						: __(
								'The archive next to this site',
								'lw-migration'
							) }
				</caption>
				<thead>
					<tr>
						<th scope="col" className="screen-reader-text">
							{ __( 'Fact', 'lw-migration' ) }
						</th>
						<th scope="col" className="screen-reader-text">
							{ __( 'In the archive', 'lw-migration' ) }
						</th>
						<th scope="col" className="screen-reader-text">
							{ __( 'Comparison', 'lw-migration' ) }
						</th>
						<th scope="col" className="screen-reader-text">
							{ there
								? __( 'On that site', 'lw-migration' )
								: __( 'On this site', 'lw-migration' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ factRows( summary, safety ).map( ( fact ) => (
						<tr
							key={ fact.id }
							className={ `is-${ fact.state }` }
							data-fact={ fact.id }
						>
							<th scope="row">{ fact.label }</th>
							<td className="lw-facts-table__value">
								{ fact.archive }
							</td>
							<td className="lw-facts-table__marker">
								<Marker state={ fact.state } />
							</td>
							<td
								className={ `lw-facts-table__value ${
									fact.warn ? 'is-warn' : ''
								}` }
							>
								{ fact.site }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

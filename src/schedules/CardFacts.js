/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, envelope } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import DestinationIcon, {
	presetOf,
	typeText,
} from '../components/DestinationIcon';
import StatusBadge from '../components/StatusBadge';
import { formatBytes } from '../data/format';
import { stripBars, stripSummary } from './history';
import { parts, scopeLabel } from './what';
import {
	describeNotify,
	retentionLimits,
	statusLabel,
	statusTone,
} from './timing';
import { relativeIn } from './when';

/**
 * "Next": the next run large, how long until it, the three after it. The
 * times are the server's (`upcoming`).
 *
 * @param {Object}                 props
 * @param {Object}                 props.item  Schedule item.
 * @param {number}                 props.now   Unix seconds.
 * @param {(ts: number) => string} props.write runWriter().
 */
export function NextFact( { item, now, write } ) {
	const [ next, ...after ] = item.enabled ? item.upcoming || [] : [];
	let body;
	if ( ! item.enabled ) {
		body = (
			<p className="lw-fact__sub">
				{ __( 'It runs only when you start it.', 'lw-migration' ) }
			</p>
		);
	} else if ( ! next ) {
		body = (
			<p className="lw-fact__sub">
				{ __( 'Switched on, but no run is planned', 'lw-migration' ) }
			</p>
		);
	} else {
		body = (
			<>
				<p className="lw-fact__next">{ write( next ) }</p>
				<p className="lw-fact__sub">{ relativeIn( next, now ) }</p>
				{ after.length > 0 && (
					<ul
						className="lw-fact__after"
						aria-label={ __( 'The runs after it', 'lw-migration' ) }
					>
						{ after.slice( 0, 3 ).map( ( ts ) => (
							<li key={ ts }>{ write( ts ) }</li>
						) ) }
					</ul>
				) }
			</>
		);
	}
	return (
		<div className="lw-fact">
			<h4 className="lw-fact__title">{ __( 'Next', 'lw-migration' ) }</h4>
			{ body }
		</div>
	);
}

/**
 * "Last runs": the strip of the last 14 runs, then the newest one and, when
 * it ended with one, its error (the log's text, redacted by the server).
 *
 * @param {Object}                 props
 * @param {Object}                 props.item  Schedule item.
 * @param {(ts: number) => string} props.write runWriter().
 */
export function RunsFact( { item, write } ) {
	const last = item.lastRun;
	const history = item.history || [];
	return (
		<div className="lw-fact">
			<h4 className="lw-fact__title">
				{ __( 'Last runs', 'lw-migration' ) }
			</h4>
			{ history.length > 0 && (
				<ol className="lw-strip" aria-label={ stripSummary( history ) }>
					{ stripBars( history, write ).map( ( bar ) => (
						<li
							key={ bar.key }
							className={ `is-${ bar.tone }` }
							title={ bar.label }
						>
							<span className="screen-reader-text">
								{ bar.label }
							</span>
						</li>
					) ) }
				</ol>
			) }
			{ last ? (
				<>
					<p className="lw-fact__last">
						<StatusBadge status={ statusTone( last.status ) }>
							{ statusLabel( last.status ) }
						</StatusBadge>
						<span>
							{ write( last.endedAt ) }
							{ last.size > 0 &&
								` · ${ formatBytes( last.size ) }` }
						</span>
					</p>
					{ last.error && (
						<p className="lw-fact__error">{ last.error }</p>
					) }
				</>
			) : (
				<p className="lw-fact__sub">
					{ __( 'Not run yet', 'lw-migration' ) }
				</p>
			) }
		</div>
	);
}

/**
 * "What and where": the parts in the backup, each place it goes with what
 * it keeps there, and the e-mail.
 *
 * @param {Object}        props
 * @param {Object}        props.item         Schedule item.
 * @param {Object[]|null} props.destinations Destination views (null while unknown).
 */
export function ContentsFact( { item, destinations } ) {
	const chosen = item.destinations || [];
	const local = 0 === chosen.length || false !== item.keepLocal;
	const scope = scopeLabel( item.export || {} );
	return (
		<div className="lw-fact">
			<h4 className="lw-fact__title">
				{ __( 'What and where', 'lw-migration' ) }
			</h4>
			{ scope && <p className="lw-fact__scope">{ scope }</p> }
			<ul className="lw-parts">
				{ parts().map( ( part ) => {
					const out = Boolean( item.export?.[ part.flag ] );
					return (
						<li key={ part.key } className={ out ? 'is-out' : '' }>
							{ part.label }
							{ out && (
								<span className="screen-reader-text">
									{ __( '(left out)', 'lw-migration' ) }
								</span>
							) }
						</li>
					);
				} ) }
			</ul>
			<ul className="lw-places">
				{ local && (
					<li>
						<DestinationIcon icon="server" size={ 16 } />
						<span>
							{ __( 'Here, on this server', 'lw-migration' ) }
							<span className="lw-places__keep">
								{ ` · ${ retentionLimits( item.retention ).join(
									', '
								) }` }
							</span>
						</span>
					</li>
				) }
				{ chosen.map( ( d ) => {
					const known = destinations?.find( ( x ) => x.id === d.id );
					if ( ! known ) {
						return destinations ? (
							<li key={ d.id } className="is-gone">
								<span className="lw-places__gap" />
								<span>
									{ __(
										'A deleted destination (its runs skip it)',
										'lw-migration'
									) }
								</span>
							</li>
						) : null;
					}
					return (
						<li key={ d.id }>
							<DestinationIcon
								type={ known.type }
								preset={ presetOf( known ) }
								size={ 16 }
							/>
							<span>
								{ known.name || typeText( known ) }
								<span className="lw-places__keep">
									{ ` · ${ retentionLimits( {
										bytes: 0,
										...d.retention,
									} ).join( ', ' ) }` }
								</span>
							</span>
						</li>
					);
				} ) }
			</ul>
			<p className="lw-fact__mail">
				<Icon icon={ envelope } size={ 18 } />
				<span>{ describeNotify( item.notify ) }</span>
			</p>
		</div>
	);
}

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, caution, lock } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatBytes, formatNumber } from '../data/format';
import { percentOf, tabOf } from '../job/phases';
import { headline, runningText } from './status';

const RADIUS = 42;
const CIRCLE = 2 * Math.PI * RADIUS;

/**
 * The ring: days with a backup among the last 14 (GET /overview `days`).
 *
 * @param {Object}  props
 * @param {number}  props.covered Days with a backup.
 * @param {number}  props.of      Days counted.
 * @param {boolean} props.warn    Draw it in the warning colour.
 */
function Ring( { covered, of, warn } ) {
	const share = of > 0 ? Math.min( 1, covered / of ) : 0;
	return (
		<div className="lw-overview-status__ring">
			<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
				<circle
					className="lw-overview-ring__track"
					cx="50"
					cy="50"
					r={ RADIUS }
				/>
				{ share > 0 && (
					<circle
						className={ `lw-overview-ring__value ${
							warn ? 'is-warning' : ''
						}` }
						cx="50"
						cy="50"
						r={ RADIUS }
						strokeDasharray={ CIRCLE }
						strokeDashoffset={ CIRCLE * ( 1 - share ) }
						transform="rotate(-90 50 50)"
					/>
				) }
				<text
					className="lw-overview-ring__count"
					x="50"
					y="49"
					textAnchor="middle"
				>
					{ `${ covered }/${ of }` }
				</text>
				<text
					className="lw-overview-ring__label"
					x="50"
					y="65"
					textAnchor="middle"
				>
					{ __( 'days saved', 'lw-migration' ) }
				</text>
			</svg>
			<span className="screen-reader-text">
				{ sprintf(
					/* translators: 1: days with a backup (the count); 2: days counted (14). */
					_n(
						'%1$d of the last %2$d days has a backup.',
						'%1$d of the last %2$d days have a backup.',
						covered,
						'lw-migration'
					),
					covered,
					of
				) }
			</span>
		</div>
	);
}

/**
 * The running job's line: what runs, how far, and the link to it. Not a
 * live region: the percent changes on every tick and would be read out
 * each time.
 *
 * @param {Object}      props
 * @param {Object|null} props.job     App's job (live), or null.
 * @param {Object|null} props.running GET /overview `running`.
 */
function Running( { job, running } ) {
	const source = job || running;
	if ( ! source ) {
		return null;
	}
	const percent = job
		? percentOf( job )
		: Math.round( ( Number( running.progress ) || 0 ) * 100 );
	return (
		<p className="lw-overview-status__running">
			<span className="lw-overview-status__dot" aria-hidden="true" />
			<span>
				{ sprintf(
					/* translators: 1: what runs, e.g. A backup is running; 2: percent done. */
					__( '%1$s: %2$d%% done.', 'lw-migration' ),
					runningText( source.type ),
					percent
				) }{ ' ' }
				<a href={ `#${ tabOf( source ) }` }>
					{ __( 'Open it', 'lw-migration' ) }
				</a>
			</span>
		</p>
	);
}

/**
 * The first block: the ring of covered days, one sentence on how the site
 * is backed up, its lead (where the newest backup is), the facts of that
 * backup that are known, the backups folder's state and a running job.
 *
 * @param {Object}      props
 * @param {Object}      props.data    GET /overview.
 * @param {Object|null} props.job     App's running job, or null.
 * @param {Object|null} props.running GET /overview `running` while App says a job runs, else null.
 * @param {number}      props.now     Unix seconds.
 * @param {boolean}     props.network The page runs on a network.
 */
export default function StatusBlock( { data, job, running, now, network } ) {
	const text = headline( data, now, network );
	const last = data.last;
	const warn = [ 'failed', 'partial', 'late', 'stale', 'folder' ].includes(
		text.kind
	);
	const facts = [];
	if ( last && null !== last.size ) {
		facts.push(
			<span key="size">
				{ __( 'Size', 'lw-migration' ) }{ ' ' }
				<b className="lw-overview__num">{ formatBytes( last.size ) }</b>
			</span>
		);
	}
	if ( last && null !== last.files ) {
		facts.push(
			<span key="files">
				{ __( 'Files', 'lw-migration' ) }{ ' ' }
				<b className="lw-overview__num">
					{ formatNumber( last.files ) }
				</b>
			</span>
		);
	}
	if ( last && null !== last.dbTables ) {
		facts.push(
			<span key="tables">
				{ __( 'Database', 'lw-migration' ) }{ ' ' }
				<b className="lw-overview__num">
					{ sprintf(
						/* translators: %s: number of database tables. */
						_n(
							'%s table',
							'%s tables',
							last.dbTables,
							'lw-migration'
						),
						formatNumber( last.dbTables )
					) }
				</b>
			</span>
		);
	}
	facts.push(
		data.folder.protected ? (
			<span key="folder" className="lw-overview-status__safe">
				<Icon icon={ lock } size={ 18 } />
				{ __( 'Backups folder protected', 'lw-migration' ) }
			</span>
		) : (
			<span key="folder" className="lw-overview-status__unsafe">
				<Icon icon={ caution } size={ 18 } />
				{ __( 'Backups folder needs a look', 'lw-migration' ) }
			</span>
		)
	);

	return (
		<section
			className={ `lw-overview-status is-${ text.kind }` }
			aria-labelledby="lw-overview-status-title"
		>
			<Ring
				covered={ data.days.covered }
				of={ data.days.of }
				warn={ warn }
			/>
			<div className="lw-overview-status__text">
				<h2 id="lw-overview-status-title">{ text.title }</h2>
				<p className="lw-overview-status__lead">{ text.lead }</p>
				<Running job={ job } running={ running } />
				<div className="lw-overview-status__facts">{ facts }</div>
			</div>
		</section>
	);
}

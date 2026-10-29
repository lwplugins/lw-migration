/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { cloudStateLabel, notAllUploaded } from '../backups/cloudStatus';
import StatusBadge from '../components/StatusBadge';

const TONE = { ok: 'ok', failed: 'critical', skipped: 'warning' };

/**
 * Each destination of a backup or cloud job (job view `result.cloud`) with
 * its state and, for a failure, the reason; a download says where it went.
 * A finished job whose uploads did not all succeed says so in its own words
 * (spec 21.1: partly done; the local copy then stays).
 *
 * @param {Object} props
 * @param {Object} props.job Job view.
 */
export default function CloudResult( { job } ) {
	const rows = job.result?.cloud || [];
	const done = 'done' === job.status;
	const fetched =
		'cloud' === job.type && 'fetch' === job.result?.action && done;
	if ( 0 === rows.length && ! fetched ) {
		return null;
	}

	return (
		<div className="lw-result__cloud">
			{ fetched && (
				<p className="lw-result__detail">
					{ sprintf(
						/* translators: %s: backup file name. */
						__( '%s is now in the backups list.', 'lw-migration' ),
						job.result.backup?.name || ''
					) }
				</p>
			) }
			{ done && notAllUploaded( rows ) && (
				<Notice status="warning" isDismissible={ false }>
					{ 'export' === job.type
						? __(
								'Partly done: not every upload succeeded, so the local copy stays.',
								'lw-migration'
							)
						: __(
								'Partly done: not every upload succeeded.',
								'lw-migration'
							) }
				</Notice>
			) }
			{ rows.length > 0 && (
				<ul className="lw-result__list">
					{ rows.map( ( row ) => (
						<li key={ row.id }>
							<StatusBadge
								status={ TONE[ row.status ] || 'idle' }
							>
								{ cloudStateLabel( row.status ) }
							</StatusBadge>{ ' ' }
							{ row.name || row.id }
							{ row.message && `: ${ row.message }` }
							{ row.pruned > 0 &&
								` · ${ sprintf(
									/* translators: %d: number of old backups removed. */
									__( '%d old removed', 'lw-migration' ),
									row.pruned
								) }` }
						</li>
					) ) }
				</ul>
			) }
			{ 'export' === job.type && false === job.result?.backup?.local && (
				<p className="lw-result__detail">
					{ __(
						'The local copy was deleted: every upload succeeded.',
						'lw-migration'
					) }
				</p>
			) }
		</div>
	);
}

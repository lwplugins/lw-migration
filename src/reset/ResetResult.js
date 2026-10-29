/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { needsReload, resultLines } from './reset';
import { dropInNote } from './resetFacts';

/**
 * What a finished reset did: the lines of its parts, the backup taken before
 * it (in Backups), the drop-in notice, and after a database or full reset a
 * Reload (this page still shows menus and notices of what was removed).
 *
 * @param {Object}     props
 * @param {Object}     props.job     Finished reset job view.
 * @param {() => void} props.onClose Leaves the job screen (to Backups).
 */
export default function ResetResult( { job, onClose } ) {
	const result = job.result || {};
	const done = 'done' === job.status;
	const note = dropInNote( result.dropIns );

	return (
		<>
			{ done &&
				resultLines( result ).map( ( line ) => (
					<p key={ line } className="lw-result__title">
						{ line }
					</p>
				) ) }
			{ result.backup && (
				<p className="lw-result__detail">
					{ createInterpolateElement(
						sprintf(
							/* translators: %s: backup file name. */
							__( 'Backup before the reset: %s', 'lw-migration' ),
							'<a />'
						),
						{
							a: (
								<a href="#backups" onClick={ onClose }>
									{ result.backup.name }
								</a>
							),
						}
					) }
				</p>
			) }
			{ done && note && <p className="lw-result__detail">{ note }</p> }
			{ done && needsReload( result ) && (
				<div className="lw-result__reload">
					<p className="lw-result__detail">
						{ __(
							'Reload the page to see the fresh site.',
							'lw-migration'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => window.location.reload() }
					>
						{ __( 'Reload', 'lw-migration' ) }
					</Button>
				</div>
			) }
		</>
	);
}

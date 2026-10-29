/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { download, upload } from '@wordpress/icons';

/**
 * The Backups section's head: what it lists, and the two ways to a new
 * backup here (upload a .wpress file in Restore, or make one in Backup).
 *
 * @param {Object}     props
 * @param {() => void} props.onUploadFile Opens Restore with the upload source.
 */
export default function BackupsLead( { onUploadFile } ) {
	return (
		<section className="lw-bk-lead">
			<div>
				<h2 className="lw-bk-lead__title">
					{ __( 'Backups', 'lw-migration' ) }
				</h2>
				<p className="lw-bk-lead__intro">
					{ __(
						'Every .wpress backup on this server, and where copies of them were uploaded.',
						'lw-migration'
					) }
				</p>
			</div>
			<div className="lw-bk-lead__actions">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					icon={ upload }
					onClick={ onUploadFile }
				>
					{ __( 'Upload a .wpress file', 'lw-migration' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					icon={ download }
					href="#export"
				>
					{ __( 'New backup', 'lw-migration' ) }
				</Button>
			</div>
		</section>
	);
}

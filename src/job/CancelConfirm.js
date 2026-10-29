/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * The cancel confirmation inside the job card: what cancelling leaves
 * behind, "Cancel the job" (focused when it opens) and "Keep going".
 *
 * @param {Object}     props
 * @param {string}     props.text      What cancelling leaves behind.
 * @param {Object}     props.confirm   useInlineConfirm() result.
 * @param {() => void} props.onConfirm Cancel the job.
 */
export default function CancelConfirm( { text, confirm, onConfirm } ) {
	return (
		// Escape inside it keeps the job going, like "Keep going".
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div className="lw-job__confirm" onKeyDown={ confirm.onKeyDown }>
			<p id="lw-job-confirm-text">{ text }</p>
			<div className="lw-actions">
				<Button
					ref={ confirm.confirmRef }
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					aria-describedby="lw-job-confirm-text"
					onClick={ onConfirm }
				>
					{ __( 'Cancel the job', 'lw-migration' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ confirm.close }
				>
					{ __( 'Keep going', 'lw-migration' ) }
				</Button>
			</div>
		</div>
	);
}

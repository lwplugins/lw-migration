/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, caution, copySmall, info } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { copyText } from './copy';

/** The line a server cron runs (silent when nothing is due). */
export const SERVER_COMMAND = 'wp lw-migration schedule run --due --quiet';

/**
 * Decision 18: WP-Cron switched off or not running — say what the server
 * should run instead, with a Copy button. A warning only when runs are
 * late; WP-Cron switched off with nothing late is information (a server
 * cron may run them).
 *
 * @param {Object} props
 * @param {Object} props.cron {disabled, late}.
 */
export default function CronNotice( { cron } ) {
	const code = useRef();
	const { createSuccessNotice, createWarningNotice } =
		useDispatch( noticesStore );
	if ( ! cron.disabled && ! cron.late ) {
		return null;
	}
	const copy = async () => {
		if (
			await copyText( SERVER_COMMAND, navigator.clipboard, code.current )
		) {
			createSuccessNotice( __( 'Command copied.', 'lw-migration' ), {
				type: 'snackbar',
			} );
			return;
		}
		createWarningNotice(
			__(
				'Copying is not allowed here: the command is selected, copy it with Ctrl+C (Cmd+C on a Mac).',
				'lw-migration'
			),
			{ type: 'snackbar' }
		);
	};

	return (
		<section
			className={ `lw-cron ${ cron.late ? 'is-late' : 'is-off' }` }
			aria-label={ __( 'WP-Cron', 'lw-migration' ) }
		>
			<Icon icon={ cron.late ? caution : info } size={ 24 } />
			<div className="lw-cron__text">
				<p className="lw-cron__lead">
					{ cron.late
						? __(
								'Scheduled backups are late: WP-Cron has not run on this site for a while. It runs only when someone visits the site.',
								'lw-migration'
							)
						: __(
								'WP-Cron is switched off on this site (DISABLE_WP_CRON), so schedules run only when the server starts them.',
								'lw-migration'
							) }
				</p>
				<p>
					{ __(
						'Let the server run this command every 5 minutes, in the folder of this site:',
						'lw-migration'
					) }
				</p>
				<div className="lw-cron__command">
					<code ref={ code }>{ SERVER_COMMAND }</code>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						icon={ copySmall }
						onClick={ copy }
					>
						{ __( 'Copy', 'lw-migration' ) }
					</Button>
				</div>
				<p className="lw-cron__note">
					{ __(
						'A server cron job that already runs WP-Cron for this site runs the schedules too.',
						'lw-migration'
					) }
				</p>
			</div>
		</section>
	);
}

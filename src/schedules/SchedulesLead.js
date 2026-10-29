/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { forwardRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

/**
 * The section's title, what schedules do, and New schedule (not while
 * loading).
 *
 * @param {Object}                    props
 * @param {((event: Object) => void)} props.onNew Opens the editor (null: no button).
 * @param {Object}                    ref         The New schedule button (focus goes back to it).
 */
function SchedulesLead( { onNew }, ref ) {
	return (
		<section className="lw-sched-lead">
			<div>
				<h2 className="lw-sched-lead__title">
					{ __( 'Scheduled backups', 'lw-migration' ) }
				</h2>
				<p className="lw-sched-lead__intro">
					{ __(
						'Backups that make themselves at the times you set. Each schedule keeps and removes only its own backups.',
						'lw-migration'
					) }
				</p>
			</div>
			{ onNew && (
				<Button
					__next40pxDefaultSize
					variant="primary"
					icon={ plus }
					onClick={ onNew }
					ref={ ref }
				>
					{ __( 'New schedule', 'lw-migration' ) }
				</Button>
			) }
		</section>
	);
}

export default forwardRef( SchedulesLead );

/**
 * WordPress dependencies
 */
// No stable number field in core yet.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import { __experimentalNumberControl as NumberControl } from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { whole } from '../schedules/ScheduleFields';
import { RETENTION_MAX } from '../schedules/scheduleForm';
import { GB, bytesFrom, gbShown } from '../views/retentionSize';

/**
 * What a destination keeps of the manual backups, as one sentence: "At most
 * [n] backups, [n] GB, [n] days." The values and their limits are those of
 * the schedule's RetentionFields; each input keeps its own name for screen
 * readers.
 *
 * @param {Object}                      props
 * @param {Object}                      props.retention {count, bytes, days}.
 * @param {(retention: Object) => void} props.onChange  Replaces it.
 * @param {string}                      props.id        Heading id (unique on the page).
 */
export default function KeepSentence( { retention, onChange, id } ) {
	const set = ( key, value ) => onChange( { ...retention, [ key ]: value } );
	const input = ( props ) => (
		<div className="lw-keep__field">
			<NumberControl
				__next40pxDefaultSize
				hideLabelFromVision
				min={ 0 }
				{ ...props }
			/>
		</div>
	);

	return (
		<div className="lw-keep" role="group" aria-labelledby={ id }>
			<h4 id={ id } className="lw-keep__title">
				{ __( 'Keeping manual backups there', 'lw-migration' ) }
			</h4>
			<div className="lw-keep__sentence">
				{ createInterpolateElement(
					/* translators: <count/>, <size/>, <days/>: number fields (backups, GB, days). */
					__(
						'At most <count/> backups, <size/> GB, <days/> days.',
						'lw-migration'
					),
					{
						count: input( {
							label: __( 'Number of backups', 'lw-migration' ),
							max: RETENTION_MAX.count,
							value: retention.count,
							onChange: ( value ) =>
								set(
									'count',
									whole( value, 0, RETENTION_MAX.count )
								),
						} ),
						size: input( {
							label: __( 'Total size in GB', 'lw-migration' ),
							max: RETENTION_MAX.bytes / GB,
							step: 0.01,
							spinFactor: 50,
							value: gbShown( retention.bytes ),
							onChange: ( value ) =>
								set(
									'bytes',
									bytesFrom(
										value,
										retention.bytes,
										RETENTION_MAX.bytes
									)
								),
						} ),
						days: input( {
							label: __( 'Days to keep', 'lw-migration' ),
							max: RETENTION_MAX.days,
							value: retention.days,
							onChange: ( value ) =>
								set(
									'days',
									whole( value, 0, RETENTION_MAX.days )
								),
						} ),
					}
				) }
			</div>
			<p className="lw-keep__help">
				{ __(
					'After a manual backup is uploaded here, the oldest manual backups of this site go once a limit is reached; the newest always stays. Scheduled backups follow their schedule. 0 means no limit.',
					'lw-migration'
				) }
			</p>
		</div>
	);
}

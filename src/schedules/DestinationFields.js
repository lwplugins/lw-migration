/**
 * WordPress dependencies
 */
// No stable number field in core yet.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	CheckboxControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { DestinationLabel } from '../components/DestinationIcon';
import ToggleRow from '../components/ToggleRow';
import { whereText } from '../backups/cloudStatus';
import { whole } from './ScheduleFields';
import { RETENTION_MAX } from './scheduleForm';

const DEFAULT_RETENTION = { count: 7, bytes: 0, days: 0 };

/**
 * The cloud part of a schedule's "Where" (spec 21.6): which destinations get its
 * backups, how many of this schedule's backups each keeps, and whether the
 * local copy stays (the switch shows once a destination is chosen).
 *
 * @param {Object}                  props
 * @param {Object[]}                props.destinations Destination views ([] while loading).
 * @param {boolean}                 props.loaded       The list is known (a deleted one can be told apart).
 * @param {Object}                  props.form         Schedule form.
 * @param {(patch: Object) => void} props.onChange     Merges into the form.
 */
export default function DestinationFields( {
	destinations,
	loaded,
	form,
	onChange,
} ) {
	const chosen = new Map( form.destinations.map( ( d ) => [ d.id, d ] ) );
	const gone = loaded
		? form.destinations.filter(
				( d ) => ! destinations.some( ( x ) => x.id === d.id )
			)
		: [];
	const toggle = ( id, on ) =>
		onChange( {
			destinations: on
				? [
						...form.destinations,
						{ id, retention: { ...DEFAULT_RETENTION } },
					]
				: form.destinations.filter( ( d ) => d.id !== id ),
		} );
	const setRetention = ( id, key, value ) =>
		onChange( {
			destinations: form.destinations.map( ( d ) =>
				d.id === id
					? { ...d, retention: { ...d.retention, [ key ]: value } }
					: d
			),
		} );

	if ( 0 === destinations.length && 0 === form.destinations.length ) {
		return null;
	}

	return (
		<div
			className="lw-options__group"
			role="group"
			aria-labelledby="lw-schedule-cloud"
		>
			<h5 id="lw-schedule-cloud" className="lw-options__title">
				{ __( 'Upload to', 'lw-migration' ) }
			</h5>
			<p className="lw-options__help">
				{ __(
					'After each run the backup goes to every destination checked here. One that fails does not stop the others; the run is then partly done and the local copy stays.',
					'lw-migration'
				) }
			</p>
			{ destinations.map( ( d ) => (
				<div
					key={ d.id }
					className={ `lw-schedule-cloud ${
						chosen.has( d.id ) ? 'is-chosen' : ''
					}` }
				>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ <DestinationLabel item={ d } /> }
						help={ whereText( d ) }
						checked={ chosen.has( d.id ) }
						onChange={ ( on ) => toggle( d.id, on ) }
					/>
					{ chosen.has( d.id ) && (
						<div className="lw-schedule-time">
							<NumberControl
								__next40pxDefaultSize
								label={ __(
									'Keep this many there',
									'lw-migration'
								) }
								min={ 0 }
								max={ RETENTION_MAX.count }
								value={ chosen.get( d.id ).retention.count }
								onChange={ ( v ) =>
									setRetention(
										d.id,
										'count',
										whole( v, 0, RETENTION_MAX.count )
									)
								}
							/>
							<NumberControl
								__next40pxDefaultSize
								label={ __(
									'Days to keep there',
									'lw-migration'
								) }
								min={ 0 }
								max={ RETENTION_MAX.days }
								value={ chosen.get( d.id ).retention.days }
								onChange={ ( v ) =>
									setRetention(
										d.id,
										'days',
										whole( v, 0, RETENTION_MAX.days )
									)
								}
							/>
						</div>
					) }
				</div>
			) ) }
			{ gone.length > 0 && (
				<p className="lw-options__help">
					{ __(
						'A destination of this schedule was deleted; its runs skip it. Saving the schedule drops it.',
						'lw-migration'
					) }
				</p>
			) }
			{ form.destinations.length > 0 && (
				<ToggleRow
					title={ __( 'Keep the local copy', 'lw-migration' ) }
					help={ __(
						'When off, the backup is deleted from this server once every upload succeeded.',
						'lw-migration'
					) }
					checked={ form.keepLocal }
					onChange={ ( keepLocal ) => onChange( { keepLocal } ) }
					onText={ __( 'Kept', 'lw-migration' ) }
					offText={ __(
						'Deleted after the uploads',
						'lw-migration'
					) }
				/>
			) }
		</div>
	);
}

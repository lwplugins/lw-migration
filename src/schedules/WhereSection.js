/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import DestinationFields from './DestinationFields';
import { RetentionFields } from './ScheduleFields';

/**
 * The editor's "Where": what stays on this server (the schedule's own
 * retention), then the destinations with what each keeps there.
 *
 * @param {Object}                  props
 * @param {Object}                  props.form         Form state.
 * @param {Object}                  props.errors       { field: text }.
 * @param {Object[]}                props.destinations Destination views ([] while loading).
 * @param {boolean}                 props.loaded       The destination list is known.
 * @param {(patch: Object) => void} props.onChange     Merges into the form.
 */
export default function WhereSection( {
	form,
	errors,
	destinations,
	loaded,
	onChange,
} ) {
	return (
		<div
			className="lw-options__group"
			role="group"
			aria-labelledby="lw-schedule-where"
		>
			<h4 id="lw-schedule-where" className="lw-options__title">
				{ __( 'Where', 'lw-migration' ) }
			</h4>
			<p className="lw-options__help">
				{ __(
					'Each place keeps its own copies of this schedule’s backups; the numbers say how many it keeps there.',
					'lw-migration'
				) }
			</p>
			<RetentionFields
				level={ 5 }
				title={ __( 'Here, on this server', 'lw-migration' ) }
				retention={ form.retention }
				errors={ errors }
				onChange={ ( retention ) => onChange( { retention } ) }
			/>
			<DestinationFields
				destinations={ destinations }
				loaded={ loaded }
				form={ form }
				onChange={ onChange }
			/>
		</div>
	);
}

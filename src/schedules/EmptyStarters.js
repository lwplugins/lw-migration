/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { starters } from './starters';

/**
 * No schedule yet: three ready-made ones that open the editor filled in
 * (nothing is saved until "Save schedule").
 *
 * @param {Object}                                  props
 * @param {(form: Object, opener: Element) => void} props.onStart Opens the editor with the starter's form.
 */
export default function EmptyStarters( { onStart } ) {
	return (
		<section className="lw-sched-empty" aria-labelledby="lw-sched-empty">
			<h3 id="lw-sched-empty" className="lw-sched-empty__title">
				{ __( 'No schedules yet', 'lw-migration' ) }
			</h3>
			<p className="lw-sched-empty__lead">
				{ __(
					'Start from a common setup, or set one up yourself with New schedule. You can change everything before saving.',
					'lw-migration'
				) }
			</p>
			<div className="lw-starters">
				{ starters().map( ( starter ) => (
					<button
						key={ starter.key }
						type="button"
						className="lw-starter"
						onClick={ ( event ) =>
							onStart( starter.form, event.currentTarget )
						}
					>
						<strong>{ starter.title }</strong>
						<span>{ starter.text }</span>
					</button>
				) ) }
			</div>
		</section>
	);
}

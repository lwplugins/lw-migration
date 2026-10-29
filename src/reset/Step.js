/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, check } from '@wordpress/icons';

/**
 * One numbered step before a reset starts; once done, its number turns
 * into a check (and says "Done" to a screen reader).
 *
 * @param {Object}  props
 * @param {number}  props.number   Position.
 * @param {boolean} props.done     The step is done.
 * @param {Element} props.children Content.
 */
export default function Step( { number, done, children } ) {
	return (
		<li className={ `lw-reset-step ${ done ? 'is-done' : '' }` }>
			<span className="lw-reset-step__mark" aria-hidden="true">
				{ done ? <Icon icon={ check } size={ 18 } /> : number }
			</span>
			<div className="lw-reset-step__body">
				{ done && (
					<span className="screen-reader-text">
						{ __( 'Done', 'lw-migration' ) }
					</span>
				) }
				{ children }
			</div>
		</li>
	);
}

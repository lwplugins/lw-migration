/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, lock } from '@wordpress/icons';

/**
 * What replaces the steps after too many wrong passwords: that no reset can
 * start now, and in how many minutes it can be tried again (the server
 * sends no count of tries left).
 *
 * @param {Object} props
 * @param {number} props.minutes  Whole minutes until the next try.
 * @param {Object} props.focusRef Ref that takes the focus when the lock comes.
 */
export default function ResetLock( { minutes, focusRef } ) {
	return (
		<div
			className="lw-reset-locked"
			role="alert"
			tabIndex={ -1 }
			ref={ focusRef }
		>
			<Icon icon={ lock } size={ 20 } />
			<div>
				<p>
					<strong>
						{ __(
							'You cannot start a reset right now.',
							'lw-migration'
						) }
					</strong>
				</p>
				<p>
					{ sprintf(
						/* translators: %d: minutes until the next try. */
						_n(
							'Too many wrong passwords. Try again in %d minute.',
							'Too many wrong passwords. Try again in %d minutes.',
							minutes,
							'lw-migration'
						),
						minutes
					) }
				</p>
			</div>
		</div>
	);
}

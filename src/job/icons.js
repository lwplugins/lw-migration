/**
 * The job card's own icons (the rest come from the core icon set). Both are
 * decorative: the text beside them says the same.
 */

/**
 * WordPress dependencies
 */
import { Path, Rect, SVG } from '@wordpress/primitives';

/**
 * The bolt of the LW mark: a finished phase, and the spark that rides the
 * current one.
 *
 * @param {Object} props
 * @param {string} props.className Class.
 */
export function Bolt( { className } ) {
	return (
		<SVG
			className={ className }
			viewBox="0 0 24 24"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
			focusable="false"
		>
			<Path
				fill="currentColor"
				d="M13.6 2.2 4.8 13.4a.8.8 0 0 0 .6 1.3H11l-1.4 6.9c-.2.8.8 1.2 1.3.6l8.8-11.2a.8.8 0 0 0-.6-1.3H13.4l1.5-6.9c.2-.8-.8-1.2-1.3-.6z"
			/>
		</SVG>
	);
}

/**
 * A browser tab: "keep this tab open".
 */
export function BrowserTab() {
	return (
		<SVG
			viewBox="0 0 24 24"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
			focusable="false"
		>
			<Rect
				x="3"
				y="5"
				width="18"
				height="14"
				rx="2"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.7"
			/>
			<Path
				fill="none"
				stroke="currentColor"
				strokeWidth="1.7"
				d="M3 9h18"
			/>
			<Path
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				d="M6 7h.01M9 7h.01"
			/>
		</SVG>
	);
}

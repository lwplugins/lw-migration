/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

/**
 * LW Migration mark (two opposing arrows: moving between sites), the paths of
 * the registry icon `lw-migration.svg`; both layers follow `color`, the
 * secondary one at 40%.
 */
export default function LwMark() {
	return (
		<SVG
			className="lw-admin-mark"
			viewBox="0 0 24 24"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
			focusable="false"
		>
			<Path
				opacity=".4"
				fill="currentColor"
				d="M21 14H10v-3l-7 5 7 5v-3h11z"
			/>
			<Path fill="currentColor" d="M3 6h11V3l7 5-7 5v-3H3z" />
		</SVG>
	);
}

/**
 * WordPress dependencies
 */
// Experimental core components: the shared LW admin pattern relies on them.
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */

/**
 * Soft segmented control (styled core ToggleGroupControl) from a value→label map.
 *
 * @param {Object}                  props
 * @param {string}                  props.label       Accessible label (hidden).
 * @param {Object}                  props.options     { value: label }.
 * @param {string}                  props.value       Current value.
 * @param {(value: string) => void} props.onChange    Receives the value.
 * @param {string}                  props.describedBy Id of a hint that describes it (optional).
 */
export default function Segmented( {
	label,
	options,
	value,
	onChange,
	describedBy,
} ) {
	return (
		<ToggleGroupControl
			__next40pxDefaultSize
			isBlock
			hideLabelFromVision
			aria-describedby={ describedBy }
			label={ label }
			value={ value }
			onChange={ onChange }
		>
			{ Object.entries( options ).map( ( [ key, text ] ) => (
				<ToggleGroupControlOption
					key={ key }
					value={ key }
					label={ text }
				/>
			) ) }
		</ToggleGroupControl>
	);
}

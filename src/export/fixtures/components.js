/**
 * Plain stand-ins for the WordPress components in the Backup section's server
 * renders (the real ones need the browser); they keep what the page shows.
 * A test mocks the components package with an import of this module.
 */

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

const box =
	( tag, className = '' ) =>
	( { children, className: own } ) =>
		createElement(
			tag,
			{ className: own || className || undefined },
			children
		);

export const Button = ( { children, disabled } ) =>
	createElement(
		'button',
		{ 'aria-disabled': disabled ? 'true' : undefined },
		children
	);
export const Card = box( 'div' );
export const CardBody = box( 'div' );
export const CardHeader = box( 'div' );
export const FormToggle = ( { id, checked, disabled } ) =>
	createElement( 'input', {
		id,
		type: 'checkbox',
		role: 'switch',
		checked,
		disabled,
		readOnly: true,
	} );
export const Notice = box( 'div', 'notice' );
export const TextControl = ( { label } ) => createElement( 'label', {}, label );
export const TextareaControl = ( { label } ) =>
	createElement( 'label', {}, label );
export const __experimentalHeading = box( 'h3' );
export const __experimentalHStack = box( 'div' );
export const __experimentalVStack = box( 'div' );
export const __experimentalText = box( 'p' );
export const __experimentalToggleGroupControl = ( {
	label,
	value,
	children,
} ) =>
	createElement(
		'div',
		{ className: 'segmented', 'aria-label': label, title: value },
		children
	);
export const __experimentalToggleGroupControlOption = ( { label } ) =>
	createElement( 'button', {}, label );

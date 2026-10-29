/**
 * WordPress dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { closeSmall, plus } from '@wordpress/icons';

/**
 * Controls of the backup options shared by the Backup section and the
 * schedule editor (ExportOptions): the invalid marking, a titled group and
 * the search-and-replace pairs.
 */

/**
 * Props that mark a control invalid: the error replaces its help text, so
 * the control's own aria-describedby reads it. `data-refused` marks what a
 * refused start named (ExportView focuses it); a live hint does not.
 *
 * @param {string}  error   Error text ('' = valid).
 * @param {string}  help    Normal help text.
 * @param {boolean} refused The start was refused for this control.
 * @return {Object} Props.
 */
export const marked = ( error, help, refused = true ) =>
	error
		? {
				help: error,
				'aria-invalid': true,
				'data-refused': refused,
				className: 'is-invalid',
			}
		: { help };

/**
 * A titled group of controls (a heading instead of a legend, so the
 * separator line never runs through the title).
 *
 * @param {Object}  props
 * @param {string}  props.id       Heading id.
 * @param {string}  props.title    Heading.
 * @param {Element} props.children Controls.
 * @param {string}  props.block    Class block of the group and its heading.
 */
export function Group( { id, title, children, block = 'lw-options' } ) {
	return (
		<div
			className={ `${ block }__group` }
			role="group"
			aria-labelledby={ id }
		>
			<h4 id={ id } className={ `${ block }__title` }>
				{ title }
			</h4>
			{ children }
		</div>
	);
}

/**
 * "Search and replace in the database": pairs stored in the backup and
 * applied when it is restored.
 *
 * @param {Object}                      props
 * @param {string[][]}                  props.pairs      [ from, to ] pairs.
 * @param {(pairs: string[][]) => void} props.onChange   Receives the new pairs.
 * @param {string}                      props.error      Refusal text ('' = valid).
 * @param {?number}                     props.errorIndex Refused pair (null = all).
 */
export function ReplacePairs( { pairs, onChange, error, errorIndex } ) {
	const set = ( index, side, value ) =>
		onChange(
			pairs.map( ( pair, i ) => {
				if ( i !== index ) {
					return pair;
				}
				return 0 === side ? [ value, pair[ 1 ] ] : [ pair[ 0 ], value ];
			} )
		);

	return (
		<Group
			id="lw-options-replace"
			title={ __( 'Search and replace in the database', 'lw-migration' ) }
		>
			<p className="lw-options__hint">
				{ __(
					'Applied to the database when this backup is restored. The site address is changed by itself.',
					'lw-migration'
				) }
			</p>
			{ pairs.map( ( [ from, to ], index ) => {
				const invalid =
					error && ( null === errorIndex || errorIndex === index );
				const mark = invalid
					? {
							'aria-invalid': true,
							'aria-describedby': 'lw-options-replace-error',
							'data-refused': true,
							className: 'is-invalid',
						}
					: {};
				return (
					<div key={ index } className="lw-pairs__row">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							{ ...mark }
							label={ __( 'Find', 'lw-migration' ) }
							value={ from }
							onChange={ ( value ) => set( index, 0, value ) }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							{ ...mark }
							label={ __( 'Replace with', 'lw-migration' ) }
							value={ to }
							onChange={ ( value ) => set( index, 1, value ) }
						/>
						<Button
							__next40pxDefaultSize
							icon={ closeSmall }
							label={ __( 'Remove this pair', 'lw-migration' ) }
							onClick={ () =>
								onChange(
									pairs.filter( ( _, i ) => i !== index )
								)
							}
						/>
					</div>
				);
			} ) }
			{ error && (
				<p
					id="lw-options-replace-error"
					className="lw-options__error"
					role="alert"
				>
					{ error }
				</p>
			) }
			<Button
				__next40pxDefaultSize
				variant="secondary"
				icon={ plus }
				onClick={ () => onChange( [ ...pairs, [ '', '' ] ] ) }
			>
				{ __( 'Add a pair', 'lw-migration' ) }
			</Button>
		</Group>
	);
}

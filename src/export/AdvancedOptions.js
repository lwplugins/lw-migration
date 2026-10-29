/**
 * WordPress dependencies
 */
import { Card, TextareaControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, chevronDown } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import StatusBadge from '../components/StatusBadge';
import ToggleRow from '../components/ToggleRow';
import { ReplacePairs, marked } from '../views/optionControls';
import { advancedCount } from './choices';

/**
 * The folded "Advanced" card: search and replace, leaving out by name, only
 * these tables, the e-mail addresses. Its inputs stay mounted while folded,
 * so nothing typed is lost; while folded, a badge counts what is set.
 *
 * @param {Object}                  props
 * @param {boolean}                 props.open     Expanded.
 * @param {() => void}              props.onToggle Expand/collapse.
 * @param {Object}                  props.options  Form state.
 * @param {(patch: Object) => void} props.onChange Receives a form-state patch.
 * @param {Object}                  props.errors   { field: text }, plus `replaceIndex`.
 */
export default function AdvancedOptions( {
	open,
	onToggle,
	options,
	onChange,
	errors,
} ) {
	const set = advancedCount( options );

	return (
		<Card
			className={ `lw-admin-section lw-advanced ${
				open ? 'is-open' : ''
			}` }
		>
			<h3 className="lw-advanced__heading">
				<button
					type="button"
					className="lw-advanced__toggle"
					aria-expanded={ open }
					aria-controls="lw-advanced-body"
					onClick={ onToggle }
				>
					<span className="lw-advanced__title">
						{ __( 'Advanced', 'lw-migration' ) }
					</span>
					<span className="lw-advanced__hint">
						{ __(
							'search and replace, leaving out by name',
							'lw-migration'
						) }
					</span>
					{ set > 0 && ! open && (
						<span className="lw-options__badge">
							<StatusBadge status="info">
								{ sprintf(
									/* translators: %d: number of settings set in the Advanced section. */
									_n(
										'%d set',
										'%d set',
										set,
										'lw-migration'
									),
									set
								) }
							</StatusBadge>
						</span>
					) }
					<Icon
						icon={ chevronDown }
						className="lw-advanced__chevron"
					/>
				</button>
			</h3>
			<div
				id="lw-advanced-body"
				className="lw-advanced__body"
				hidden={ ! open }
			>
				<div className="lw-options">
					<ReplacePairs
						pairs={ options.replace }
						onChange={ ( replace ) => onChange( { replace } ) }
						error={ errors.replace || '' }
						errorIndex={ errors.replaceIndex ?? null }
					/>
					<div className="lw-advanced__cols">
						<TextareaControl
							__nextHasNoMarginBottom
							rows={ 3 }
							label={ __(
								'Leave out files and folders',
								'lw-migration'
							) }
							value={ options.excludeFiles }
							onChange={ ( excludeFiles ) =>
								onChange( { excludeFiles } )
							}
							{ ...marked(
								errors.excludeFiles,
								__(
									'One per line, inside wp-content, for example: uploads/videos',
									'lw-migration'
								)
							) }
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							rows={ 3 }
							label={ __(
								'Leave out database tables',
								'lw-migration'
							) }
							value={ options.excludeTables }
							onChange={ ( excludeTables ) =>
								onChange( { excludeTables } )
							}
							{ ...marked(
								errors.excludeTables,
								__( 'One table name per line.', 'lw-migration' )
							) }
						/>
					</div>
					<TextareaControl
						__nextHasNoMarginBottom
						rows={ 3 }
						label={ __( 'Only these tables', 'lw-migration' ) }
						value={ options.includeTables }
						onChange={ ( includeTables ) =>
							onChange( { includeTables } )
						}
						{ ...marked(
							errors.includeTables,
							__(
								'Leave it empty to include every table of this site.',
								'lw-migration'
							)
						) }
					/>
					<ToggleRow
						inline
						title={ __(
							'Replace e-mail addresses',
							'lw-migration'
						) }
						help={ __(
							'When the backup is restored at another address, e-mail addresses at the old domain change with it. Switch it off to keep them.',
							'lw-migration'
						) }
						checked={ ! options.noEmailReplace }
						onChange={ ( on ) =>
							onChange( { noEmailReplace: ! on } )
						}
					/>
				</div>
			</div>
		</Card>
	);
}

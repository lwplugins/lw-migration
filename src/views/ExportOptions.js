/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	TextareaControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { chevronDown, chevronUp } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import StatusBadge from '../components/StatusBadge';
import { DEFAULTS, countChanged } from './exportRequest';
import { Group, ReplacePairs, marked } from './optionControls';

// The database, media, plugins and themes, and the password, are chosen
// above the card (the schedule editor's "What" section).
const FLAG_LABELS = {
	noInactiveThemes: __( 'Inactive themes', 'lw-migration' ),
	noInactivePlugins: __( 'Inactive plugins', 'lw-migration' ),
	noMustUsePlugins: __( 'Must-use plugins', 'lw-migration' ),
	noCache: __( 'Cache folders', 'lw-migration' ),
	noSpamComments: __( 'Spam comments', 'lw-migration' ),
	noPostRevisions: __( 'Post revisions', 'lw-migration' ),
};

/** Set outside the card: not counted in its "changed" badge. */
const OUTSIDE = [ 'noMedia', 'noThemes', 'noPlugins', 'noDatabase', 'encrypt' ];

const COMPRESSION = {
	none: __( 'None', 'lw-migration' ),
	gzip: 'gzip',
	bzip2: 'bzip2',
};

/**
 * The folded "Advanced settings" card of the schedule editor (spec 11,
 * ruling P11: text areas, no pickers): the rest of what to leave out, the
 * compression, exclusions by name, search and replace. Its inputs stay
 * mounted while folded, so nothing typed is lost; the state lives in the
 * editor.
 *
 * @param {Object}                  props
 * @param {boolean}                 props.open     Expanded.
 * @param {() => void}              props.onToggle Expand/collapse.
 * @param {Object}                  props.options  Form state.
 * @param {(patch: Object) => void} props.onChange Merges a change.
 * @param {Object}                  props.errors   { field: text }, plus `replaceIndex`.
 */
export default function ExportOptions( {
	open,
	onToggle,
	options,
	onChange,
	errors,
} ) {
	const changed = countChanged( {
		...options,
		...Object.fromEntries(
			OUTSIDE.map( ( key ) => [ key, DEFAULTS[ key ] ] )
		),
	} );

	return (
		<Section
			className={ `lw-options-card ${ open ? '' : 'is-collapsed' }` }
			title={ __( 'Advanced settings', 'lw-migration' ) }
			badge={
				changed > 0 && (
					<span className="lw-options__badge">
						<StatusBadge status="info">
							{ sprintf(
								/* translators: %d: number of changed settings. */
								_n(
									'%d changed',
									'%d changed',
									changed,
									'lw-migration'
								),
								changed
							) }
						</StatusBadge>
					</span>
				)
			}
			description={ __(
				'More to leave out, compression, address changes.',
				'lw-migration'
			) }
			actions={
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					icon={ open ? chevronUp : chevronDown }
					aria-expanded={ open }
					aria-controls="lw-export-options"
					onClick={ onToggle }
				>
					{ open
						? __( 'Hide', 'lw-migration' )
						: __( 'Show', 'lw-migration' ) }
				</Button>
			}
		>
			<div id="lw-export-options" className="lw-options">
				<Group
					id="lw-options-leave"
					title={ __( 'Leave out', 'lw-migration' ) }
				>
					<div className="lw-options__grid">
						{ Object.entries( FLAG_LABELS ).map(
							( [ key, label ] ) => (
								<CheckboxControl
									key={ key }
									__nextHasNoMarginBottom
									label={ label }
									checked={ options[ key ] }
									onChange={ ( value ) =>
										onChange( { [ key ]: value } )
									}
								/>
							)
						) }
					</div>
				</Group>

				<Group
					id="lw-options-file"
					title={ __( 'The file', 'lw-migration' ) }
				>
					<div className="lw-options__field">
						<span className="lw-options__label" aria-hidden="true">
							{ __( 'Compression', 'lw-migration' ) }
						</span>
						<Segmented
							label={ __( 'Compression', 'lw-migration' ) }
							options={ COMPRESSION }
							value={ options.compression }
							onChange={ ( value ) =>
								onChange( { compression: value } )
							}
							describedBy="lw-options-compression-hint"
						/>
						<p
							id="lw-options-compression-hint"
							className="lw-options__hint"
						>
							{ __(
								'A smaller file, a slower backup.',
								'lw-migration'
							) }
						</p>
					</div>
				</Group>

				<Group
					id="lw-options-names"
					title={ __( 'Leave out by name', 'lw-migration' ) }
				>
					<TextareaControl
						__nextHasNoMarginBottom
						rows={ 3 }
						label={ __( 'Files and folders', 'lw-migration' ) }
						value={ options.excludeFiles }
						onChange={ ( value ) =>
							onChange( { excludeFiles: value } )
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
						label={ __( 'Database tables', 'lw-migration' ) }
						value={ options.excludeTables }
						onChange={ ( value ) =>
							onChange( { excludeTables: value } )
						}
						{ ...marked(
							errors.excludeTables,
							__( 'One table name per line.', 'lw-migration' )
						) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						rows={ 3 }
						label={ __( 'Only these tables', 'lw-migration' ) }
						value={ options.includeTables }
						onChange={ ( value ) =>
							onChange( { includeTables: value } )
						}
						{ ...marked(
							errors.includeTables,
							__(
								'Leave it empty to include every table of this site.',
								'lw-migration'
							)
						) }
					/>
				</Group>

				<ReplacePairs
					pairs={ options.replace }
					onChange={ ( replace ) => onChange( { replace } ) }
					error={ errors.replace || '' }
					errorIndex={ errors.replaceIndex ?? null }
				/>

				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Keep e-mail domains when restored elsewhere',
						'lw-migration'
					) }
					checked={ options.noEmailReplace }
					onChange={ ( value ) =>
						onChange( { noEmailReplace: value } )
					}
				/>
			</div>
		</Section>
	);
}

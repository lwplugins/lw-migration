/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import SettingRow from '../components/SettingRow';
import ToggleRow from '../components/ToggleRow';
import { codeChoice, codePatch } from './choices';
import {
	cleanupHelp,
	files,
	plugins,
	pluginsHelp,
	sizeText,
	tables,
	themes,
	themesHelp,
} from './choiceText';
import { knownPart } from './estimate';
import { Group } from '../views/optionControls';

const PLUGIN_CHOICES = {
	all: _x( 'All', 'plugins or themes', 'lw-migration' ),
	active: _x( 'Active only', 'plugins', 'lw-migration' ),
	none: _x( 'None', 'plugins or themes', 'lw-migration' ),
};

const THEME_CHOICES = {
	...PLUGIN_CHOICES,
	active: _x( 'Active only', 'themes', 'lw-migration' ),
};

/**
 * "What goes in": every part of the backup with its size and count from the
 * estimate, switched on by default except the cache folders. The must-use
 * count is the plugins WordPress loads from mu-plugins (docs/react-admin-api.md). Each control sets the request flags of
 * exportRequest.js; the plugins and themes choices set two flags each
 * (choices.js), and spam comments and revisions stay two switches (leaving
 * out the junk need not cost the revision history).
 *
 * @param {Object}                  props
 * @param {Object}                  props.options  Form state.
 * @param {(patch: Object) => void} props.onChange Receives a form-state patch.
 * @param {?Object}                 props.estimate Estimate.
 * @param {string}                  props.status   estimateStatus().
 */
export default function ContentChoices( {
	options,
	onChange,
	estimate,
	status,
} ) {
	const sized = ( part, count, saving ) =>
		sizeText( { estimate, status, part, count, saving } );
	const title = ( name, size ) =>
		size ? (
			<>
				{ name } <span className="lw-choice__size">{ size }</span>
			</>
		) : (
			name
		);
	const pluginChoice = codeChoice( options, 'plugins' );
	const themeChoice = codeChoice( options, 'themes' );
	const database = knownPart( estimate, 'database' );

	return (
		<Section
			title={ __( 'What goes in', 'lw-migration' ) }
			description={ __(
				'Everything except the cache folders is in by default. What you switch off stays untouched on the target site when the backup is restored.',
				'lw-migration'
			) }
			className="lw-choices"
		>
			<Group
				block="lw-choices"
				id="lw-choices-content"
				title={ __( 'Content', 'lw-migration' ) }
			>
				<ToggleRow
					inline
					title={ title(
						__( 'Media library', 'lw-migration' ),
						sized( 'media', files )
					) }
					help={ __(
						'The uploaded images, documents and videos.',
						'lw-migration'
					) }
					checked={ ! options.noMedia }
					onChange={ ( on ) => onChange( { noMedia: ! on } ) }
				/>
				<ToggleRow
					inline
					title={ title(
						__( 'Database', 'lw-migration' ),
						sized( 'database', tables )
					) }
					help={ __(
						'Posts, pages, users, settings.',
						'lw-migration'
					) }
					checked={ ! options.noDatabase }
					onChange={ ( on ) => onChange( { noDatabase: ! on } ) }
				/>
			</Group>
			<Group
				block="lw-choices"
				id="lw-choices-code"
				title={ __( 'Code', 'lw-migration' ) }
			>
				<SettingRow
					inline
					title={ title(
						__( 'Plugins', 'lw-migration' ),
						sized( 'plugins', plugins )
					) }
					help={ pluginsHelp(
						pluginChoice,
						knownPart( estimate, 'plugins' )
					) }
				>
					<Segmented
						label={ __( 'Plugins', 'lw-migration' ) }
						options={ PLUGIN_CHOICES }
						value={ pluginChoice }
						onChange={ ( choice ) =>
							onChange( codePatch( 'plugins', choice ) )
						}
					/>
				</SettingRow>
				<SettingRow
					inline
					title={ title(
						__( 'Themes', 'lw-migration' ),
						sized( 'themes', themes )
					) }
					help={ themesHelp(
						themeChoice,
						knownPart( estimate, 'themes' )
					) }
				>
					<Segmented
						label={ __( 'Themes', 'lw-migration' ) }
						options={ THEME_CHOICES }
						value={ themeChoice }
						onChange={ ( choice ) =>
							onChange( codePatch( 'themes', choice ) )
						}
					/>
				</SettingRow>
				<ToggleRow
					inline
					title={ title(
						__( 'Must-use plugins', 'lw-migration' ),
						sized( 'muPlugins', plugins )
					) }
					help={ __(
						'The contents of wp-content/mu-plugins.',
						'lw-migration'
					) }
					checked={ ! options.noMustUsePlugins }
					onChange={ ( on ) =>
						onChange( { noMustUsePlugins: ! on } )
					}
				/>
			</Group>
			<Group
				block="lw-choices"
				id="lw-choices-cleanup"
				title={ __( 'Clean-up', 'lw-migration' ) }
			>
				<ToggleRow
					inline
					title={ title(
						__( 'Leave out the cache', 'lw-migration' ),
						sized( 'cache', null, true )
					) }
					help={ __(
						'Cache folders are not needed for a restore.',
						'lw-migration'
					) }
					checked={ options.noCache }
					onChange={ ( noCache ) => onChange( { noCache } ) }
				/>
				<ToggleRow
					inline
					title={ __( 'Leave out spam comments', 'lw-migration' ) }
					help={ cleanupHelp( database, 'spamComments' ) }
					checked={ options.noSpamComments }
					disabled={ options.noDatabase }
					onChange={ ( noSpamComments ) =>
						onChange( { noSpamComments } )
					}
				/>
				<ToggleRow
					inline
					title={ __( 'Leave out post revisions', 'lw-migration' ) }
					help={ cleanupHelp( database, 'revisions' ) }
					checked={ options.noPostRevisions }
					disabled={ options.noDatabase }
					onChange={ ( noPostRevisions ) =>
						onChange( { noPostRevisions } )
					}
				/>
			</Group>
		</Section>
	);
}

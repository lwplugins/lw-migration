/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NETWORK } from '../data/boot';
import { formatBytes, formatNumber } from '../data/format';
import { figuresOf, partState } from './estimate';

/**
 * The words of the "What goes in" rows (ContentChoices): the size and count
 * beside a title, and what a choice keeps, with the estimate's counts when
 * they are known.
 */

/**
 * The size beside a row's title: the part's size and count ("at least" while
 * a first measuring still walks the files), "counting…" while it is not
 * measured yet, "could not be counted"; '' when the estimate is not available.
 *
 * @param {Object}                      props
 * @param {?Object}                     props.estimate Estimate.
 * @param {string}                      props.status   estimateStatus().
 * @param {string}                      props.part     Part name.
 * @param {?((part: Object) => string)} props.count    The part's count text (null = none).
 * @param {boolean}                     props.saving   The size is what leaving it out saves.
 * @return {string} Text.
 */
export function sizeText( { estimate, status, part, count, saving = false } ) {
	const state = partState( estimate, part );
	if ( 'failed' === state ) {
		return __( 'could not be counted', 'lw-migration' );
	}
	const figures = figuresOf( estimate, part );
	if ( figures ) {
		const size = formatBytes( figures.bytes );
		const text = [ saving ? `\u2212${ size }` : size, count?.( figures ) ]
			.filter( Boolean )
			.join( ' \u00B7 ' );
		// A first measuring's file figures only grow: they are lower bounds.
		return 'growing' === state
			? sprintf(
					/* translators: %s: size (and count) counted so far, e.g. 1.2 GB · 4,812 files. */
					__( 'at least %s', 'lw-migration' ),
					text
				)
			: text;
	}
	return 'counting' === status ? __( 'counting…', 'lw-migration' ) : '';
}

export const files = ( part ) =>
	sprintf(
		/* translators: %s: number of files. */
		_n( '%s file', '%s files', part.files, 'lw-migration' ),
		formatNumber( part.files )
	);
export const tables = ( part ) =>
	sprintf(
		/* translators: %s: number of database tables. */
		_n( '%s table', '%s tables', part.tables, 'lw-migration' ),
		formatNumber( part.tables )
	);
export const plugins = ( part ) =>
	sprintf(
		/* translators: %s: number of plugins. */
		_n( '%s plugin', '%s plugins', part.count, 'lw-migration' ),
		formatNumber( part.count )
	);
export const themes = ( part ) =>
	sprintf(
		/* translators: %s: number of themes. */
		_n( '%s theme', '%s themes', part.count, 'lw-migration' ),
		formatNumber( part.count )
	);

/**
 * What the plugins choice keeps, in words (with the counts when known).
 *
 * @param {string}  choice codeChoice().
 * @param {?Object} part   The plugins part of the estimate.
 * @return {string} Text.
 */
export function pluginsHelp( choice, part ) {
	if ( 'none' === choice ) {
		return __( 'No plugin goes in.', 'lw-migration' );
	}
	if ( 'active' === choice ) {
		return part
			? sprintf(
					/* translators: 1: number of active plugins, 2: number of inactive plugins. */
					__(
						'The %1$s active ones; the %2$s inactive ones stay out.',
						'lw-migration'
					),
					formatNumber( part.activeCount ),
					formatNumber( part.count - part.activeCount )
				)
			: __(
					'Only the active plugins; the inactive ones stay out.',
					'lw-migration'
				);
	}
	return part
		? sprintf(
				/* translators: %s: number of plugins. */
				_n(
					'%s plugin, active or not.',
					'All %s, the inactive ones too.',
					part.count,
					'lw-migration'
				),
				formatNumber( part.count )
			)
		: __( 'Every plugin, the inactive ones too.', 'lw-migration' );
}

/**
 * What the themes choice keeps, in words.
 *
 * @param {string}  choice codeChoice().
 * @param {?Object} part   The themes part of the estimate.
 * @return {string} Text.
 */
export function themesHelp( choice, part ) {
	if ( 'none' === choice ) {
		return __( 'No theme goes in.', 'lw-migration' );
	}
	if ( 'active' === choice ) {
		return NETWORK
			? __(
					'Only the themes the sites use, with their parents.',
					'lw-migration'
				)
			: __( 'Only the theme in use and its parent.', 'lw-migration' );
	}
	return part
		? sprintf(
				/* translators: %s: number of themes. */
				_n(
					'%s theme, in use or not.',
					'All %s, the unused ones too.',
					part.count,
					'lw-migration'
				),
				formatNumber( part.count )
			)
		: __( 'Every theme, the unused ones too.', 'lw-migration' );
}

/**
 * The help under a database clean-up switch: the count when known.
 *
 * @param {?Object} database The database part of the estimate.
 * @param {string}  key      'spamComments' or 'revisions'.
 * @return {string} Text.
 */
export function cleanupHelp( database, key ) {
	const count = database?.[ key ];
	if ( 'spamComments' === key ) {
		return null === count || undefined === count
			? __( 'Comments marked as spam.', 'lw-migration' )
			: sprintf(
					/* translators: %s: number of spam comments. */
					_n(
						'%s spam comment.',
						'%s spam comments.',
						count,
						'lw-migration'
					),
					formatNumber( count )
				);
	}
	return null === count || undefined === count
		? __( 'Earlier versions of posts and pages.', 'lw-migration' )
		: sprintf(
				/* translators: %s: number of post revisions. */
				_n(
					'%s earlier version of posts and pages.',
					'%s earlier versions of posts and pages.',
					count,
					'lw-migration'
				),
				formatNumber( count )
			);
}

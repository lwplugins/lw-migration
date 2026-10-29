/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import LoginNotice from '../components/LoginNotice';
import Segmented from '../components/Segmented';
import {
	SkeletonBlock,
	SkeletonRegion,
	SkeletonText,
} from '../components/skeleton';
import { api } from '../data/api';
import { errorMessage, isLoggedOut } from '../data/errors';
import { PER_PAGE, label, pageCount, remember, toggle } from './sitePicker';
import './network.scss';

const chipName = ( labels, id ) =>
	labels[ id ] ||
	sprintf(
		/* translators: %d: site ID in this network. */
		__( 'Site %d', 'lw-migration' ),
		id
	);

/**
 * Rows in the shape of the list while a page loads.
 */
function ListSkeleton() {
	return (
		<SkeletonRegion
			className="lw-picker__list"
			label={ __( 'Loading the sites…', 'lw-migration' ) }
		>
			{ Array.from( { length: 5 }, ( _, index ) => (
				<div key={ index } className="lw-picker__skel-row">
					<SkeletonBlock width={ 18 } height={ 18 } />
					<SkeletonText width={ `${ 40 + ( index % 3 ) * 15 }%` } />
				</div>
			) ) }
		</SkeletonRegion>
	);
}

/**
 * One page of the network's sites with checkboxes, searched by address.
 *
 * @param {Object}                                     props
 * @param {number[]}                                   props.chosen   Chosen site ids.
 * @param {Object}                                     props.seen     Id => label of every site seen so far.
 * @param {(update: (seen: Object) => Object) => void} props.onSeen   Updates `seen` (a state setter).
 * @param {(patch: Object) => void}                    props.onChange Receives `{ sites, siteLabels }`.
 * @param {string}                                     props.tab      Tab to come back to after a login.
 * @param {string}                                     props.idBase   Prefix of the element ids.
 */
function SiteList( { chosen, seen, onSeen, onChange, tab, idBase } ) {
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( { page: 1, search: '' } );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ attempt, setAttempt ] = useState( 0 );
	// A stable callback: useDebounce starts over (and drops the wait) when it changes.
	const newSearch = useCallback(
		( text ) => setQuery( { page: 1, search: text.trim() } ),
		[]
	);
	const searchFor = useDebounce( newSearch, 300 );

	useEffect( () => {
		const controller = new window.AbortController();
		setResult( null );
		setError( null );
		api.networkSites(
			{ page: query.page, perPage: PER_PAGE, search: query.search },
			controller.signal
		).then(
			( found ) => {
				setResult( found );
				onSeen( ( prev ) => remember( prev, found.items ) );
			},
			( e ) => {
				if ( ! controller.signal.aborted ) {
					setError( e );
				}
			}
		);
		return () => controller.abort();
	}, [ query, attempt, onSeen ] );

	const pick = ( site ) => {
		const sites = toggle( chosen, site.id );
		const known = { ...seen, [ site.id ]: label( site ) };
		const siteLabels = Object.fromEntries(
			sites
				.filter( ( id ) => known[ id ] )
				.map( ( id ) => [ id, known[ id ] ] )
		);
		onChange( { sites, siteLabels } );
	};
	const pages = result ? pageCount( result.total, PER_PAGE ) : 1;
	const go = ( page ) => setQuery( ( prev ) => ( { ...prev, page } ) );

	return (
		<>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="search"
				label={ __( 'Search by address', 'lw-migration' ) }
				help={ __(
					'Finds sites by their domain or path, not by their title.',
					'lw-migration'
				) }
				value={ search }
				onChange={ ( text ) => {
					setSearch( text );
					searchFor( text );
				} }
			/>
			{ error && isLoggedOut( error ) && <LoginNotice tab={ tab } /> }
			{ error && ! isLoggedOut( error ) && (
				<Notice
					status="error"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Try again', 'lw-migration' ),
							onClick: () => setAttempt( ( n ) => n + 1 ),
						},
					] }
				>
					{ errorMessage( error ) }
				</Notice>
			) }
			{ ! error && ! result && <ListSkeleton /> }
			{ result && ! result.items.length && (
				<p className="lw-picker__empty">
					{ __( 'No site address matches.', 'lw-migration' ) }
				</p>
			) }
			{ result && result.items.length > 0 && (
				<ul
					className="lw-picker__list"
					aria-label={ __( 'Sites of this network', 'lw-migration' ) }
				>
					{ result.items.map( ( site ) => (
						<li key={ site.id } className="lw-picker__row">
							<CheckboxControl
								__nextHasNoMarginBottom
								id={ `${ idBase }-site-${ site.id }` }
								label={ label( site ) }
								help={ site.title }
								checked={ chosen.includes( site.id ) }
								onChange={ () => pick( site ) }
							/>
						</li>
					) ) }
				</ul>
			) }
			{ result && pages > 1 && (
				<div className="lw-picker__pager">
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ query.page <= 1 }
						accessibleWhenDisabled
						onClick={ () => go( query.page - 1 ) }
					>
						{ __( 'Previous', 'lw-migration' ) }
					</Button>
					<span className="lw-picker__page">
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'lw-migration' ),
							query.page,
							pages
						) }
					</span>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						disabled={ query.page >= pages }
						accessibleWhenDisabled
						onClick={ () => go( query.page + 1 ) }
					>
						{ __( 'Next', 'lw-migration' ) }
					</Button>
				</div>
			) }
		</>
	);
}

/**
 * What a network backup takes: the whole network or the chosen sites. The
 * chosen sites stay chosen across pages and searches and show as removable
 * chips above the list.
 *
 * @param {Object}                  props
 * @param {Object}                  props.value    Form state (`scope`, `sites`, `siteLabels`).
 * @param {(patch: Object) => void} props.onChange Merges a change.
 * @param {string}                  props.error    Refusal text ('' = none).
 * @param {string}                  props.tab      Tab to come back to after a login.
 * @param {string}                  props.idBase   Prefix of the element ids.
 */
export default function SiteScope( {
	value,
	onChange,
	error = '',
	tab,
	idBase = 'lw-picker',
} ) {
	const chosen = value.sites || [];
	// The labels of the chosen sites, kept with the form, and of every site seen here.
	const [ seen, setSeen ] = useState( () => ( {
		...( value.siteLabels || {} ),
	} ) );
	const labels = { ...seen, ...( value.siteLabels || {} ) };
	const hintId = `${ idBase }-hint`;
	const remove = ( id ) =>
		onChange( {
			sites: toggle( chosen, id ),
			siteLabels: Object.fromEntries(
				Object.entries( value.siteLabels || {} ).filter(
					( [ key ] ) => Number( key ) !== id
				)
			),
		} );

	return (
		<div className="lw-picker">
			<Segmented
				label={ __( 'What to back up', 'lw-migration' ) }
				options={ {
					network: __( 'Whole network', 'lw-migration' ),
					sites: __( 'Chosen sites', 'lw-migration' ),
				} }
				value={ value.scope }
				onChange={ ( scope ) => onChange( { scope } ) }
			/>
			{ 'sites' === value.scope && (
				<>
					<div className="lw-picker__chosen">
						<p
							id={ hintId }
							className={ `lw-picker__count ${
								error ? 'is-invalid' : ''
							}` }
							aria-live="polite"
							data-refused={ error ? 'true' : undefined }
							tabIndex={ error ? -1 : undefined }
						>
							{ chosen.length
								? sprintf(
										/* translators: %d: number of chosen sites. */
										_n(
											'%d chosen',
											'%d chosen',
											chosen.length,
											'lw-migration'
										),
										chosen.length
									)
								: error ||
									__(
										'Choose at least one site.',
										'lw-migration'
									) }
						</p>
						{ chosen.length > 0 && (
							<ul
								className="lw-chips"
								aria-label={ __(
									'Chosen sites',
									'lw-migration'
								) }
							>
								{ chosen.map( ( id ) => (
									<li key={ id } className="lw-chip">
										<span className="lw-chip__name">
											{ chipName( labels, id ) }
										</span>
										<Button
											size="small"
											icon={ closeSmall }
											className="lw-chip__remove"
											label={ sprintf(
												/* translators: %s: site address. */
												__(
													'Remove %s',
													'lw-migration'
												),
												chipName( labels, id )
											) }
											onClick={ () => remove( id ) }
										/>
									</li>
								) ) }
							</ul>
						) }
					</div>
					<SiteList
						chosen={ chosen }
						seen={ seen }
						onSeen={ setSeen }
						onChange={ onChange }
						tab={ tab }
						idBase={ idBase }
					/>
				</>
			) }
		</div>
	);
}

/**
 * External dependencies
 */
// react-dom comes with @wordpress/element; these tests render on the server.
// eslint-disable-next-line import/no-extraneous-dependencies
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AppSkeleton from './AppSkeleton';

// The real cards need the browser; plain stand-ins keep their classes.
vi.mock( '@wordpress/components', () => {
	const box =
		( className = '' ) =>
		( { children, className: own } ) =>
			createElement(
				'div',
				{ className: own || className || undefined },
				children
			);
	return { Card: box(), CardBody: box(), CardHeader: box() };
} );

const render = ( props ) =>
	renderToStaticMarkup( createElement( AppSkeleton, props ) );

const count = ( html, needle ) => html.split( needle ).length - 1;

describe( 'AppSkeleton', () => {
	it( 'draws the Overview on its first paint when the page opens there', () => {
		const html = render( { tab: 'overview' } );
		expect( html ).toContain( 'lw-admin-sidebar' );
		expect( html ).toContain( 'lw-overview-status__ring' );
		expect( html ).toContain( 'lw-overview-actions' );
		expect( html ).toContain( 'lw-overview__cols' );
		expect( html ).not.toContain( 'lw-hero' );
		// One loading announcement for the whole first paint.
		expect( count( html, 'role="status"' ) ).toBe( 1 );
	} );

	it( 'draws the Backup section lead, bar and choices when the page opens there', () => {
		const html = render( { tab: 'export' } );
		expect( html ).toContain( 'lw-export__lead' );
		expect( html ).toContain( 'lw-composition__keys' );
		expect( html ).toContain( 'lw-admin-row' );
		expect( html ).not.toContain( 'lw-hero' );
		expect( html ).not.toContain( 'lw-overview' );
		expect( count( html, 'role="status"' ) ).toBe( 1 );
	} );

	it( 'draws the "Which sites" card above the bar on a network', () => {
		const site = render( { tab: 'export', network: false } );
		const network = render( { tab: 'export', network: true } );
		const firstCard = ( html ) => html.indexOf( 'lw-admin-section' );
		expect( firstCard( site ) ).toBeGreaterThan(
			site.indexOf( 'lw-composition' )
		);
		expect( firstCard( network ) ).toBeGreaterThan( -1 );
		expect( firstCard( network ) ).toBeLessThan(
			network.indexOf( 'lw-composition' )
		);
	} );

	it( 'draws the Restore section lead, step rail and source tiles when the page opens there', () => {
		const html = render( { tab: 'import' } );
		expect( html ).toContain( 'lw-restore__lead' );
		expect( html ).toContain( 'lw-steps' );
		expect( html ).toContain( 'lw-sources' );
		expect( html ).not.toContain( 'lw-hero' );
		expect( count( html, 'role="status"' ) ).toBe( 1 );
	} );

	it( 'draws the Backups lead, storage strip, list and panel when the page opens there', () => {
		const html = render( { tab: 'backups' } );
		expect( html ).toContain( 'lw-bk-lead' );
		expect( html ).toContain( 'lw-bk-storage' );
		expect( html ).toContain( 'lw-bk-list' );
		expect( html ).toContain( 'lw-bk-detail' );
		expect( html ).not.toContain( 'lw-hero' );
		expect( count( html, 'role="status"' ) ).toBe( 1 );
	} );

	it( 'draws the Destinations lead, two cards and the shelf when the page opens there', () => {
		const html = render( { tab: 'destinations' } );
		expect( html ).toContain( 'lw-lead' );
		expect( count( html, 'class="lw-dest"' ) ).toBe( 2 );
		expect( html ).toContain( 'lw-add__groups' );
		expect( html ).not.toContain( 'lw-hero' );
		expect( count( html, 'role="status"' ) ).toBe( 1 );
	} );

	it( 'draws the Reset lead, five choice tiles and the two-column panel when the page opens there', () => {
		const html = render( { tab: 'reset' } );
		expect( html ).toContain( 'lw-reset-lead' );
		expect( count( html, 'lw-reset-choice is-loading' ) ).toBe( 5 );
		expect( count( html, 'class="lw-reset-plan__part"' ) ).toBe( 2 );
		expect( html ).not.toContain( 'lw-hero' );
		expect( count( html, 'role="status"' ) ).toBe( 1 );
	} );

	it( 'keeps a hero and a card for the other sections', () => {
		const html = render( { tab: 'schedules' } );
		expect( html ).toContain( 'lw-hero' );
		expect( html ).not.toContain( 'lw-composition' );
	} );
} );

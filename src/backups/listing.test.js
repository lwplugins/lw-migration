/**
 * External dependencies
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import {
	chips,
	dayGroups,
	daysAgo,
	toItems,
	visibleItems,
	whenText,
} from './listing';

const DEFAULT_DATE_SETTINGS = getSettings();
const inBudapest = () =>
	setSettings( {
		...DEFAULT_DATE_SETTINGS,
		timezone: {
			...DEFAULT_DATE_SETTINGS.timezone,
			string: 'Europe/Budapest',
			offset: 2,
		},
	} );

afterEach( () => setSettings( DEFAULT_DATE_SETTINGS ) );

// 2026-10-16 08:00 UTC = 10:00 in Budapest.
const NOW = Date.UTC( 2026, 9, 16, 8, 0 ) / 1000;
const HOUR = 3600;

const item = ( name, patch = {} ) => ( {
	id: `own:${ name }`,
	name,
	folder: 'own',
	size: 100,
	mtime: NOW,
	label: '',
	safety: false,
	beforeReset: false,
	kind: 'manual',
	cloud: [],
	links: 0,
	...patch,
} );

describe( 'toItems', () => {
	it( 'puts the newest first and keeps the server kind', () => {
		const rows = toItems( [
			item( 'a.wpress', { mtime: NOW - 100, kind: 'scheduled' } ),
			item( 'b.wpress', { mtime: NOW } ),
		] );
		expect( rows.map( ( r ) => [ r.name, r.kind ] ) ).toEqual( [
			[ 'b.wpress', 'manual' ],
			[ 'a.wpress', 'scheduled' ],
		] );
	} );

	it( 'derives a kind for an item without one (the other folder first)', () => {
		const rows = toItems( [
			item( 'x.wpress', {
				kind: undefined,
				folder: 'external',
				safety: true,
			} ),
			item( 'pre-reset.wpress', {
				kind: undefined,
				safety: true,
				beforeReset: true,
			} ),
			item( 'pre-import.wpress', { kind: undefined, safety: true } ),
			item( 'own.wpress', { kind: undefined } ),
		] );
		expect( rows.map( ( r ) => r.kind ) ).toEqual( [
			'other',
			'safetyReset',
			'safetyRestore',
			'manual',
		] );
	} );

	it( 'gives every row a text label, a link count and a cloud list', () => {
		const [ row ] = toItems( [
			item( 'a.wpress', { label: null, links: 'x', cloud: null } ),
		] );
		expect( row.label ).toBe( '' );
		expect( row.links ).toBe( 0 );
		expect( row.cloud ).toEqual( [] );
		expect( toItems( undefined ) ).toEqual( [] );
	} );
} );

describe( 'chips', () => {
	it( 'counts every kind and leaves out the empty ones', () => {
		const rows = toItems( [
			item( 'a', { kind: 'scheduled' } ),
			item( 'b', { kind: 'scheduled' } ),
			item( 'c', { kind: 'other', folder: 'external' } ),
			item( 'd', { kind: 'safetyRestore' } ),
		] );
		expect( chips( rows ) ).toEqual( [
			{ id: 'all', count: 4 },
			{ id: 'scheduled', count: 2 },
			{ id: 'safetyRestore', count: 1 },
			{ id: 'other', count: 1 },
		] );
	} );

	it( 'shows only All when there is no backup', () => {
		expect( chips( [] ) ).toEqual( [ { id: 'all', count: 0 } ] );
	} );
} );

describe( 'visibleItems', () => {
	const rows = toItems( [
		item( 'site-20261016.wpress', { kind: 'scheduled' } ),
		item( 'site-20261015.wpress', { label: 'Before the MOVE' } ),
	] );

	it( 'narrows by the chosen kind', () => {
		expect(
			visibleItems( rows, 'scheduled', '' ).map( ( r ) => r.name )
		).toEqual( [ 'site-20261016.wpress' ] );
		expect( visibleItems( rows, 'all', '' ) ).toHaveLength( 2 );
	} );

	it( 'searches the name and the label, ignoring case and spaces around', () => {
		expect(
			visibleItems( rows, 'all', '  move ' ).map( ( r ) => r.name )
		).toEqual( [ 'site-20261015.wpress' ] );
		expect(
			visibleItems( rows, 'all', '20261016' ).map( ( r ) => r.name )
		).toEqual( [ 'site-20261016.wpress' ] );
		expect( visibleItems( rows, 'manual', '20261016' ) ).toEqual( [] );
	} );
} );

describe( 'day groups', () => {
	it( 'counts days in the site time zone', () => {
		inBudapest();
		// 23:30 UTC on the 15th is already the 16th in Budapest.
		expect( daysAgo( Date.UTC( 2026, 9, 15, 23, 30 ) / 1000, NOW ) ).toBe(
			0
		);
		expect( daysAgo( Date.UTC( 2026, 9, 15, 21, 30 ) / 1000, NOW ) ).toBe(
			1
		);
		expect( daysAgo( NOW + 86400, NOW ) ).toBe( 0 );
	} );

	it( 'groups today, yesterday, each recent day, then the older ones together', () => {
		inBudapest();
		const rows = toItems( [
			item( 'today', { mtime: NOW - HOUR } ),
			item( 'yesterday-a', { mtime: NOW - 24 * HOUR } ),
			item( 'yesterday-b', { mtime: NOW - 25 * HOUR } ),
			item( 'two-days', { mtime: NOW - 48 * HOUR } ),
			item( 'ten-days', { mtime: NOW - 240 * HOUR } ),
			item( 'month', { mtime: NOW - 720 * HOUR } ),
		] );
		const groups = dayGroups( rows, NOW );
		expect(
			groups.map( ( g ) => [
				g.title,
				g.recent,
				g.items.map( ( r ) => r.name ),
			] )
		).toEqual( [
			[ 'Today', true, [ 'today' ] ],
			[ 'Yesterday', true, [ 'yesterday-a', 'yesterday-b' ] ],
			[ expect.stringContaining( '14' ), true, [ 'two-days' ] ],
			[ 'Older', false, [ 'ten-days', 'month' ] ],
		] );
		expect( new Set( groups.map( ( g ) => g.key ) ).size ).toBe( 4 );
	} );

	it( 'builds its date formatters per list, never per backup', () => {
		inBudapest();
		const rows = ( count ) =>
			toItems(
				Array.from( { length: count }, ( _, index ) =>
					item( `b${ index }`, {
						mtime: NOW - ( index * 192 * HOUR ) / count,
					} )
				)
			);
		const built = ( list ) => {
			const spy = vi.spyOn( Intl, 'DateTimeFormat' );
			try {
				expect( dayGroups( list, NOW ) ).toHaveLength( 8 );
				return spy.mock.calls.length;
			} finally {
				spy.mockRestore();
			}
		};
		expect( built( rows( 300 ) ) ).toBe( built( rows( 30 ) ) );
	} );

	it( 'writes the clock time in a recent group and the day in Older', () => {
		inBudapest();
		expect( whenText( NOW, true ) ).toContain( '10' );
		expect( whenText( NOW, false ) ).toContain( '2026' );
	} );
} );

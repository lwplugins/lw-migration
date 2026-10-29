/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const scss = readFileSync( new URL( './reset.scss', import.meta.url ), 'utf8' );

/**
 * @param {string} value A font-size value, e.g. "11px".
 * @return {string[]} The flat declaration runs that set it.
 */
function blocksSetting( value ) {
	const runs = [];
	const needle = `font-size: ${ value };`;
	let at = scss.indexOf( needle );
	while ( at >= 0 ) {
		const start =
			Math.max(
				scss.lastIndexOf( '{', at ),
				scss.lastIndexOf( '}', at )
			) + 1;
		const ends = [
			scss.indexOf( '}', at ),
			scss.indexOf( '{', at ),
		].filter( ( i ) => i >= 0 );
		runs.push( scss.slice( start, Math.min( ...ends ) ) );
		at = scss.indexOf( needle, at + needle.length );
	}
	return runs;
}

describe( 'reset styles', () => {
	it( 'keeps 11px for uppercase micro labels and tags only (the shared type scale)', () => {
		const runs = blocksSetting( '11px' );
		expect( runs.length ).toBeGreaterThan( 0 );
		runs.forEach( ( run ) =>
			expect( run ).toContain( 'text-transform: uppercase' )
		);
	} );

	it( 'sizes no core control and has no styles left for the old cards and dialog', () => {
		expect( scss ).not.toMatch(
			/\.components-[\w-]+[^{]*\{[^}]*font-size/
		);
		expect( scss ).not.toContain( '.lw-resets' );
		expect( scss ).not.toContain( '.lw-reset-form' );
		expect( scss ).not.toContain( '.lw-reset__' );
	} );

	it( 'colours icons only through :where(), so the footer mark keeps its own', () => {
		const fills = scss.match( /^[^\n]*\n[^\n]*fill: currentColor/gm ) ?? [];
		expect( fills.length ).toBeGreaterThan( 0 );
		expect( scss ).not.toMatch( /(^|[\s,])svg\s*\{/m );
	} );
} );

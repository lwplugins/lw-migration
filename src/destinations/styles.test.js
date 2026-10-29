/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = ( file ) =>
	readFileSync( new URL( file, import.meta.url ), 'utf8' );

/**
 * @param {string} scss  Stylesheet source.
 * @param {string} value A font-size value, e.g. "11px".
 * @return {string[]} The flat declaration runs that set it.
 */
function blocksSetting( scss, value ) {
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

describe( 'destination styles', () => {
	it( 'keeps 11px for uppercase micro labels only (the shared type scale)', () => {
		const runs = blocksSetting( source( './destinations.scss' ), '11px' );
		expect( runs.length ).toBeGreaterThan( 0 );
		runs.forEach( ( run ) =>
			expect( run ).toContain( 'text-transform: uppercase' )
		);
	} );

	it( 'has no styles left for the removed destination dialog', () => {
		expect( source( '../admin.scss' ) ).not.toContain( '.lw-dest-picker' );
	} );
} );

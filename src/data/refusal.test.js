/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { refusalText } from './refusal';

const invalid = ( message, data ) => ( {
	code: 'lw_migration_invalid',
	message,
	data,
} );

describe( 'refusalText', () => {
	it( 'shows a specific server text as is', () => {
		expect(
			refusalText(
				invalid( 'Only http and https addresses can be imported.', {
					field: 'url',
				} ),
				'Our hint.'
			)
		).toBe( 'Only http and https addresses can be imported.' );
	} );

	it( 'uses our hint when the server marks its text generic, in any language', () => {
		[
			'This value is not accepted: excludeTables',
			'Ez az érték nem elfogadható: excludeTables',
		].forEach( ( message ) =>
			expect(
				refusalText(
					invalid( message, {
						field: 'excludeTables',
						generic: true,
					} ),
					'Check this list: one table name per line.'
				)
			).toBe( 'Check this list: one table name per line.' )
		);
	} );

	it( 'reads only a true flag', () => {
		expect(
			refusalText(
				invalid( 'Server text.', { field: 'url', generic: 'true' } ),
				'Hint.'
			)
		).toBe( 'Server text.' );
	} );

	it( 'uses our hint when the server sent no text', () => {
		expect( refusalText( invalid( '', { field: 'url' } ), 'Hint.' ) ).toBe(
			'Hint.'
		);
		expect( refusalText( null, 'Hint.' ) ).toBe( 'Hint.' );
	} );

	it( 'keeps a specific text without data (no room, missing backup)', () => {
		expect(
			refusalText(
				{ message: 'This backup does not exist (any more).' },
				''
			)
		).toBe( 'This backup does not exist (any more).' );
	} );

	it( 'gives an empty text for a generic refusal without a hint', () => {
		expect(
			refusalText(
				invalid( 'x', { field: 'password', generic: true } ),
				''
			)
		).toBe( '' );
	} );
} );

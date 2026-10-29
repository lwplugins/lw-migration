/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { STEPS, restoreStep } from './steps';

const STEP_LIST = [
	'upload',
	'validate',
	'crc',
	'decrypt',
	'confirm',
	'safety_backup',
	'content',
	'done',
];

const job = ( step, status = 'running' ) => ( {
	type: 'import',
	status,
	step,
	steps: STEP_LIST,
	stepIndex: STEP_LIST.indexOf( step ),
} );

describe( 'the step rail', () => {
	it( 'names Source, Check and Restore', () => {
		expect( STEPS ).toEqual( [ 'Source', 'Check', 'Restore' ] );
	} );

	it( 'is at Check until the confirmation is answered', () => {
		expect( restoreStep( job( 'upload' ) ) ).toBe( 1 );
		expect( restoreStep( job( 'decrypt', 'needs_input' ) ) ).toBe( 1 );
		expect( restoreStep( job( 'confirm', 'needs_input' ) ) ).toBe( 1 );
		expect( restoreStep( job( 'confirm', 'cancelled' ) ) ).toBe( 1 );
	} );

	it( 'is at Restore after it, and done when the restore is', () => {
		expect( restoreStep( job( 'safety_backup' ) ) ).toBe( 2 );
		expect( restoreStep( job( 'content', 'failed' ) ) ).toBe( 2 );
		expect( restoreStep( job( 'done', 'done' ) ) ).toBe( 3 );
	} );

	it( 'stays at Check for a job without a confirm step', () => {
		expect(
			restoreStep( { status: 'running', steps: [], stepIndex: 0 } )
		).toBe( 1 );
	} );
} );

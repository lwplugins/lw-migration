/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { barTone, stripBars, stripSummary, triggerLabel } from './history';

const run = ( status, endedAt, trigger = 'cron' ) => ( {
	status,
	endedAt,
	trigger,
} );

describe( 'the run strip', () => {
	it( 'gives every known status its look and anything else the neutral one', () => {
		expect(
			[
				'done',
				'partial',
				'failed',
				'skipped',
				'cancelled',
				'something-new',
				'toString',
				undefined,
			].map( barTone )
		).toEqual( [
			'ok',
			'partial',
			'failed',
			'skipped',
			'unknown',
			'unknown',
			'unknown',
			'unknown',
		] );
	} );

	it( 'maps the history to bars, oldest first, with their words', () => {
		const write = ( ts ) => `at ${ ts }`;
		expect(
			stripBars(
				[
					run( 'done', 10 ),
					run( 'failed', 20, 'manual' ),
					run( 'cancelled', 30 ),
					run( 'something-new', 40 ),
				],
				write
			)
		).toEqual( [
			{ key: '10-0', tone: 'ok', label: 'Ready, at 10 (Schedule)' },
			{ key: '20-1', tone: 'failed', label: 'Failed, at 20 (By hand)' },
			{
				key: '30-2',
				tone: 'unknown',
				label: 'Cancelled, at 30 (Schedule)',
			},
			{
				key: '40-3',
				tone: 'unknown',
				label: 'Unknown, at 40 (Schedule)',
			},
		] );
		expect( stripBars( undefined, write ) ).toEqual( [] );
	} );

	it( 'sums the strip up for a screen reader', () => {
		expect(
			stripSummary( [
				run( 'done', 1 ),
				run( 'done', 2 ),
				run( 'partial', 3 ),
			] )
		).toBe( 'The last 3 runs: 2 ready, 1 not.' );
		expect( stripSummary( [ run( 'done', 1 ) ] ) ).toBe(
			'The last 1 run: 1 ready, 0 not.'
		);
		expect( triggerLabel( 'manual' ) ).toBe( 'By hand' );
	} );
} );

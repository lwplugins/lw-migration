/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	actionsFor,
	confirmText,
	movingTarget,
	targetLabel,
} from './backupFolder';

const VIEW = {
	custom: '',
	hidden: false,
	moving: null,
	problem: null,
	problemText: '',
	editable: true,
	supported: true,
	running: false,
};

const moving = ( to, left = 2 ) => ( {
	to,
	toHidden: false,
	left,
	leftBytes: 1024,
} );

describe( 'actionsFor', () => {
	it( 'offers a move from the default folder', () => {
		expect( actionsFor( VIEW ) ).toEqual( {
			show: [ 'move' ],
			why: '',
			disabled: false,
		} );
	} );

	it( 'offers a move and the way back from a folder of your own', () => {
		expect( actionsFor( { ...VIEW, custom: '/srv/backups' } ) ).toEqual( {
			show: [ 'move', 'backToDefault' ],
			why: '',
			disabled: false,
		} );
	} );

	it( 'keeps both ways out of a folder with a problem', () => {
		expect(
			actionsFor( {
				...VIEW,
				custom: '/srv/backups',
				problem: 'missing',
				problemText: 'The backups folder is missing.',
			} )
		).toEqual( {
			show: [ 'move', 'backToDefault' ],
			why: '',
			disabled: false,
		} );
	} );

	it( 'offers only finish and undo while a move is unfinished', () => {
		expect(
			actionsFor( { ...VIEW, moving: moving( '/srv/backups' ) } )
		).toEqual( { show: [ 'finish', 'undo' ], why: '', disabled: false } );
	} );

	it( 'disables the buttons while a job runs', () => {
		expect( actionsFor( { ...VIEW, running: true } ) ).toEqual( {
			show: [ 'move' ],
			why: '',
			disabled: true,
		} );
		expect( actionsFor( VIEW, true ) ).toEqual( {
			show: [ 'move' ],
			why: '',
			disabled: true,
		} );
		expect(
			actionsFor( { ...VIEW, moving: moving( '' ) }, true ).disabled
		).toBe( true );
	} );

	it( 'disables the way back to the default folder too where no folder of your own works', () => {
		const own = { ...VIEW, custom: '/srv/backups', supported: false };
		expect( actionsFor( own, true ) ).toEqual( {
			show: [ 'backToDefault' ],
			why: 'unsupported',
			disabled: true,
		} );
		expect( actionsFor( { ...own, running: true } ) ).toEqual( {
			show: [ 'backToDefault' ],
			why: 'unsupported',
			disabled: true,
		} );
		expect(
			actionsFor( { ...own, moving: moving( '' ), running: true } )
		).toEqual( { show: [ 'finish' ], why: 'unsupported', disabled: true } );
	} );

	it( 'hides everything when the setting is locked', () => {
		expect(
			actionsFor( { ...VIEW, custom: '/srv/backups', editable: false } )
		).toEqual( { show: [], why: 'locked', disabled: false } );
		expect(
			actionsFor( { ...VIEW, editable: false, supported: false }, true )
		).toEqual( { show: [], why: 'locked', disabled: false } );
	} );

	it( 'keeps only the default folder where no folder of your own works', () => {
		expect( actionsFor( { ...VIEW, supported: false } ) ).toEqual( {
			show: [],
			why: 'unsupported',
			disabled: false,
		} );
		expect(
			actionsFor( { ...VIEW, custom: '/srv/backups', supported: false } )
		).toEqual( {
			show: [ 'backToDefault' ],
			why: 'unsupported',
			disabled: false,
		} );
		expect(
			actionsFor( {
				...VIEW,
				custom: '/srv/backups',
				supported: false,
				moving: moving( '' ),
			} )
		).toEqual( {
			show: [ 'finish' ],
			why: 'unsupported',
			disabled: false,
		} );
		expect(
			actionsFor( {
				...VIEW,
				supported: false,
				moving: moving( '/srv/backups' ),
			} )
		).toEqual( { show: [ 'undo' ], why: 'unsupported', disabled: false } );
	} );
} );

describe( 'targetLabel', () => {
	it( 'names the default folder without the random end of its name', () => {
		expect( targetLabel( '' ) ).toBe(
			'Default: wp-content/lw-migration-…/backups (a protected folder whose name ends in random characters)'
		);
		expect( targetLabel( '/srv/backups' ) ).toBe( '/srv/backups' );
	} );
} );

describe( 'confirmText', () => {
	it( 'says where every action moves the backups', () => {
		const rest =
			' Scheduled backups wait, and no backup or restore can start until it is done. Keep this page open; if it stops, you can finish it later.';
		expect( confirmText( { path: '/srv/backups' } ) ).toBe(
			'Move every backup to this folder?' + rest
		);
		expect( confirmText( { path: '' } ) ).toBe(
			'Move every backup to the default folder?' + rest
		);
		expect( confirmText( { action: 'finish' } ) ).toBe(
			'Finish moving the backups to the new folder?' + rest
		);
		expect( confirmText( { action: 'undo' } ) ).toBe(
			'Move the backups back to the old folder?' + rest
		);
	} );
} );

describe( 'movingTarget', () => {
	it( 'names the target as the server shows it, the default one by name', () => {
		expect( movingTarget( moving( '/srv/backups' ) ) ).toBe(
			'/srv/backups'
		);
		expect(
			movingTarget( {
				...moving( '/srv/site/wp-content/lw-migration-backups-…' ),
				toHidden: true,
			} )
		).toBe( '/srv/site/wp-content/lw-migration-backups-…' );
		expect( movingTarget( moving( '' ) ) ).toBe( 'the default folder' );
	} );
} );

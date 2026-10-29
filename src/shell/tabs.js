/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	backup,
	cloud,
	cog,
	download,
	home,
	link,
	scheduled,
	trash,
	upload,
} from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { NETWORK } from '../data/boot';

/**
 * Tab registry (spec 11). `store` names the save store the top bar drives
 * on that tab (null = no Save button).
 */
export const TABS = [
	// The home page: the first section, where the plugin's page opens.
	{
		id: 'overview',
		label: __( 'Overview', 'lw-migration' ),
		title: __( 'Overview', 'lw-migration' ),
		icon: home,
		store: null,
	},
	{
		id: 'export',
		label: __( 'Backup', 'lw-migration' ),
		title: NETWORK
			? __( 'Back up this network', 'lw-migration' )
			: __( 'Back up this site', 'lw-migration' ),
		icon: download,
		store: null,
	},
	{
		id: 'import',
		label: __( 'Restore', 'lw-migration' ),
		title: NETWORK
			? __( 'Restore or move sites', 'lw-migration' )
			: __( 'Restore or move a site', 'lw-migration' ),
		icon: upload,
		store: null,
	},
	{
		id: 'backups',
		label: __( 'Backups', 'lw-migration' ),
		title: __( 'Backups', 'lw-migration' ),
		icon: backup,
		store: null,
	},
	{
		id: 'schedules',
		label: __( 'Schedules', 'lw-migration' ),
		title: __( 'Scheduled backups', 'lw-migration' ),
		icon: scheduled,
		store: null,
	},
	{
		id: 'destinations',
		label: __( 'Destinations', 'lw-migration' ),
		title: __( 'Cloud destinations', 'lw-migration' ),
		icon: cloud,
		store: null,
	},
	// Connections join two single sites (spec 19): not in the network app.
	...( NETWORK
		? []
		: [
				{
					id: 'connections',
					label: __( 'Connections', 'lw-migration' ),
					title: __( 'Connected sites', 'lw-migration' ),
					icon: link,
					store: null,
				},
			] ),
	{
		id: 'reset',
		label: __( 'Reset', 'lw-migration' ),
		title: NETWORK
			? __( 'Reset this network', 'lw-migration' )
			: __( 'Reset this site', 'lw-migration' ),
		icon: trash,
		store: null,
	},
	{
		id: 'settings',
		label: __( 'Settings', 'lw-migration' ),
		title: __( 'Settings', 'lw-migration' ),
		icon: cog,
		store: 'settings',
	},
];

export const TAB_IDS = TABS.map( ( tab ) => tab.id );

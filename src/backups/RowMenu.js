/**
 * WordPress dependencies
 */
import { DropdownMenu } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	cloudUpload,
	download,
	link,
	listView,
	moreHorizontal,
	tag,
	trash,
	upload,
} from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { OWN_FOLDER } from '../data/boot';

/**
 * The menu items of one backup, in the panel's order. Upload… only with a
 * destination, Label only in our own folder.
 *
 * @param {Object}  item       Backup row.
 * @param {Object}  actions    The view's backup actions.
 * @param {boolean} busy       A job runs (no restore, no upload).
 * @param {boolean} withUpload A destination exists.
 * @param {boolean} preparing  This backup's download is being prepared.
 * @return {Object[]} DropdownMenu controls.
 */
export function menuControls(
	item,
	actions,
	busy,
	withUpload,
	preparing = false
) {
	return [
		{
			title: __( 'Restore', 'lw-migration' ),
			icon: upload,
			isDisabled: busy,
			onClick: () => actions.restore( item ),
		},
		{
			title: __( 'Download', 'lw-migration' ),
			icon: download,
			isDisabled: preparing,
			onClick: () => actions.download( item ),
		},
		withUpload && {
			title: __( 'Upload to the cloud…', 'lw-migration' ),
			icon: cloudUpload,
			isDisabled: busy,
			onClick: () => actions.open( 'upload', item, 'menu' ),
		},
		{
			title: __( 'Transfer link', 'lw-migration' ),
			icon: link,
			onClick: () => actions.open( 'share', item, 'menu' ),
		},
		{
			title: __( 'Files', 'lw-migration' ),
			icon: listView,
			onClick: () => actions.open( 'browse', item, 'menu' ),
		},
		OWN_FOLDER === item.folder && {
			title: __( 'Label', 'lw-migration' ),
			icon: tag,
			onClick: () => actions.open( 'label', item, 'menu' ),
		},
		{
			title: __( 'Delete', 'lw-migration' ),
			icon: trash,
			onClick: () => actions.open( 'delete', item, 'menu' ),
		},
	].filter( Boolean );
}

/**
 * The ⋯ button of a row and its menu.
 *
 * @param {Object}  props
 * @param {Object}  props.item       Backup row.
 * @param {Object}  props.actions    The view's backup actions.
 * @param {boolean} props.busy       A job runs.
 * @param {boolean} props.withUpload A destination exists.
 * @param {boolean} props.preparing  This backup's download is being prepared.
 */
export default function RowMenu( {
	item,
	actions,
	busy,
	withUpload,
	preparing = false,
} ) {
	return (
		<DropdownMenu
			icon={ moreHorizontal }
			label={ sprintf(
				/* translators: %s: backup file name. */
				__( 'More for %s', 'lw-migration' ),
				item.name
			) }
			toggleProps={ {
				size: 'compact',
				className: 'lw-bk-row__more',
				'data-lw-backup': item.id,
			} }
			popoverProps={ {
				className: 'lw-row-menu',
				placement: 'bottom-end',
			} }
			controls={ menuControls(
				item,
				actions,
				busy,
				withUpload,
				preparing
			) }
		/>
	);
}

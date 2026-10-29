/**
 * Internal dependencies
 */
import DeleteModal from '../views/DeleteModal';
import EntriesModal from '../views/EntriesModal';
import LabelModal from '../views/LabelModal';
import ShareModal from './ShareModal';
import UploadModal from './UploadModal';

/**
 * The open dialog of a backup action (each keeps its own request and
 * refusals): label, entries, transfer link, delete, upload to the cloud.
 *
 * @param {Object}                props
 * @param {Object|null}           props.open         `{ what, backup }` or null.
 * @param {Object[]}              props.destinations Destination views.
 * @param {() => void}            props.onClose      Close without a change.
 * @param {() => void}            props.onChanged    Close after a change (read the lists again).
 * @param {() => void}            props.onDeleted    After a delete.
 * @param {() => void}            props.onGone       The backup is not there any more.
 * @param {(job: Object) => void} props.onStart      Receives a started upload job.
 */
export default function BackupDialogs( {
	open,
	destinations,
	onClose,
	onChanged,
	onDeleted,
	onGone,
	onStart,
} ) {
	const backup = open?.backup;
	switch ( open?.what ) {
		case 'label':
			return (
				<LabelModal
					backup={ backup }
					onClose={ onClose }
					onSaved={ onChanged }
					onGone={ onGone }
				/>
			);
		case 'browse':
			return (
				<EntriesModal
					backup={ backup }
					onClose={ onClose }
					onGone={ onGone }
				/>
			);
		case 'share':
			// Links made or revoked in the dialog show in the panel after it.
			return (
				<ShareModal
					backup={ backup }
					onClose={ onChanged }
					onGone={ onGone }
				/>
			);
		case 'delete':
			return (
				<DeleteModal
					backup={ backup }
					onClose={ onClose }
					onDeleted={ onDeleted }
					onGone={ onGone }
				/>
			);
		case 'upload':
			return (
				<UploadModal
					backup={ backup }
					destinations={ destinations }
					onClose={ onClose }
					onStart={ onStart }
				/>
			);
		default:
			return null;
	}
}

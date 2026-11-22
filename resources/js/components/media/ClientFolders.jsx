import { ArrowUpRight, Folder, Link2, LockKeyhole } from 'lucide-react';

export const sharingLabel = {
    direct: 'Shared with client',
    inherited: 'Shared through parent',
    partial: 'Some subfolders shared',
    private: 'Private to your team',
};

export default function ClientFolders({ folders, layout, onOpen }) {
    return <div className={`md-client-folders ${layout === 'list' ? 'md-client-folders-list' : ''}`} aria-label="Client folders">
        {folders.map(folder => {
            const shared = ['direct', 'inherited', 'partial'].includes(folder.sharing);
            const AccessIcon = shared ? Link2 : LockKeyhole;
            const clientDescription = [folder.client?.reference, folder.client?.name !== folder.name ? folder.client?.name : null].filter(Boolean).join(' · ') || 'Client folder';
            return <button type="button" className="md-client-folder-card" key={folder.id} onClick={() => onOpen(folder.id)}>
                <span className="md-client-folder-icon"><Folder size={27} strokeWidth={1.5} /></span>
                <span className={`md-folder-access ${shared ? 'md-folder-access-shared' : ''}`} title={sharingLabel[folder.sharing] || sharingLabel.private}>
                    <AccessIcon size={12} />{folder.sharing === 'partial' ? 'Partly shared' : shared ? 'Shared' : 'Private'}
                </span>
                <span className="md-client-folder-info"><strong>{folder.name}</strong><small>{clientDescription}</small></span>
                <span className="md-client-folder-footer"><span>{folder.file_count ?? 0} {folder.file_count === 1 ? 'file' : 'files'}</span><ArrowUpRight size={16} aria-hidden="true" /></span>
            </button>;
        })}
    </div>;
}

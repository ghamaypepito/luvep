import React from 'react';

// Read-only references to the existing staff-note attachment endpoints. These
// are not LeadDocuments and must not enter checklist approval or client views.
export default function EducationNoteDocuments({ notes = [], portalBase, currentUser = null }) {
    const educationView = portalBase === '/portal/education'
        || (portalBase === '/admin' && currentUser?.role === 'education');
    if (!educationView) return null;

    const files = notes.flatMap(note => (note.attachments || []).map((file, index) => ({
        ...file, key: `${note.id}-${index}`, author: note.author_name, createdAt: note.created_at,
    })));

    return <section aria-label="Other Documents - Internal note attachments" className="mt-5 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div className="border-b border-gray-100 bg-gray-50/40 px-6 py-4">
            <h3 className="text-sm font-bold uppercase tracking-wider text-gray-700">Other Documents - Internal note attachments ({files.length})</h3>
            <p className="mt-1 text-xs text-gray-500">Files attached in Overview, including earlier notes and replies. Staff only; these do not change checklist completion. Manage the original attachment through its note.</p>
        </div>
        {files.length ? <ul className="divide-y divide-gray-100">
            {files.map(file => <li key={file.key} className="flex flex-wrap items-center gap-3 px-6 py-3">
                <div className="min-w-0 flex-1">
                    <p className="break-words text-sm font-medium text-gray-800">{file.name || 'Attachment'}</p>
                    <p className="text-xs text-gray-500">Internal note{file.author ? ` / ${file.author}` : ''}{file.createdAt ? ` / ${new Date(file.createdAt).toLocaleString('en-NZ')}` : ''}</p>
                </div>
                <a href={`${file.url}?inline=1`} target="_blank" rel="noreferrer" className="text-sm font-medium text-blue-700 underline" aria-label={`View ${file.name || 'attachment'}`}>View</a>
                <a href={file.url} className="text-sm font-medium text-emerald-700 underline" aria-label={`Download ${file.name || 'attachment'}`}>Download</a>
            </li>)}
        </ul> : <p className="px-6 py-4 text-sm text-gray-500">No files attached to internal notes yet.</p>}
    </section>;
}

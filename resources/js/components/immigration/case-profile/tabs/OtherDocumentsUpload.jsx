import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';

export default function OtherDocumentsUpload({ leadId, onUploaded }) {
    const { auth } = usePage().props;
    const input = useRef(null);
    const busyRef = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    if (!['admin', 'super_admin', 'immigration', 'immigration_manager', 'immigration_adviser'].includes(auth?.user?.role)) return null;

    const upload = event => {
        const files = Array.from(event.target.files || []);
        if (!files.length || busyRef.current) return;
        setError(''); setSuccess('');
        if (files.length > 10) {
            setError('Choose up to 10 files per batch. You can upload another batch afterward.');
            event.target.value = '';
            return;
        }
        if (files.some(file => file.size > 50 * 1024 * 1024)) {
            setError('Each file must be 50 MB or smaller.');
            event.target.value = '';
            return;
        }
        busyRef.current = true; setBusy(true);
        router.post(`/portal/immigration/cases/${leadId}/documents/other`, { files }, {
            forceFormData: true, preserveScroll: true, preserveState: true,
            only: ['documents', 'unstructuredDocuments', 'checklistProgress', 'activity', 'flash'],
            onSuccess: () => { setSuccess(`${files.length} supporting document${files.length === 1 ? '' : 's'} uploaded under Other (no checklist match).`); onUploaded?.(); },
            onError: errors => setError(Object.values(errors)[0] || 'Upload failed. Check File history before retrying.'),
            onFinish: () => { busyRef.current = false; setBusy(false); if (input.current) input.current.value = ''; },
        });
    };

    return <section aria-label="Upload other documents" className="rounded-xl border border-dashed border-gray-200 bg-gray-50/50 px-4 py-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p className="text-sm font-semibold text-gray-800">Other supporting documents</p>
                <p className="mt-1 text-xs text-gray-500">Add more files without replacing existing uploads. Up to 10 files per batch, 50 MB each. Upload another batch whenever needed.</p>
                <p className="mt-1 text-xs text-gray-500">PDF, JPG, PNG, Word or Excel. This uploads directly; it does not send a document request email.</p>
            </div>
            <button type="button" disabled={busy} onClick={() => input.current?.click()} className="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Uploading...' : 'Upload other documents'}</button>
            <input ref={input} type="file" multiple hidden accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" aria-label="Choose other supporting documents" onChange={upload} />
        </div>
        {error && <p role="alert" className="mt-2 text-sm text-red-700">{error}</p>}
        {success && <p role="status" className="mt-2 text-sm text-emerald-700">{success}</p>}
    </section>;
}

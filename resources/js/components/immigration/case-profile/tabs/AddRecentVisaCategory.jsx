import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';

export default function AddRecentVisaCategory({ leadId, exists, onAdded }) {
    const { auth } = usePage().props;
    const saving = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    if (exists || !['admin', 'super_admin', 'immigration', 'immigration_manager', 'immigration_adviser'].includes(auth?.user?.role)) return null;

    const add = () => {
        if (saving.current) return;
        saving.current = true;
        setBusy(true);
        setError('');
        router.post(`/portal/immigration/cases/${leadId}/documents/recent-visa-category`, {}, {
            preserveScroll: true, preserveState: true,
            only: ['checklist', 'checklistGrouped', 'checklistProgress', 'activity', 'flash'],
            onSuccess: () => onAdded?.(),
            onError: errors => setError(Object.values(errors).flat().join(' ') || 'Could not add this upload category.'),
            onFinish: () => { saving.current = false; setBusy(false); },
        });
    };

    return <section aria-label="Add recent visa upload category" className="rounded-xl border border-gray-200 bg-white px-4 py-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p className="text-sm font-semibold text-gray-800">Recent visa / eVisa upload</p>
                <p className="mt-1 text-xs text-gray-500">Add an optional upload category, visible to this client.</p>
            </div>
            <button type="button" disabled={busy} onClick={add} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800 hover:border-gray-900 disabled:opacity-50">
                {busy ? 'Adding category…' : 'Add Recent visa / eVisa category'}
            </button>
        </div>
        {error && <p role="alert" className="mt-2 text-sm text-red-700">{error}</p>}
    </section>;
}

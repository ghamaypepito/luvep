import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { toast } from 'sonner';

export default function SendAssessmentButton({ leadId }) {
    const [busy, setBusy] = useState(false);
    const role = usePage().props.auth?.user?.role;
    if (!['admin', 'super_admin', 'immigration', 'immigration_manager', 'immigration_adviser'].includes(role)) return null;

    const send = () => {
        setBusy(true);
        router.post(`/portal/immigration/cases/${leadId}/assessment-invitation`, {}, {
            preserveScroll: true,
            onSuccess: (page) => toast.success(page.props.flash?.success || 'Assessment request prepared'),
            onError: (errors) => toast.error(Object.values(errors)[0] || 'Could not prepare assessment invitation'),
            onFinish: () => setBusy(false),
        });
    };

    return <button type="button" disabled={busy} onClick={send}
        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-md text-xs font-semibold border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-50">
        <Send size={12} /> {busy ? 'Preparing…' : 'Send Assessment Form'}
    </button>;
}

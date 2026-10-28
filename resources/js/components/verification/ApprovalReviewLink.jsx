import { Link, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

export default function ApprovalReviewLink({ leadId, kind = 'proposal', status }) {
    const modules = usePage().props.auth?.modules || [];
    if (!modules.includes(`program_verification.${kind}`) || !['pending', 'verified'].includes(status)) return null;
    const path = kind === 'consultancy' ? '/consultancy-verification' : '/program-verification';
    return <Link href={`${path}?lead_id=${leadId}`} className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 whitespace-nowrap">
        <ShieldCheck size={14} /> Review & approve
    </Link>;
}

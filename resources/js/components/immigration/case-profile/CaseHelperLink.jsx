import { Link, usePage } from '@inertiajs/react';

export default function CaseHelperLink({ leadId }) {
    const { auth } = usePage().props;
    if (!['admin', 'super_admin', 'immigration_manager'].includes(auth?.user?.role)) return null;
    return <Link href={`/portal/immigration/cases/${leadId}/helper`} className="inline-flex rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700">Manage case helper access</Link>;
}

import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight } from 'lucide-react';

export default function HrReturnLink({ hr }) {
    const adminHref = hr?.admin_return_url;
    let sameOrigin = false;
    if (adminHref && typeof window !== 'undefined') {
        try {
            sameOrigin = new URL(adminHref, window.location.href).origin === window.location.origin;
        } catch {
            // Preserve normal link behavior if the configured URL is invalid.
        }
    }

    if (sameOrigin) {
        return <Link href={adminHref}><ArrowLeft size={16} /> Back to Admin</Link>;
    }

    return <a href={adminHref || hr?.main_url || '/'}>
        {adminHref ? 'Back to Admin' : 'Back to Luvep'} <ArrowUpRight size={16} />
    </a>;
}

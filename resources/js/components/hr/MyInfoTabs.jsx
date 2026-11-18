import { Link, usePage } from '@inertiajs/react';
import { hrPath } from '@/lib/hrPath';

export default function MyInfoTabs() {
    const { hr, auth } = usePage().props;
    const admin = !hr.preview && ['admin', 'super_admin'].includes(auth?.user?.role);
    const documentManager = admin || Object.entries(hr.permissions || {}).some(([key, allowed]) => key.startsWith('documents.') && allowed);
    const equipmentManager = admin || hr.permissions?.['operations.manage'];
    const path = usePage().url.split('?')[0];
    const tabs = [
        ['/profile', 'Profile & employment'],
        ...(hr.employee ? [['/documents?scope=mine', 'My documents']] : documentManager ? [['/documents', 'Employee documents']] : []),
        ['/policies?scope=mine', 'Policies & guide'],
        ...(hr.employee ? [['/equipment?scope=mine', 'My equipment']] : equipmentManager ? [['/equipment', 'Equipment']] : []),
    ];
    return <nav className="hr-info-tabs" aria-label="My Info">
        {tabs.map(([href, label]) => <Link key={href} href={hrPath(href)} aria-current={path === hrPath(href.split('?')[0]) ? 'page' : undefined}>{label}</Link>)}
    </nav>;
}

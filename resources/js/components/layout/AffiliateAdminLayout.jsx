import { ArrowLeft, ChartNoAxesCombined, FileSignature, Users } from 'lucide-react';
import AffiliateWorkspaceLayout from './AffiliateWorkspaceLayout';

const nav = [
    { name: 'Affiliate Overview', href: '/admin/agents', icon: <ChartNoAxesCombined size={19} /> },
    { name: 'All Leads', href: '/admin/leads', icon: <Users size={19} /> },
    { name: 'Affiliate Agreements', href: '/admin/agents/agreements', icon: <FileSignature size={19} /> },
];
const footerNav = [{ name: 'Back to Admin', href: '/admin/dashboard', icon: <ArrowLeft size={18} /> }];

export default function AffiliateAdminLayout({ children }) {
    return <AffiliateWorkspaceLayout nav={nav} subtitle="Affiliate Partner Management" footerNav={footerNav}>{children}</AffiliateWorkspaceLayout>;
}

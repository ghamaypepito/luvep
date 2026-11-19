import AffiliateWorkspaceLayout from "./AffiliateWorkspaceLayout";
import { usePage } from "@inertiajs/react";
import { LayoutDashboard, Users, UserCircle, Mail, FileSignature, GraduationCap, Briefcase, Link as LinkIcon } from "lucide-react";

// Hide a nav item whose `module` hasn't been granted to this user. `modules` is
// auth.modules — the granted-key list from User::grantedModules(); super admins
// get every key. Mirrors gateNav in AdminLayout; a section header with nothing
// left under it is dropped so the sidebar doesn't show an empty group.
function gateNav(items, modules) {
    const kept = items.filter((it) => !it.module || modules.includes(it.module));

    return kept.filter((it, i) => {
        if (!it.section) return true;
        const next = kept[i + 1];

        return next && !next.section;
    });
}

// Agent Portal sidebar — deliberately lean. Recruiting agents add leads and
// edit their info; they don't run the pipeline, so no bookings/reports/etc.
// Badges come from the globally-shared `sidebarBadges` prop.
export default function AgentLayout({ children }) {
    const { props } = usePage();
    const badges = props?.sidebarBadges?.agent || {};
    const modules = props?.auth?.modules || [];

    const AGENT_NAV = [
        { name: "Dashboard", href: "/portal/agent/dashboard", icon: <LayoutDashboard size={20} /> },

        { name: "Work", section: true },
        { name: "My Leads", href: "/portal/agent/leads", icon: <Users size={20} />, badge: badges.new_leads_today, badgeTone: "default" },
        // Read-only view of the students this agent's referrals became. Ships
        // hidden — a super admin grants `referral_students` per agent from
        // Module Management. The route carries the same gate.
        { name: "Students", href: "/portal/agent/students", icon: <GraduationCap size={20} />, module: "referral_students" },
        // The agent's own referrals that became immigration cases. Ships hidden —
        // a super admin grants `referral_cases` per agent from Module Management.
        { name: "Cases", href: "/portal/agent/cases", icon: <Briefcase size={20} />, module: "referral_cases" },
        ...(props?.auth?.user?.role === 'agent' ? [{ name: "Assigned cases", href: "/portal/agent/assigned-cases", icon: <Briefcase size={20} />, module: "assigned_immigration_cases" }] : []),
        { name: "Compose", href: "/portal/agent/compose", icon: <Mail size={20} /> },

        { name: "Account", section: true },
        { name: "My Profile", href: "/portal/agent/profile", icon: <UserCircle size={20} /> },
        { name: "My Affiliate Link", href: "/portal/agent/profile#affiliate-link", icon: <LinkIcon size={20} /> },
        { name: "My Affiliate Agreement", href: "/portal/agent/agreement", icon: <FileSignature size={20} /> },
    ];

    return (
        <AffiliateWorkspaceLayout subtitle="Affiliate Partner Portal" nav={gateNav(AGENT_NAV, modules)}>
            {children}
        </AffiliateWorkspaceLayout>
    );
}

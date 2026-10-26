import { usePage } from "@inertiajs/react";
import DashboardLayout from "./DashboardLayout";
import {
    Home, Users, UserCog, History, Calendar as CalendarIcon, BookOpen,
    GraduationCap, Video, Globe, FileText, Star, LayoutDashboard,
    Briefcase, Languages, Building2, KeyRound, Sparkles, Tag,
    Radio, PenLine, CalendarDays, Inbox, Megaphone, BarChart3,
    CheckSquare, Clock, Crown, LifeBuoy, Mail, MessageSquare, Smartphone,
    Wallet, UserCheck, Wrench, Award, User, BadgeCheck, Send, Boxes,
} from "lucide-react";

// Super-admin-only entry — only injected when the current user holds
// the `super_admin` role. The route itself is also gated server-side
// (portal:super_admin in routes/web.php), so this filter is purely
// cosmetic — admins won't even see the link.
const SUPER_ADMIN_NAV = {
    name: "Super Dashboard",
    href: "/admin/super-dashboard",
    icon: <Crown size={20} />,
};

// Also super-admin-only, but belongs at the bottom next to the other
// system-level controls rather than up top with the dashboard.
const SUPER_ADMIN_MAINTENANCE_NAV = {
    name: "Maintenance Mode",
    href: "/admin/maintenance",
    icon: <Wrench size={20} />,
};

// Super-admin-only — grant per-user access to restricted modules.
const SUPER_ADMIN_MODULE_NAV = {
    name: "Module Management",
    href: "/admin/module-management",
    icon: <Boxes size={20} />,
};

// Drop nav items gated by a restricted module the user can't see. Items with
// no `module` field are grandfathered and always shown; a group is removed if
// gating empties its children. `modules` is the granted-key list from
// auth.modules (super admins get every key).
function gateNav(items, modules) {
    return items
        .filter((it) => ! it.module || modules.includes(it.module))
        .map((it) => (it.children ? { ...it, children: gateNav(it.children, modules) } : it))
        .filter((it) => ! it.children || it.children.length > 0);
}

const ADMIN_NAV = [
    { name: "Dashboard", href: "/admin/dashboard", icon: <Home size={20} /> },
    {
        name: "Leads",
        icon: <Users size={20} />,
        children: [
            { name: "List of Leads",         href: "/admin/leads",                      icon: <Users size={16} /> },
            { name: "Proposal & Agreements", href: "/admin/leads/proposals-agreements", icon: <FileText size={16} /> },
        ],
    },
    { name: "Document Queue", href: "/admin/document-queue", icon: <Inbox size={20} /> },
    { name: "Portal Invitations", href: "/admin/portal-invitations", icon: <KeyRound size={20} />, module: "portal_invitation" },
    { name: "Events", href: "/admin/events", icon: <CalendarIcon size={20} /> },
    { name: "Bookings", href: "/admin/booking", icon: <BookOpen size={20} /> },
    { name: "Daily Time Record", href: "/admin/dtr", icon: <Clock size={20} /> },
    { name: "Availability", href: "/admin/availability", icon: <Clock size={20} /> },
    { name: "Programs", href: "/admin/programs", icon: <GraduationCap size={20} /> },
    { name: "Schools",  href: "/admin/schools",  icon: <Building2 size={20} /> },
    { name: "Promotions", href: "/admin/promos", icon: <Tag size={20} /> },
    { name: "Facebook Live", href: "/admin/facebook-live", icon: <Video size={20} /> },
    { name: "Visa Approved", href: "/admin/visa-approvals", icon: <Award size={20} /> },
    // Restricted module — hidden unless granted (default super-admin-only).
    { name: "Agents", href: "/admin/agents", icon: <UserCheck size={20} />, module: "agents" },
    {
        name: "Verification",
        icon: <BadgeCheck size={20} />,
        children: [
            { name: "Proposal", href: "/program-verification", icon: <FileText size={16} />, module: "program_verification.proposal" },
            { name: "Consultancy Agreement", href: "/consultancy-verification", icon: <Wallet size={16} />, module: "program_verification.consultancy" },
        ],
    },
    {
        name: "Social",
        icon: <Radio size={20} />,
        children: [
            { name: "Compose",     href: "/social/compose",     icon: <PenLine size={16} /> },
            { name: "Scheduled",   href: "/social/scheduled",   icon: <CalendarDays size={16} /> },
            { name: "Inbox",       href: "/social/inbox",       icon: <Inbox size={16} /> },
            { name: "Ads",         href: "/social/ads",         icon: <Megaphone size={16} /> },
            { name: "Performance", href: "/social/performance", icon: <BarChart3 size={16} /> },
            { name: "Accounts",    href: "/social/accounts",    icon: <Users size={16} /> },
        ],
    },
    // Single User Reviews page — tabs between Immigration and Education
    // inside the page so staff doesn't have to bounce between sidebar links.
    { name: "User Reviews", href: "/admin/user-reviews", icon: <Star size={20} /> },
    { name: "Video Testimonials", href: "/admin/video-testimonials", icon: <Video size={20} /> },
    {
        name: "Email",
        icon: <Mail size={20} />,
        children: [
            { name: "Templates", href: "/admin/message-templates", icon: <PenLine size={16} /> },
            { name: "Automation", href: "/admin/email-automation", icon: <Send size={16} /> },
            { name: "Compose", href: "/admin/email/compose", icon: <Send size={16} /> },
            { name: "Bulk Mail", href: "/admin/email/bulk", icon: <Megaphone size={16} /> },
            { name: "SMS", href: "/admin/email/sms", icon: <Smartphone size={16} /> },
            { name: "Replies", href: "/admin/email/replies", icon: <MessageSquare size={16} /> },
        ],
    },
    { name: "System Tickets", href: "/admin/system-tickets", icon: <LifeBuoy size={20} /> },
    {
        name: "Immigration",
        icon: <Globe size={20} />,
        children: [
            { name: "Dashboard", href: "/portal/immigration/dashboard", icon: <LayoutDashboard size={16} /> },
        ],
    },
    {
        // Cross-portal view — admin can open any role's dashboard.
        name: "Portals",
        icon: <LayoutDashboard size={20} />,
        children: [
            { name: "Sales Portal", href: "/portal/sales/dashboard", icon: <Briefcase size={16} /> },
            { name: "Education Portal", href: "/portal/education/dashboard", icon: <GraduationCap size={16} /> },
            { name: "English Portal", href: "/portal/english/dashboard", icon: <Languages size={16} /> },
            { name: "Immigration Portal", href: "/portal/immigration/dashboard", icon: <Globe size={16} /> },
            { name: "Immigration Adviser Portal", href: "/portal/immigration-adviser/dashboard", icon: <BadgeCheck size={16} /> },
            { name: "Accommodation Portal", href: "/portal/accommodation/dashboard", icon: <Building2 size={16} /> },
            { name: "Finance Portal", href: "/portal/finance/dashboard", icon: <Wallet size={16} /> },
            { name: "Agent Portal", href: "/portal/agent/dashboard", icon: <UserCheck size={16} /> },
            { name: "Client Portal", href: "/portal/lead/dashboard", icon: <User size={16} /> },
        ],
    },
    { name: "User Management", href: "/admin/users", icon: <UserCog size={20} /> },
    { name: "All Tasks", href: "/admin/tasks", icon: <CheckSquare size={20} /> },
    { name: "Activity Log", href: "/admin/activity-logs", icon: <History size={20} /> },
];

export default function AdminLayout({ children }) {
    const { props } = usePage();
    const isSuperAdmin = props?.auth?.user?.role === "super_admin";
    const modules = props?.auth?.modules || [];
    // Slip the Super Dashboard link in at the top for super-admins; Module
    // Management + Maintenance sit at the bottom with the system controls.
    const baseNav = isSuperAdmin
        ? [SUPER_ADMIN_NAV, ...ADMIN_NAV, { name: "AI Connectors", href: "/admin/ai-connectors", icon: <KeyRound size={20} /> }, SUPER_ADMIN_MODULE_NAV, SUPER_ADMIN_MAINTENANCE_NAV]
        : ADMIN_NAV;
    // Hide any restricted-module items the user hasn't been granted. No-op for
    // the current nav (nothing is gated yet) — ready for future modules.
    const nav = gateNav(baseNav, modules);

    return (
        <DashboardLayout
            brand="EP."
            subtitle={isSuperAdmin ? "Super Admin" : "Admin Panel"}
            accent="bg-gray-900"
            nav={nav}
            settingsHref="/admin/settings"
        >
            {children}
        </DashboardLayout>
    );
}

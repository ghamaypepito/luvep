import DashboardLayout from "./DashboardLayout";
import { usePage } from "@inertiajs/react";
import {
    LayoutDashboard, ClipboardCheck, UserSquare2, Globe, FolderOpen,
    Calendar, FileBadge, FileText, LineChart,
    User, Bell, CheckSquare, Ticket, Mail,
    PenLine, Megaphone, Smartphone, MessageSquare,
    FileSignature, ReceiptText, GraduationCap, Award,
    Clock,
} from "lucide-react";

export default function ImmigrationLayout({ children }) {
    const { props } = usePage();
    const badges = props?.sidebarBadges?.immigration || {};

    const NAV = [
        { name: "Dashboard", href: "/portal/immigration/dashboard", icon: <LayoutDashboard size={20} /> },

        { name: "Work", section: true },
        { name: "Visa Assessment", href: "/portal/immigration/assessments",  icon: <ClipboardCheck size={20} />, badge: badges.new_assessments, badgeTone: "default" },
        {
            name: "Leads",
            icon: <UserSquare2 size={20} />,
            badge: badges.new_leads_today,
            badgeTone: "default",
            children: [
                { name: "List of Leads",         href: "/portal/immigration/leads",                      icon: <UserSquare2 size={16} /> },
                { name: "Proposal & Agreements", href: "/portal/immigration/leads/proposals-agreements", icon: <FileText size={16} /> },
            ],
        },
        {
            name: "Case",
            icon: <Globe size={20} />,
            badge: badges.active_cases,
            badgeTone: "default",
            children: [
                { name: "List of Cases", href: "/portal/immigration/cases",            icon: <Globe size={16} /> },
                { name: "Engagement",    href: "/portal/immigration/cases/engagement", icon: <FileSignature size={16} /> },
                { name: "Invoice",       href: "/portal/immigration/cases/invoice",    icon: <ReceiptText size={16} /> },
                { name: "Initial Agreement", href: "/portal/immigration/client-documents", icon: <FileText size={16} /> },
                { name: "INZ Forms",     href: "/portal/immigration/cases/inz-forms",  icon: <FileText size={16} /> },
            ],
        },
        // Shared with Education + Sales — same screen, this portal's layout.
        { name: "Students",     href: "/portal/immigration/students",     icon: <GraduationCap size={20} /> },
        { name: "Documents",    href: "/portal/immigration/documents",    icon: <FolderOpen size={20} />,     badge: badges.docs_pending_review, badgeTone: "teal" },
        { name: "Task Board",   href: "/portal/immigration/tasks",        icon: <CheckSquare size={20} /> },
        { name: "Appointments", href: "/portal/immigration/appointments", icon: <Calendar size={20} /> },

        { name: "Setup", section: true },
        { name: "Visas",               href: "/portal/immigration/visa-types",          icon: <FileBadge size={20} /> },
        { name: "INZ Forms",           href: "/portal/immigration/inz-forms",           icon: <FileText size={20} /> },

        {
            name: "Email",
            icon: <Mail size={20} />,
            children: [
                { name: "Templates", href: "/portal/immigration/email-templates", icon: <PenLine size={16} /> },
                { name: "Compose", href: "/portal/immigration/compose", icon: <Mail size={16} /> },
                { name: "Bulk Mail", href: "/portal/immigration/bulk-email",      icon: <Megaphone size={16} /> },
                { name: "SMS",       href: "/portal/immigration/sms",             icon: <Smartphone size={16} /> },
                { name: "Replies",   href: "/portal/immigration/email/replies",   icon: <MessageSquare size={16} /> },
            ],
        },

        { name: "Reports",       href: "/portal/immigration/reports",         icon: <LineChart size={20} /> },
        { name: "Visa Approved", href: "/admin/visa-approvals",               icon: <Award size={20} /> },

        { name: "Account", section: true },
        { name: "My Profile",    href: "/portal/immigration/profile",       icon: <User size={20} /> },
        { name: "Notifications", href: "/portal/immigration/notifications", icon: <Bell size={20} />, badge: badges.notifications_unread, badgeTone: "teal" },
        { name: "Daily Time Record", href: "/portal/immigration/dtr", icon: <Clock size={20} /> },
        { name: "My Tickets",    href: "/portal/tickets",                  icon: <Ticket size={20} /> },
    ];

    return (
        <DashboardLayout brand="EP." subtitle="Immigration Portal" accent="bg-[#009688]" nav={NAV}>
            {children}
        </DashboardLayout>
    );
}

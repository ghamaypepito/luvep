import DashboardLayout from "./DashboardLayout";
import { usePage } from "@inertiajs/react";
import {
    LayoutDashboard, Users, CalendarCheck, CheckSquare,
    Send, FileText, BarChart3, User, Bell, LineChart, Tag, ClipboardCheck, Ticket, GraduationCap, Award,
    Clock,
} from "lucide-react";

// Sales Portal sidebar — grouped into WORK / OUTREACH / ACCOUNT. Sidebar
// badges (open tasks, new leads today, etc.) come from the `sidebarBadges`
// prop shared globally by HandleInertiaRequests, so every page in the portal
// gets them for free.
export default function SalesLayout({ children }) {
    const { props } = usePage();
    const badges = props?.sidebarBadges?.sales || {};

    const SALES_NAV = [
        { name: "Dashboard", href: "/portal/sales/dashboard", icon: <LayoutDashboard size={20} /> },

        { name: "Work", section: true },
        // Leads is now a group — "List of Leads" is the original leads
        // table; "Proposal & Agreements" is the new sidebar page for
        // system-generated docs. Children pattern is supported by
        // DashboardLayout — see its accordion render path.
        {
            name: "Leads",
            icon: <Users size={20} />,
            badge: badges.new_leads_today,
            badgeTone: "default",
            children: [
                { name: "List of Leads",           href: "/portal/sales/leads",                      icon: <Users size={16} /> },
                { name: "Proposal & Agreements",   href: "/portal/sales/leads/proposals-agreements", icon: <FileText size={16} /> },
            ],
        },
        // Shared with Education + Immigration — same screen, this portal's layout.
        { name: "Students", href: "/portal/sales/students", icon: <GraduationCap size={20} /> },
        { name: "Task Board", href: "/portal/sales/tasks", icon: <CheckSquare size={20} />, badge: badges.tasks_open, badgeTone: badges.tasks_overdue > 0 ? "danger" : "default" },
        { name: "Assessments", href: "/portal/sales/assessments", icon: <ClipboardCheck size={20} /> },
        { name: "Bookings",  href: "/portal/sales/bookings",  icon: <CalendarCheck size={20} />, badge: badges.bookings_this_week, badgeTone: "default" },
        { name: "Programs",  href: "/portal/sales/programs",  icon: <GraduationCap size={20} /> },
        { name: "Reports",       href: "/portal/sales/reports", icon: <LineChart size={20} /> },
        { name: "Offers of Place", href: "/portal/sales/offers-of-place", icon: <FileText size={20} /> },
        { name: "Visa Approved", href: "/admin/visa-approvals", icon: <Award size={20} /> },

        { name: "Outreach", section: true },
        { name: "Promotions",      href: "/admin/promos",                 icon: <Tag size={20} /> },
        { name: "Bulk Email",      href: "/portal/sales/bulk-email",      icon: <Send size={20} /> },
        { name: "Email Templates", href: "/portal/sales/email-templates", icon: <FileText size={20} /> },
        { name: "Compose", href: "/portal/sales/compose", icon: <Send size={20} /> },
        { name: "Campaigns",       href: "/portal/sales/campaigns",       icon: <BarChart3 size={20} /> },

        { name: "Account", section: true },
        { name: "My Profile",    href: "/portal/sales/profile",       icon: <User size={20} /> },
        { name: "Notifications", href: "/portal/sales/notifications", icon: <Bell size={20} />, badge: badges.notifications_unread, badgeTone: "warning" },
        { name: "Daily Time Record", href: "/portal/sales/dtr", icon: <Clock size={20} /> },
        { name: "My Tickets",    href: "/portal/tickets",             icon: <Ticket size={20} /> },
    ];

    return (
        <DashboardLayout brand="EP." subtitle="Sales Portal" accent="bg-blue-600" nav={SALES_NAV}>
            {children}
        </DashboardLayout>
    );
}

import DashboardLayout from "./DashboardLayout";
import { usePage } from "@inertiajs/react";
import {
    LayoutDashboard, UserSquare2, Users, GraduationCap,
    LineChart, User, Bell, Tag, Star, CheckSquare, ClipboardCheck,
    Building2, Ticket, Mail,
    PenLine, Megaphone, Smartphone, MessageSquare, FileText, Award,
    Clock, BookOpen, BadgeCheck, CalendarDays,
} from "lucide-react";

export default function EducationLayout({ children }) {
    const { props } = usePage();
    const badges = props?.sidebarBadges?.education || {};
    const modules = props?.auth?.modules || [];

    const EDUCATION_NAV = [
        { name: "Dashboard", href: "/portal/education/dashboard", icon: <LayoutDashboard size={20} /> },

        { name: "Work", section: true },
        { name: "Assessments", href: "/portal/education/assessments", icon: <ClipboardCheck size={20} />, badge: badges.assessments, badgeTone: "warning" },
        { name: "Bookings", href: "/portal/education/booking", icon: <BookOpen size={20} />, badge: badges.bookings_new, badgeTone: "warning" },
        { name: "Events & RSVP", href: "/portal/education/rsvps", icon: <CalendarDays size={20} /> },
        { name: "List of Leads", href: "/portal/education/leads", icon: <UserSquare2 size={20} />, badge: badges.new_leads_today, badgeTone: "warning" },
        { name: "Proposal & Agreements", href: "/portal/education/leads/proposals-agreements", icon: <FileText size={20} /> },
        { name: "Students",  href: "/portal/education/students",  icon: <Users size={20} /> },
        { name: "Intake Monitoring", href: "/portal/education/students/intake-monitoring", icon: <CalendarDays size={20} /> },
        // Restricted module — only shows when granted (e.g. to Dinah). Two
        // parts, each its own grantable feature.
        ...((modules.includes("program_verification.proposal") || modules.includes("program_verification.consultancy"))
            ? [{
                name: "Verification",
                icon: <BadgeCheck size={20} />,
                children: [
                    ...(modules.includes("program_verification.proposal")
                        ? [{ name: "Proposal", href: "/program-verification", icon: <FileText size={16} /> }] : []),
                    ...(modules.includes("program_verification.consultancy")
                        ? [{ name: "Consultancy Agreement", href: "/consultancy-verification", icon: <ClipboardCheck size={16} /> }] : []),
                ],
            }]
            : []),
        { name: "Task Board", href: "/portal/education/tasks", icon: <CheckSquare size={20} />, badge: badges.tasks_open, badgeTone: badges.tasks_overdue > 0 ? "danger" : "default" },

        { name: "Setup", section: true },
        {
            name: "Emails",
            icon: <Mail size={20} />,
            children: [
                { name: "Templates", href: "/portal/education/email-templates", icon: <PenLine size={16} /> },
                { name: "Compose", href: "/portal/education/compose", icon: <Mail size={16} /> },
                { name: "Bulk Mail", href: "/portal/education/bulk-email",      icon: <Megaphone size={16} /> },
                { name: "SMS",       href: "/portal/education/sms",             icon: <Smartphone size={16} /> },
                { name: "Replies",   href: "/portal/education/email/replies",   icon: <MessageSquare size={16} /> },
            ],
        },
        { name: "Reports",       href: "/portal/education/reports", icon: <LineChart size={20} /> },
        { name: "Programs",      href: "/portal/education/programs", icon: <GraduationCap size={20} /> },
        { name: "Schools",       href: "/portal/education/schools",  icon: <Building2 size={20} /> },
        // Public-facing showcase content — testimonials, success stories, campaigns.
        {
            name: "Marketing",
            icon: <Megaphone size={20} />,
            badge: badges.user_reviews_new,
            badgeTone: "warning",
            children: [
                { name: "User Reviews",  href: "/admin/user-reviews",  icon: <Star size={16} />, badge: badges.user_reviews_new, badgeTone: "warning" },
                { name: "Visa Approved", href: "/admin/visa-approvals", icon: <Award size={16} /> },
                { name: "Promotions",    href: "/admin/promos",         icon: <Tag size={16} /> },
            ],
        },

        { name: "Account", section: true },
        { name: "Daily Time Record", href: "/portal/education/dtr", icon: <Clock size={20} /> },
        { name: "My Tickets",    href: "/portal/tickets",                icon: <Ticket size={20} /> },
        { name: "Notifications", href: "/portal/education/notifications", icon: <Bell size={20} />, badge: badges.notifications_unread, badgeTone: "warning" },
        { name: "My Profile",    href: "/portal/education/profile",       icon: <User size={20} /> },
    ];

    return (
        <DashboardLayout brand="EP." subtitle="Education Portal" accent="bg-[#14532d]" nav={EDUCATION_NAV}>
            {children}
        </DashboardLayout>
    );
}

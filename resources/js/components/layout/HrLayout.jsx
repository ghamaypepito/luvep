import { hrPath } from "@/lib/hrPath";
import { Link, router, usePage } from "@inertiajs/react";
import {
    LayoutDashboard,
    UserRound,
    UsersRound,
    LogOut,
    ArrowUpRight,
    Clock3,
    ClipboardCheck,
    CalendarDays,
    FolderLock,
    BookOpen,
    MessageSquareLock,
    ListChecks,
    Laptop,
} from "lucide-react";
import NotificationBell from "../NotificationBell";
import ClockWidget from "../hr/ClockWidget";
import ProfileMenu from "../hr/ProfileMenu";
import PreviewBanner from "../hr/PreviewBanner";
import "../../../css/hr.css";

export default function HrLayout({ children }) {
    const { hr, flash, errors } = usePage().props;
    const path = usePage().url.split("?")[0];
    const links = [
        [hrPath("/dashboard"), "Overview", LayoutDashboard],
        [hrPath("/calendar"), "Calendar", CalendarDays],
        [hrPath("/profile"), "My profile", UserRound],
    ];
    if (hr?.employee) links.push([hrPath("/leave"), "My leave", CalendarDays]);
    if (hr?.permissions?.["leave.review"])
        links.push([hrPath("/leave/review"), "Leave decisions", ClipboardCheck]);
    if (hr?.employee) links.push([hrPath("/dtr"), "My time records", Clock3]);
    if (hr?.permissions?.["dtr.review"])
        links.push([hrPath("/dtr/review"), "Time reviews", ClipboardCheck]);
    if (hr?.permissions?.["dtr.correct"])
        links.push([hrPath("/dtr/corrections"), "Time corrections", Clock3]);
    links.push([hrPath("/checklists"), "Onboarding & exits", ListChecks]);
    links.push([hrPath("/reports"), "Reports", ClipboardCheck]);
    links.push([hrPath("/milestones"), "Milestones", CalendarDays]);
    links.push([hrPath("/equipment"), "Equipment", Laptop]);
    links.push([hrPath("/requests"), "HR support", MessageSquareLock]);
    links.push([hrPath("/policies"), "Policies", BookOpen]);
    links.push([hrPath("/documents"), "Documents", FolderLock]);
    if (hr?.permissions?.people) links.push([hrPath("/people"), "People", UsersRound]);
    if (hr?.permissions?.["access.manage"])
        links.push([hrPath("/access"), "Access", UsersRound]);
    if (hr?.permissions?.audit)
        links.push([hrPath("/audit"), "Activity & delivery", ClipboardCheck]);
    return (
        <div className="hr-app">
            <aside className="hr-sidebar">
                <a href={hrPath("/dashboard")} aria-label="Luvep HR home" className="hr-logo">
                    <img src="/images/luvep-hr-logo.png" alt="Luv, eP." />
                    <span>People & culture</span>
                </a>
                <nav aria-label="HR navigation">
                    {links.map(([href, label, Icon]) => (
                        <Link
                            href={href}
                            key={href}
                            aria-current={path === href ? "page" : undefined}
                        >
                            <Icon size={19} />
                            {label}
                        </Link>
                    ))}
                </nav>
                <div className="hr-sidebar-footer">
                    <a href={hr?.main_url || "/"}>
                        Back to Luvep <ArrowUpRight size={16} />
                    </a>
                    <button onClick={() => router.post("/logout")}>
                        <LogOut size={16} />
                        Sign out
                    </button>
                </div>
            </aside>
            <div className="hr-workspace">
                <header className="hr-topbar">
                    <span>Employee portal</span>
                    <div className="hr-actions">
                        <NotificationBell />
                        <ProfileMenu />
                    </div>
                </header>
                <PreviewBanner />
                <main id="hr-main">
                    {flash?.success && (
                        <p className="hr-feedback" role="status">
                            {flash.success}
                        </p>
                    )}
                    {(flash?.error || Object.keys(errors || {}).length > 0) && (
                        <p className="hr-feedback hr-error" role="alert">
                            {flash?.error || Object.values(errors).flat()[0]}
                        </p>
                    )}
                    <ClockWidget />
                    {children}
                </main>
                <footer className="hr-footer">
                    Luv, eP. · Your people, your workplace.
                </footer>
            </div>
        </div>
    );
}

import { UserViewButton } from '../admin/UserViewControls';
import { hrPath } from "@/lib/hrPath";
import { Link, router, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
    LayoutDashboard,
    UserRound,
    UsersRound,
    LogOut,
    Clock3,
    ClipboardCheck,
    CalendarDays,
    FolderLock,
    BookOpen,
    MessageSquareLock,
    ListChecks,
    Laptop,
    Menu,
    X,
} from "lucide-react";
import NotificationBell from "../NotificationBell";
import ClockWidget from "../hr/ClockWidget";
import ProfileMenu from "../hr/ProfileMenu";
import PreviewBanner from "../hr/PreviewBanner";
import HrReturnLink from "../hr/HrReturnLink";
import "../../../css/hr.css";

export default function HrLayout({ children }) {
    const { hr, flash, errors } = usePage().props;
    const path = usePage().url.split("?")[0];
    const [menuOpen, setMenuOpen] = useState(false);
    useEffect(() => setMenuOpen(false), [path]);
    const overview = [
        [hrPath("/dashboard"), "Overview", LayoutDashboard],
        [hrPath("/calendar"), "Calendar", CalendarDays],
        [hrPath("/reports"), "Reports", ClipboardCheck],
    ];
    const personal = [
        [hrPath("/profile"), "My profile", UserRound],
    ];
    const team = [];
    const workplace = [];
    const administration = [];
    if (hr?.employee) personal.push([hrPath("/leave"), "My leave", CalendarDays]);
    if (hr?.permissions?.["leave.review"])
        team.push([hrPath("/leave/review"), "Leave decisions", ClipboardCheck]);
    if (hr?.employee) personal.push([hrPath("/dtr"), "My time records", Clock3]);
    if (hr?.permissions?.["dtr.review"])
        team.push([hrPath("/dtr/review"), "Time reviews", ClipboardCheck]);
    if (hr?.can_view_dtr_changes)
        team.push([hrPath("/dtr/changes"), "DTR changes", ClipboardCheck]);
    if (hr?.permissions?.["dtr.correct"])
        team.push([hrPath("/dtr/corrections"), "Time corrections", Clock3]);
    team.push([hrPath("/checklists"), "Onboarding & exits", ListChecks]);
    team.push([hrPath("/milestones"), "Milestones", CalendarDays]);
    workplace.push([hrPath("/equipment"), "Equipment", Laptop]);
    workplace.push([hrPath("/requests"), "HR support", MessageSquareLock]);
    workplace.push([hrPath("/policies"), "Policies", BookOpen]);
    workplace.push([hrPath("/documents"), "Documents", FolderLock]);
    workplace.push(["https://luvep.com/media-drive", "Client Media Drive", FolderLock]);
    if (hr?.permissions?.people) team.unshift([hrPath("/people"), "People", UsersRound]);
    if (hr?.permissions?.["access.manage"])
        administration.push([hrPath("/access"), "Access", UsersRound]);
    if (hr?.permissions?.audit)
        administration.push([hrPath("/audit"), "Activity & delivery", ClipboardCheck]);
    const groups = [["Workspace", overview], ["Team management", team], ["My workspace", personal], ["Resources & support", workplace], ["Administration", administration]];
    const current = groups.flatMap(([, links]) => links).filter(([href]) => path === href || path.startsWith(`${href}/`)).sort(([a], [b]) => b.length - a.length)[0];
    return (
        <div className="hr-app">
            <a href="#hr-main" className="hr-skip-link">Skip to content</a>
            <aside className="hr-sidebar">
                <a href={hrPath("/dashboard")} aria-label="Luvep HR home" className="hr-logo">
                    <img src="/images/luvep-hr-logo.png" alt="Luv, eP." />
                    <span>People & culture</span>
                </a>
                <button type="button" className="hr-menu-toggle" aria-expanded={menuOpen} aria-controls="hr-navigation" onClick={() => setMenuOpen(!menuOpen)}>
                    {menuOpen ? <X size={20} /> : <Menu size={20} />} Menu
                    <span>{current?.[1] || "People & culture"}</span>
                </button>
                <div id="hr-navigation" className={`hr-nav-content${menuOpen ? " is-open" : ""}`}>
                <nav aria-label="HR navigation">
                    {groups.filter(([, links]) => links.length).map(([title, links]) => <div className="hr-nav-group" key={title}>
                        <p>{title}</p>
                        {links.map(([href, label, Icon]) => (
                        <Link
                            href={href}
                            key={href}
                            onClick={() => setMenuOpen(false)}
                            aria-current={current?.[0] === href ? "page" : undefined}
                        >
                            <Icon size={18} aria-hidden="true" />
                            {label}
                        </Link>
                    ))}</div>)}
                </nav>
                <div className="hr-sidebar-footer">
                    <HrReturnLink hr={hr} />
                    <button onClick={() => router.post("/logout")}>
                        <LogOut size={16} />
                        Sign out
                    </button>
                </div>
                </div>
            </aside>
            <div className="hr-workspace">
                <header className="hr-topbar">
                    <span><span className="hr-topbar-label">People & culture</span> <span className="hr-breadcrumb-separator">/</span> <strong>{current?.[1] || "HR Portal"}</strong></span>
                    <div className="hr-actions">
                        <UserViewButton />
                        <NotificationBell />
                        <ProfileMenu />
                    </div>
                </header>
                <PreviewBanner />
                <main id="hr-main" tabIndex={-1}>
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

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
    ChevronDown,
    Settings,
} from "lucide-react";
import NotificationBell from "../NotificationBell";
import ClockWidget from "../hr/ClockWidget";
import ProfileMenu from "../hr/ProfileMenu";
import PreviewBanner from "../hr/PreviewBanner";
import HrReturnLink from "../hr/HrReturnLink";
import "../../../css/hr.css";

export default function HrLayout({ children }) {
    const { hr, flash, errors } = usePage().props;
    const pageUrl = usePage().url;
    const path = pageUrl.split("?")[0];
    const [menuOpen, setMenuOpen] = useState(false);
    const [density, setDensity] = useState(() => {
        try { return window.localStorage.getItem("luvep-hr-density") === "split" ? "split" : "garden"; }
        catch { return "garden"; }
    });
    const changeDensity = (value) => {
        setDensity(value);
        try { window.localStorage.setItem("luvep-hr-density", value); } catch { /* Browser storage may be unavailable. */ }
    };
    useEffect(() => setMenuOpen(false), [path]);
    const personal = [];
    if (hr?.employee) personal.push(
        [hrPath("/dtr"), "Time records", Clock3],
        [hrPath("/dtr/changes?scope=mine"), "My DTR changes", ClipboardCheck],
        [hrPath("/leave"), "Leave", CalendarDays],
    );
    personal.push([hrPath("/profile"), "My profile", UserRound]);
    const team = [];
    if (hr?.can_view_staff_status) team.push([hrPath("/team"), "Staff status", UsersRound]);
    if (hr?.permissions?.["leave.review"]) team.push([hrPath("/leave/review"), "Leave decisions", ClipboardCheck]);
    if (hr?.permissions?.["dtr.review"]) team.push([hrPath("/dtr/review"), "Time reviews", ClipboardCheck]);
    if (hr?.can_view_dtr_changes) team.push([hrPath("/dtr/changes"), "DTR acknowledgements", ClipboardCheck]);
    if (hr?.permissions?.["dtr.correct"]) team.push([hrPath("/dtr/corrections"), "Time corrections", Clock3]);
    if (hr?.permissions?.people) team.push([hrPath("/people"), "People", UsersRound]);
    if (hr?.can_view_staff_status) team.push([hrPath("/calendar"), "Team calendar", CalendarDays], [hrPath("/reports"), "Team reports", ClipboardCheck]);
    const resources = [
        [hrPath("/checklists"), "Onboarding & exits", ListChecks],
        [hrPath("/milestones"), "Milestones", CalendarDays],
        [hrPath("/equipment"), "Equipment", Laptop],
        [hrPath("/requests"), "HR support", MessageSquareLock],
        [hrPath("/policies"), "Policies", BookOpen],
        [hrPath("/documents"), "Documents", FolderLock],
    ];
    if (!hr?.can_view_staff_status) resources.push([hrPath("/calendar"), "Calendar", CalendarDays], [hrPath("/reports"), "Reports", ClipboardCheck]);
    const settings = [];
    if (hr?.permissions?.["access.manage"]) settings.push([hrPath("/access"), "Access", UsersRound]);
    if (hr?.permissions?.audit) settings.push([hrPath("/audit"), "Activity & delivery", ClipboardCheck]);
    const groups = [["My workspace", personal, UserRound], ["Team workspace", team, UsersRound], ["Resources & support", resources, FolderLock], ["Settings", settings, Settings]];
    const overview = [hrPath("/dashboard"), "Overview", LayoutDashboard];
    const matches = ([href]) => {
        const [linkPath, query] = href.split("?");
        if (query) return pageUrl === href;
        if (linkPath === hrPath("/dtr/changes") && pageUrl.includes("scope=mine")) return false;
        return path === linkPath || path.startsWith(`${linkPath}/`);
    };
    const current = [overview, ...groups.flatMap(([, links]) => links)].filter(matches).sort(([a], [b]) => b.length - a.length)[0];
    return (
        <div className={`hr-app hr-density-${density}`}>
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
                    <Link href={overview[0]} aria-current={path === overview[0] || path === "/" ? "page" : undefined}><LayoutDashboard size={18} aria-hidden="true" />Overview</Link>
                    {groups.filter(([, links]) => links.length).map(([title, links, GroupIcon]) => <details className="hr-nav-group" key={title} open={links.some(link => current?.[0] === link[0])}>
                        <summary><GroupIcon size={18} aria-hidden="true" /><span>{title}</span><ChevronDown size={15} aria-hidden="true" /></summary>
                        <div>{links.map(([href, label, Icon]) => <Link href={href} key={href} onClick={() => setMenuOpen(false)} aria-current={current?.[0] === href ? "page" : undefined}><Icon size={16} aria-hidden="true" />{label}</Link>)}</div>
                    </details>)}
                </nav>
                <div className="hr-sidebar-footer">
                    <div className="hr-density-control" role="group" aria-label="Workspace density">
                        <button type="button" aria-pressed={density === "garden"} onClick={() => changeDensity("garden")}>Garden</button>
                        <button type="button" aria-pressed={density === "split"} onClick={() => changeDensity("split")}>Split view</button>
                    </div>
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

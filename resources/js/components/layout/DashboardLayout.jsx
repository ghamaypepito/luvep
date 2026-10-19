import ProfileMenu from '../hr/ProfileMenu';
import PreviewBanner from '../hr/PreviewBanner';
import '../../../css/hr.css';
import { Link, usePage, router } from "@inertiajs/react";
import { useState, useEffect } from "react";
import { Menu, X, Settings, LogOut, ChevronDown, Eye, ArrowLeft, Sparkles, LayoutGrid, GraduationCap, Languages, Globe, Building2, Building, Wallet, UserRound, Users, KeyRound } from "lucide-react";

// Admin-area restricted modules that, when granted via Module Management, should
// surface as a sidebar link for a NON-admin staffer (admins already see them in
// the admin sidebar). Keyed by the module key from config/modules.php.
const MODULE_NAV = {
    portal_invitation: { name: "Portal Invitations", href: "/admin/portal-invitations", icon: <KeyRound size={20} /> },
};

// A distinct icon per department portal for the sidebar "Portals" group.
const PORTAL_ICONS = {
    sales: Building2,
    education: GraduationCap,
    english: Languages,
    immigration: Globe,
    accommodation: Building,
    finance: Wallet,
    agent: UserRound,
    sub_agent: Users,
};
const portalIcon = (key) => {
    const Icon = PORTAL_ICONS[key] || LayoutGrid;
    return <Icon size={20} />;
};
import ClockWidget from "@/components/hr/ClockWidget";
import NotificationBell from "@/components/NotificationBell";
import GlobalSearchBar from "@/components/GlobalSearchBar";
import RequestTicketButton from "@/components/RequestTicketButton";
import { AvatarPhoto } from "@/components/ui/Avatar";

/**
 * The official ePathways dashboard shell — sidebar + topbar + content area.
 * Used by the admin panel and every department portal. Drive it with props:
 *
 *   <DashboardLayout
 *     brand="ePathways."
 *     subtitle="Admin Panel"            // small label under the brand
 *     accent="bg-gray-900"              // colour for the avatar / portal dot
 *     nav={[{ name, href, icon }, { name, icon, children: [{ name, href, icon }] }]}
 *     settingsHref="/admin/settings"    // omit to hide the Settings link
 *   >
 *     {page}
 *   </DashboardLayout>
 */
export default function DashboardLayout({
    children,
    nav = [],
    brand = "EP.",
    subtitle = null,
    accent = "bg-gray-900",
    settingsHref = null,
    footerNav = [],
}) {
    const { hr } = usePage().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { url, props } = usePage();
    const user = props.auth?.user;

    // Department portals this user can switch between (their role + any granted
    // in Module Management). Empty unless there's more than one.
    const portals = props.auth?.portals || [];
    const currentPortal = portals.find((p) => url.startsWith(`/portal/${p.key}/`));

    // Admin-area modules granted to a NON-admin staffer via Module Management —
    // surface each as a sidebar link so an enabled module actually shows up in
    // their account. Admins/super already see these in the admin sidebar.
    const grantedModules = props.auth?.modules || [];
    const isPrivileged = user?.role === "admin" || user?.role === "super_admin";
    const moduleExtras = isPrivileged
        ? []
        : Object.entries(MODULE_NAV)
            .filter(([key]) => grantedModules.includes(key))
            .map(([, item]) => item);

    // Append a "Tools" section for granted admin modules, plus a collapsible
    // "Portals" group when the user can reach more than one portal.
    const navWithPortals = [
        ...nav,
        ...(moduleExtras.length > 0 ? [{ section: true, name: "Tools" }, ...moduleExtras] : []),
        ...(portals.length > 1
            ? [{
                name: "Portals",
                icon: <LayoutGrid size={20} />,
                children: portals.map((p) => ({
                    name: `${p.label} Portal`,
                    href: p.href,
                    icon: portalIcon(p.key),
                })),
            }]
            : []),
    ];

    // Shared detail pages (e.g. the lead detail screen) are reached from more
    // than one sidebar entry — the Leads list AND the Students screen both open
    // /leads/{id}. By URL alone they always light up "List of Leads". A link can
    // instead declare which section it belongs to with a `?nav=<slug>` hint; the
    // slug is matched against the last path segment of each nav item's href.
    const navHint = (() => {
        const i = url.indexOf("?");
        if (i === -1) return null;
        return new URLSearchParams(url.slice(i + 1)).get("nav");
    })();
    const hrefSlug = (href) => href.replace(/\/+$/, "").split("/").pop();

    // Match `/leads` against `/leads/123` (lead detail) but NOT against
    // `/leads/proposals-agreements` — otherwise sibling children share
    // a prefix and both light up. Path must either equal the href or
    // continue with a `/`. When a `?nav=` hint is present it wins: ONLY the
    // item whose href slug equals the hint is active, so the shared page lights
    // up the section it was opened from rather than the one its URL sits under.
    const isPathMatch = (href) =>
        navHint
            ? hrefSlug(href) === navHint
            : url === href || url.startsWith(href + "/");

    // The single best top-level match — the item whose href is the LONGEST
    // prefix of the current URL — so sibling prefixes don't both light up
    // (e.g. /leads vs /leads/proposals-agreements).
    const bestTopHref = (() => {
        let best = null;
        for (const it of navWithPortals) {
            if (! it.href || it.children) continue;
            if (! isPathMatch(it.href)) continue;
            if (! best || it.href.length > best.length) best = it.href;
        }
        return best;
    })();

    // Among a group's children, only ONE is active — the child whose
    // href is the longest prefix of the current URL. That way
    // /leads/proposals-agreements beats /leads even though both are
    // valid prefixes.
    const activeChildFor = (item) => {
        if (! item.children) return null;
        let best = null;
        for (const c of item.children) {
            if (! isPathMatch(c.href)) continue;
            if (! best || c.href.length > best.href.length) best = c;
        }
        return best;
    };

    const isAccordionActive = (item) => activeChildFor(item) !== null;

    // Single-open accordions: at most one group is expanded at a time, so the
    // sidebar stays short enough to avoid scrolling even on portals with long
    // menus. At rest only the group containing the current page is open.
    // The active group: a nav group whose child matches the URL, or the Portals
    // group whenever the user is anywhere inside one of their portals.
    const activeGroupName = () => {
        for (const item of navWithPortals) {
            if (item.children && isAccordionActive(item)) return item.name;
        }
        if (portals.length > 1 && currentPortal) return "Portals";
        return null;
    };

    const [openAccordions, setOpenAccordions] = useState(() => {
        const g = activeGroupName();
        return g ? { [g]: true } : {};
    });

    useEffect(() => {
        // On navigation, open the active group and collapse every other one.
        const g = activeGroupName();
        setOpenAccordions(g ? { [g]: true } : {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url]);

    // Opening a group closes the others (accordion behaviour).
    const toggleAccordion = (name) =>
        setOpenAccordions((prev) => (prev[name] ? {} : { [name]: true }));

    const handleLogout = () => router.post("/logout");

    const getPageTitle = () => {
        for (const item of nav) {
            if (item.href && isPathMatch(item.href)) return item.name;
            const child = activeChildFor(item);
            if (child) return child.name;
        }
        return "Dashboard";
    };

    const SidebarContent = () => (
        <>
            <div className="flex items-center justify-between px-6 py-5 border-b border-gray-50/50 lg:border-none">
                <div>
                    <h2 className="text-2xl font-black tracking-tighter text-gray-900">{brand}</h2>
                    {subtitle && (
                        <p className="mt-0.5 text-[10px] font-bold uppercase tracking-[0.2em] text-gray-400">{subtitle}</p>
                    )}
                </div>
                <button className="lg:hidden" onClick={() => setSidebarOpen(false)}>
                    <X className="w-5 h-5 text-gray-600 hover:text-gray-800" />
                </button>
            </div>

            {/* AI Assistant — pinned above the nav so it's always visible, and
                styled distinctly (gradient) so it reads as the AI surface
                rather than a regular menu row. Staff only, and hidden when AI
                is disabled for the tenant. */}
            {user?.role !== "lead" && props.auth?.ai_enabled !== false && (
                <div className="px-3 pt-2 pb-1">
                    <Link
                        href="/assistant"
                        onClick={() => setSidebarOpen(false)}
                        title="EP AI Assistant"
                        className={`group flex items-center gap-3 px-3 py-2 rounded-xl transition-all duration-200 ${
                            isPathMatch("/assistant")
                                ? "bg-gradient-to-r from-indigo-500 to-violet-500 text-white shadow-md shadow-indigo-500/25"
                                : "bg-gradient-to-r from-indigo-50 to-violet-50 text-indigo-950 ring-1 ring-indigo-100/80 hover:from-indigo-100 hover:to-violet-100"
                        }`}
                    >
                        <span
                            className={`flex items-center justify-center w-8 h-8 rounded-lg shrink-0 transition-colors ${
                                isPathMatch("/assistant")
                                    ? "bg-white/20"
                                    : "bg-gradient-to-br from-indigo-500 to-violet-500 shadow-sm"
                            }`}
                        >
                            <Sparkles size={16} className="text-white" />
                        </span>
                        <span className="flex flex-col min-w-0 leading-tight">
                            <span className="text-[13px] font-bold">AI Assistant</span>
                            <span className={`text-[11px] ${isPathMatch("/assistant") ? "text-white/80" : "text-indigo-500"}`}>
                                Ask about any record
                            </span>
                        </span>
                    </Link>
                </div>
            )}

            <div className="flex-1 overflow-y-auto px-3 py-1.5 flex flex-col gap-0.5 scrollbar-hide">
                {navWithPortals.map((item) => {
                    if (item.children) {
                        const open = !!openAccordions[item.name];
                        const activeChild = activeChildFor(item);
                        const groupActive = activeChild !== null;
                        // Aggregate any child (or own) badge counts onto the group
                        // header so a collapsed group still signals pending items.
                        const groupBadge = (Number(item.badge) || 0)
                            + item.children.reduce((s, c) => s + (Number(c.badge) || 0), 0);
                        const groupBadgeTone = [item, ...item.children].some((x) => x.badgeTone === "danger")
                            ? "danger"
                            : [item, ...item.children].some((x) => x.badgeTone === "warning")
                                ? "warning"
                                : "default";
                        return (
                            <div key={item.name} className="flex flex-col">
                                <button
                                    type="button"
                                    onClick={() => toggleAccordion(item.name)}
                                    className={`flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] font-medium transition-all duration-200 group w-full text-left ${
                                        groupActive
                                            ? "bg-gray-100/80 text-gray-900 shadow-sm"
                                            : "text-gray-600 hover:bg-gray-50 hover:text-gray-900"
                                    }`}
                                >
                                    <div className={`${groupActive ? "text-gray-900" : "text-gray-500 group-hover:text-gray-600"}`}>
                                        {item.icon}
                                    </div>
                                    <span className="flex-1">{item.name}</span>
                                    {/* Show the rolled-up count only while collapsed —
                                        when open, each child shows its own badge. */}
                                    {! open && <NavBadge value={groupBadge} tone={groupBadgeTone} />}
                                    <ChevronDown
                                        size={16}
                                        className={`text-gray-400 transition-transform duration-200 ${open ? "rotate-180" : ""}`}
                                    />
                                </button>

                                <div
                                    className={`grid transition-all duration-300 ease-in-out ${
                                        open ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"
                                    }`}
                                >
                                    <div className="overflow-hidden">
                                        <div className="pl-4 pt-0.5 pb-0.5 flex flex-col gap-0.5 border-l border-gray-100 ml-6 mt-0.5">
                                            {item.children.map((child) => {
                                                const childActive = child === activeChild;
                                                return (
                                                    <Link
                                                        key={child.name}
                                                        href={child.href}
                                                        className={`flex items-center gap-3 px-3 py-1.5 rounded-lg text-[13px] font-medium transition-all duration-200 ${
                                                            childActive
                                                                ? "bg-gray-900 text-white shadow-sm"
                                                                : "text-gray-600 hover:bg-gray-50 hover:text-gray-900"
                                                        }`}
                                                    >
                                                        <span className={childActive ? "text-white" : "text-gray-500"}>{child.icon}</span>
                                                        <span className="flex-1">{child.name}</span>
                                                        <NavBadge value={child.badge} tone={child.badgeTone} />
                                                    </Link>
                                                );
                                            })}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    }

                    // Allow non-link "section header" rows so the sidebar
                    // can be grouped (e.g. WORK / OUTREACH / ACCOUNT). A
                    // section is just an item with `section: true`.
                    if (item.section) {
                        return (
                            <p
                                key={`section-${item.name}`}
                                className="px-3 pt-3 pb-0.5 text-[9px] font-bold uppercase tracking-[0.22em] text-gray-400"
                            >
                                {item.name}
                            </p>
                        );
                    }

                    const isActive = item.href === bestTopHref;
                    return (
                        <Link
                            key={item.name}
                            href={item.href}
                            className={`flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] font-medium transition-all duration-200 group ${
                                isActive
                                    ? `${accent} text-white shadow-md`
                                    : "text-gray-600 hover:bg-gray-50 hover:text-gray-900"
                            }`}
                        >
                            <div className={`${isActive ? "text-white" : "text-gray-500 group-hover:text-gray-600"}`}>{item.icon}</div>
                            <span className="flex-1">{item.name}</span>
                            <NavBadge value={item.badge} tone={item.badgeTone} />
                        </Link>
                    );
                })}
            </div>

            <div className="px-3 py-3 mt-auto border-t border-gray-50 flex flex-col gap-0.5">
                {footerNav.map((item) => {
                    const active = item.href && (url === item.href || url.startsWith(item.href + "/") || url.startsWith(item.href + "?"));
                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`flex items-center gap-3 px-3 py-2 text-[13px] font-medium rounded-lg transition-colors ${
                                active
                                    ? "bg-gray-100 text-gray-900"
                                    : "text-gray-600 hover:text-gray-900 hover:bg-gray-50"
                            }`}
                        >
                            <span className="text-gray-500">{item.icon}</span>
                            {item.name}
                        </Link>
                    );
                })}
                {settingsHref && (
                    <Link
                        href={settingsHref}
                        className="flex items-center gap-3 px-3 py-2 text-[13px] font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 rounded-lg transition-colors"
                    >
                        <Settings size={18} className="text-gray-500" />
                        Settings
                    </Link>
                )}

                <button
                    onClick={handleLogout}
                    className="flex items-center gap-3 px-3 py-2 text-[13px] font-medium text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors w-full text-left group"
                >
                    <LogOut size={18} className="text-gray-500 group-hover:text-red-600" />
                    Log out
                </button>
            </div>
        </>
    );

    return (
        <div className="flex h-screen bg-[#F5F5F7] font-sans selection:bg-gray-800 selection:text-white overflow-hidden">
            {/* Mobile Sidebar Overlay */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 bg-black/50 z-20 lg:hidden backdrop-blur-sm transition-opacity duration-300"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Mobile Sidebar */}
            <aside
                className={`fixed inset-y-0 left-0 z-30 w-72 bg-white border-r border-gray-100 flex flex-col transition-transform duration-300 ease-in-out lg:hidden shadow-2xl ${
                    sidebarOpen ? "translate-x-0" : "-translate-x-full"
                }`}
            >
                {SidebarContent()}
            </aside>

            {/* Desktop Sidebar */}
            <aside className="hidden lg:flex flex-col w-64 bg-[#F5F5F7] h-screen shrink-0 pb-4 pt-4 pl-4">
                <div className="bg-white rounded-3xl shadow-sm border border-gray-50 flex-1 flex flex-col overflow-hidden">
                    {SidebarContent()}
                </div>
            </aside>

            {/* Main content */}
            <div className="flex flex-col flex-1 overflow-hidden">
                {/* Top Navbar */}
                <header className="flex items-center justify-between px-8 py-3 bg-[#F5F5F7] lg:bg-transparent">
                    <div className="flex items-center gap-4">
                        <button className="lg:hidden p-2 -ml-2 text-gray-600 hover:bg-gray-200 rounded-xl" onClick={() => setSidebarOpen(true)}>
                            <Menu className="w-5 h-5" />
                        </button>
                        <h1 className="text-2xl font-bold text-gray-900 hidden lg:block tracking-tight">{getPageTitle()}</h1>
                    </div>

                    <div className="flex items-center gap-4 lg:gap-5 mt-2">
                        {portals.length > 1 && (
                            <div className="relative hidden sm:block" title="Switch portal">
                                <select
                                    value={currentPortal?.href || portals[0]?.href || ""}
                                    onChange={(e) => router.visit(e.target.value)}
                                    className="appearance-none text-sm font-semibold text-gray-700 bg-white border border-gray-200 rounded-xl pl-3 pr-8 py-2 hover:border-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-900 cursor-pointer"
                                >
                                    {portals.map((p) => (
                                        <option key={p.key} value={p.href}>{p.label} portal</option>
                                    ))}
                                </select>
                                <ChevronDown className="w-4 h-4 text-gray-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                            </div>
                        )}

                        <GlobalSearchBar />

                        {user?.role !== "lead" && user?.role !== "agent" && <RequestTicketButton />}

                        {/* Agents have no notifications surface. */}
                        {user?.role !== "agent" && <NotificationBell />}

                        <div className="flex items-center gap-2.5">
                            <div className="text-right hidden sm:block leading-tight">
                                <p className="text-sm font-semibold text-gray-900">{user?.name || "User"}</p>
                                {user?.role && <p className="text-[11px] text-gray-500 capitalize">{user.role}</p>}
                            </div>
                            {hr?.enabled && (hr?.employee || hr?.permissions?.preview) ? <ProfileMenu /> : (
                            <div
                                className={`flex items-center justify-center w-9 h-9 rounded-full overflow-hidden ${accent} text-white font-bold text-sm shadow-md ring-2 ring-white`}
                            >
                                <AvatarPhoto src={user?.avatar_url} title={user?.name}>
                                    {user?.name?.charAt(0)?.toUpperCase() || "U"}
                                </AvatarPhoto>
                            </div>
                            )}
                        </div>
                    </div>
                </header>

                <PreviewBanner />
                {/* Content */}
                <main className="flex-1 overflow-y-auto px-4 md:px-8 pb-8 pt-2">
                    {/* Cross-portal admin banner — shown when an admin user is viewing a department portal. */}
                    {(user?.role === "admin" || user?.role === "super_admin") && url.startsWith("/portal/") && (
                        <div className="mb-4 flex items-center justify-between gap-3 px-4 py-2.5 rounded-2xl bg-gray-900 text-white text-sm shadow-sm">
                            <div className="flex items-center gap-2">
                                <Eye size={15} className="text-gray-300" />
                                <span className="text-gray-300">Viewing as <span className="text-white font-semibold capitalize">{subtitle || "portal"}</span> — admin access</span>
                            </div>
                            <Link href="/admin/dashboard" className="inline-flex items-center gap-1 px-3 py-1 rounded-lg bg-white/10 hover:bg-white/15 text-white text-xs font-semibold transition-colors">
                                <ArrowLeft size={12} /> Back to admin
                            </Link>
                        </div>
                    )}
                    <h1 className="text-2xl font-bold text-gray-900 lg:hidden mb-6 tracking-tight">{getPageTitle()}</h1>
                    <ClockWidget />
                    {children}
                </main>
            </div>
        </div>
    );
}

// Small count pill used on nav rows, sub-items, and (aggregated) group headers.
function NavBadge({ value, tone = "default" }) {
    if (value == null || value === 0) return null;
    const cls = tone === "danger"
        ? "bg-red-500 text-white"
        : tone === "warning"
            ? "bg-amber-500 text-white"
            : tone === "teal"
                ? "bg-[#009688] text-white"
                : "bg-gray-900 text-white";
    return (
        <span className={`inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full text-[10px] font-bold tabular-nums ${cls}`}>
            {value}
        </span>
    );
}

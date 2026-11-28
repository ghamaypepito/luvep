import DashboardLayout from "./DashboardLayout";
import { Link, usePage } from "@inertiajs/react";
import { Clock,
    LayoutDashboard, Home, Users, Workflow, Receipt, Wallet,
    CalendarDays, CalendarCheck, ClipboardList, Flame, MessageSquare, MessageSquareWarning, BarChart3, User, Bell, Ticket,
    FileText, FileSignature,
} from "lucide-react";

const ACCOMMODATION_NAV = [
    { name: "Dashboard", href: "/portal/accommodation/dashboard", icon: <LayoutDashboard size={20} /> },

    { name: "Work", section: true },
    { name: "Onboarding", href: "/portal/accommodation/onboarding", icon: <Workflow size={20} /> },
    { name: "Move-Out Pipeline", href: "/portal/accommodation/move-out", icon: <Workflow size={20} /> },
    { name: "Viewings", href: "/portal/accommodation/viewings", icon: <CalendarCheck size={20} /> },
    { name: "Calendar", href: "/portal/accommodation/calendar", icon: <CalendarDays size={20} /> },
    { name: "Finance", icon: <Receipt size={20} />, children: [
        { name: "Rent & Utilities", href: "/portal/accommodation/rent-utilities", icon: <Receipt size={16} /> },
        { name: "Weekly Property Summary", href: "/portal/accommodation/finance/weekly-property-summary", icon: <BarChart3 size={16} /> },
    ] },
    { name: "Invoices & Receipts", icon: <FileText size={20} />, children: [
        { name: "Purchase invoices", href: "/portal/accommodation/purchase-invoices", icon: <FileText size={16} /> },
        { name: "Move-In Invoice", href: "/portal/accommodation/rental-invoices/move-in", icon: <FileText size={16} /> },
        { name: "Overdue Payment Invoice", href: "/portal/accommodation/rental-invoices/overdue", icon: <FileText size={16} />, finance: true },
    ] },
    { name: "PM Payment Schedule", href: "/portal/accommodation/payment-schedule", icon: <Wallet size={20} /> },
    { name: "Task Tracker", href: "/portal/accommodation/tasks", icon: <ClipboardList size={20} /> },
    { name: "Gas Delivery Tracker", href: "/portal/accommodation/gas-delivery", icon: <Flame size={20} /> },
    { name: "Email Templates", href: "/portal/accommodation/email-templates", icon: <MessageSquare size={20} /> },
    { name: "Compose", href: "/portal/accommodation/compose", icon: <MessageSquare size={20} /> },

    { name: "Forms", section: true },
    { name: "Pre-Tenancy Form", href: "/portal/accommodation/forms/pre-tenancy", icon: <FileText size={20} /> },

    { name: "Setup", section: true },
    { name: "Properties", href: "/portal/accommodation/properties", icon: <Home size={20} /> },
    { name: "Tenants", href: "/portal/accommodation/tenants", icon: <Users size={20} /> },
    { name: "Tenant agreements", href: "/portal/accommodation/forms/agreements", icon: <FileSignature size={20} /> },

    { name: "Reports", section: true },
    { name: "Reports", href: "/portal/accommodation/reports", icon: <BarChart3 size={20} /> },

    { name: "Account", section: true },
    { name: "My Profile", href: "/portal/accommodation/profile", icon: <User size={20} /> },
    { name: "Notifications", href: "/portal/accommodation/notifications", icon: <Bell size={20} /> },
    { name: "Daily Time Record", href: "/portal/accommodation/dtr", icon: <Clock size={20} /> },
        { name: "My Tickets", href: "/portal/tickets", icon: <Ticket size={20} /> },
];

const financePages = ACCOMMODATION_NAV.find(item => item.name === "Finance").children;

export default function AccommodationLayout({ children }) {
    const { props: { auth }, url } = usePage();
    const canSeeFinance = auth?.modules?.includes("accommodation_finance");
    const path = url.split("?")[0];
    const inFinance = canSeeFinance && financePages.some(page => page.href === path);
    const canSeePurchase = ["accommodation", "admin", "super_admin"].includes(auth?.user?.role);
    const nav = ACCOMMODATION_NAV.filter(item => item.name !== "Finance" || canSeeFinance)
        .map(item => item.name === "Invoices & Receipts"
            ? { ...item, children: item.children.filter(page => page.finance ? canSeeFinance : canSeePurchase) } : item)
        .filter(item => !item.children || item.children.length);
    return (
        <DashboardLayout brand="EP." subtitle="Accommodation Portal" accent="bg-[#194E47]" nav={nav}>
            {inFinance && <nav aria-label="Accommodation Finance" className="mb-4 flex flex-wrap gap-4 border-b border-gray-200">
                {financePages.map(page => <Link key={page.href} href={page.href} aria-current={page.href === path ? "page" : undefined}
                    className={`border-b-2 px-2 py-2 text-sm font-semibold ${page.href === path ? "border-emerald-800 text-emerald-900" : "border-transparent text-gray-600 hover:text-emerald-900"}`}>
                    {page.name}
                </Link>)}
            </nav>}
            {children}
        </DashboardLayout>
    );
}

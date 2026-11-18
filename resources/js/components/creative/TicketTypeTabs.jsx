import { Link } from "@inertiajs/react";

export default function TicketTypeTabs({ active = "system", isAdmin = true }) {
    return <nav aria-label="Ticket type" className="flex gap-2 border-b border-gray-200 pb-3">
        <Link href={isAdmin ? "/admin/system-tickets" : "/portal/tickets"} aria-current={active === "system" ? "page" : undefined} className={`rounded-lg px-5 py-2 text-sm font-semibold ${active === "system" ? "bg-[#d5edaa] text-[#214d41]" : "text-gray-600 hover:bg-gray-100"}`}>System</Link>
        <Link href={isAdmin ? "/admin/system-tickets?type=digital" : "/creative-requests"} aria-current={active === "digital" ? "page" : undefined} className={`rounded-lg px-5 py-2 text-sm font-semibold ${active === "digital" ? "bg-[#d5edaa] text-[#214d41]" : "text-gray-600 hover:bg-gray-100"}`}>Digital</Link>
    </nav>;
}

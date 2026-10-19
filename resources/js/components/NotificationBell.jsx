import { useState, useRef, useEffect, useCallback } from "react";
import { usePage, router } from "@inertiajs/react";
import { Bell, DollarSign, UserPlus, UserPen, FileText, ArrowRightLeft, CheckSquare, Ticket, AlertTriangle, FileSignature } from "lucide-react";

// Lucide component lookup for the icon names the NotificationFormatter emits.
const ICONS = { Bell, DollarSign, UserPlus, UserPen, FileText, ArrowRightLeft, CheckSquare, Ticket, AlertTriangle, FileSignature };
// Semantic color token → Tailwind classes for the icon chip.
const COLORS = {
    amber: "bg-amber-50 text-amber-600",
    blue: "bg-blue-50 text-blue-600",
    emerald: "bg-emerald-50 text-emerald-600",
    red: "bg-red-50 text-red-600",
    gray: "bg-gray-100 text-gray-500",
};

function timeAgo(iso) {
    if (!iso) return "";
    const then = new Date(iso).getTime();
    const secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (secs < 60) return "now";
    const mins = Math.floor(secs / 60);
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h`;
    const days = Math.floor(hrs / 24);
    if (days === 1) return "yesterday";
    if (days < 7) return `${days}d`;
    return new Date(iso).toLocaleDateString("en-US", { day: "2-digit", month: "short" });
}

function NotificationItem({ n, onClick }) {
    const Icon = ICONS[n.icon] || Bell;
    return (
        <button
            type="button"
            onClick={() => onClick(n)}
            className={`w-full flex items-start gap-3 px-4 py-3 text-left hover:bg-gray-50 transition-colors ${n.is_read ? "" : "bg-blue-50/40"}`}
        >
            <span className="mt-0.5 shrink-0 relative">
                {!n.is_read && <span className="absolute -left-2 top-1.5 w-1.5 h-1.5 bg-blue-500 rounded-full" />}
                <span className={`flex items-center justify-center w-8 h-8 rounded-full ${COLORS[n.color] || COLORS.gray}`}>
                    <Icon size={15} />
                </span>
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-semibold text-gray-900 truncate">{n.title}</span>
                <span className="block text-xs text-gray-500 line-clamp-2">{n.body}</span>
            </span>
            <span className="text-[10px] text-gray-400 shrink-0 tabular-nums mt-0.5">{timeAgo(n.created_at)}</span>
        </button>
    );
}

export default function NotificationBell() {
    const { props } = usePage();
    const unread = props.notifications?.unread_count ?? 0;

    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [items, setItems] = useState([]);
    const ref = useRef(null);

    const load = useCallback(() => {
        setLoading(true);
        fetch("/api/notifications/recent", {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        })
            .then((r) => (r.ok ? r.json() : { notifications: [] }))
            .then((d) => setItems(d.notifications ?? []))
            .catch(() => setItems([]))
            .finally(() => setLoading(false));
    }, []);

    // Load on open.
    useEffect(() => {
        if (open) load();
    }, [open, load]);

    // Close on outside click / escape.
    useEffect(() => {
        if (!open) return;
        const onClick = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
        const onKey = (e) => e.key === "Escape" && setOpen(false);
        document.addEventListener("mousedown", onClick);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onClick);
            document.removeEventListener("keydown", onKey);
        };
    }, [open]);

    const openItem = (n) => {
        setOpen(false);
        const go = () => {
            if (!n.url) return;
            const destination = new URL(n.url, window.location.origin);
            if (!['https:', 'http:'].includes(destination.protocol)) return;
            if (destination.origin !== window.location.origin) window.location.assign(destination.href);
            else router.visit(destination.href);
        };
        if (n.is_read) return go();
        // Mark read (refreshes shared unread_count via the redirect), then navigate.
        router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true, preserveState: true, onFinish: go });
    };

    const markAll = () => {
        router.post("/notifications/mark-all-read", {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setItems((prev) => prev.map((n) => ({ ...n, is_read: true }))),
        });
    };

    if (props.hr?.preview) return null;

    const badge = unread > 99 ? "99+" : unread;

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="relative p-1.5 text-gray-600 hover:bg-white hover:shadow-sm rounded-full transition-all"
                aria-label="Notifications"
            >
                <Bell size={18} />
                {unread > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center min-w-[16px] h-[16px] px-1 rounded-full bg-red-500 text-white text-[9px] font-bold tabular-nums border-2 border-[#F5F5F7]">
                        {badge}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 mt-2 w-[340px] max-w-[92vw] bg-white rounded-2xl shadow-xl border border-gray-100 z-50 overflow-hidden">
                    <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                        <span className="text-sm font-bold text-gray-900">Notifications</span>
                        {unread > 0 && (
                            <button onClick={markAll} className="text-xs font-medium text-blue-600 hover:text-blue-700">
                                Mark all read
                            </button>
                        )}
                    </div>

                    <div className="max-h-[380px] overflow-y-auto divide-y divide-gray-50">
                        {loading ? (
                            <p className="px-4 py-8 text-center text-sm text-gray-400">Loading…</p>
                        ) : items.length === 0 ? (
                            <p className="px-4 py-10 text-center text-sm text-gray-400">No notifications yet</p>
                        ) : (
                            items.map((n) => <NotificationItem key={n.id} n={n} onClick={openItem} />)
                        )}
                    </div>

                    <a href="/notifications" className="block px-4 py-3 text-center text-sm font-medium text-gray-700 hover:bg-gray-50 border-t border-gray-100">
                        See all
                    </a>
                </div>
            )}
        </div>
    );
}

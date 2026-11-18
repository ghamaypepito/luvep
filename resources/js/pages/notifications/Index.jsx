import { Head, router, usePage } from "@inertiajs/react";
import { Bell, DollarSign, UserPlus, UserPen, FileText, ArrowRightLeft, CheckSquare, Ticket, AlertTriangle, Check, Trash2, CheckCheck } from "lucide-react";
import Pagination from "@/components/ui/Pagination";

const ICONS = { Bell, DollarSign, UserPlus, UserPen, FileText, ArrowRightLeft, CheckSquare, Ticket, AlertTriangle };
const COLORS = {
    amber: "bg-amber-50 text-amber-600",
    blue: "bg-blue-50 text-blue-600",
    emerald: "bg-emerald-50 text-emerald-600",
    red: "bg-red-50 text-red-600",
    gray: "bg-gray-100 text-gray-500",
};

const fmtTime = (iso) =>
    iso ? new Date(iso).toLocaleString("en-US", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const EMPTY = {
    all: "No notifications yet.",
    unread: "You're all caught up — no unread notifications.",
    read: "No read notifications.",
};

export default function NotificationsIndex({ notifications = { data: [], links: [] }, filter = "all", counts = {} }) {
    // Drive filter links off the current path so this works whether the
    // page was reached via /notifications or /portal/<role>/notifications.
    const { url } = usePage();
    const path = url.split("?")[0];
    const rows = notifications?.data ?? [];

    const setFilter = (f) => router.get(path, f === "all" ? {} : { filter: f }, { preserveState: true, preserveScroll: true, replace: true });

    const open = (n) => {
        const go = () => {
            if (!n.url) return;
            const destination = new URL(n.url, window.location.origin);
            if (!['https:', 'http:'].includes(destination.protocol)) return;
            if (destination.origin !== window.location.origin) window.location.assign(destination.href);
            else router.visit(destination.href);
        };
        if (n.is_read) return go();
        router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true, preserveState: true, onFinish: go });
    };

    const markRead = (e, n) => {
        e.stopPropagation();
        router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true, preserveState: true });
    };

    const remove = (e, n) => {
        e.stopPropagation();
        router.delete(`/notifications/${n.id}`, { preserveScroll: true, preserveState: true });
    };

    const markAll = () => router.post("/notifications/mark-all-read", {}, { preserveScroll: true, preserveState: true });

    const tabs = [
        { key: "all", label: "All", count: counts.all ?? 0 },
        { key: "unread", label: "Unread", count: counts.unread ?? 0 },
        { key: "read", label: "Read", count: counts.read ?? 0 },
    ];

    return (
        <div className="space-y-6 max-w-4xl mx-auto">
            <Head title="Notifications" />

            <header className="flex items-end justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Bell className="w-6 h-6 text-gray-700" /> Notifications
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Updates and actions available to your account.</p>
                </div>
                {(counts.unread ?? 0) > 0 && (
                    <button onClick={markAll} className="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black flex items-center gap-2">
                        <CheckCheck size={15} /> Mark all as read
                    </button>
                )}
            </header>

            <div className="flex gap-2">
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        onClick={() => setFilter(t.key)}
                        className={`px-3.5 py-1.5 rounded-lg text-sm font-medium transition ${filter === t.key ? "bg-gray-900 text-white" : "text-gray-600 hover:bg-gray-100"}`}
                    >
                        {t.label} <span className={`ml-1 tabular-nums ${filter === t.key ? "text-gray-300" : "text-gray-400"}`}>{t.count}</span>
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-3xl border border-gray-50 shadow-sm overflow-hidden">
                {rows.length === 0 ? (
                    <p className="px-6 py-16 text-center text-sm text-gray-400">{EMPTY[filter] || EMPTY.all}</p>
                ) : (
                    <ul className="divide-y divide-gray-50">
                        {rows.map((n) => {
                            const Icon = ICONS[n.icon] || Bell;
                            return (
                                <li
                                    key={n.id}
                                    onClick={() => open(n)}
                                    className={`flex items-start gap-4 px-6 py-4 cursor-pointer hover:bg-gray-50/60 transition-colors ${n.is_read ? "" : "bg-blue-50/30"}`}
                                >
                                    <span className="relative mt-0.5 shrink-0">
                                        {!n.is_read && <span className="absolute -left-3 top-2 w-1.5 h-1.5 bg-blue-500 rounded-full" />}
                                        <span className={`flex items-center justify-center w-9 h-9 rounded-full ${COLORS[n.color] || COLORS.gray}`}>
                                            <Icon size={16} />
                                        </span>
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-gray-900">{n.title}</p>
                                        <p className="text-sm text-gray-500">{n.body}</p>
                                        <p className="text-[11px] text-gray-400 mt-1">{fmtTime(n.created_at)}</p>
                                    </div>
                                    <div className="flex items-center gap-1.5 shrink-0">
                                        {!n.is_read && (
                                            <button onClick={(e) => markRead(e, n)} title="Mark read" className="p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg">
                                                <Check size={15} />
                                            </button>
                                        )}
                                        <button onClick={(e) => remove(e, n)} title="Delete" className="p-1.5 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg">
                                            <Trash2 size={15} />
                                        </button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <Pagination links={notifications?.links ?? []} />
        </div>
    );
}

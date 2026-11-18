import { Head, Link, usePage } from "@inertiajs/react";
import {
    Users as UsersIcon, CalendarDays, CalendarRange, CheckCircle2,
    Plus, ArrowRight, Mail, Phone,
} from "lucide-react";
import Avatar from "@/components/ui/Avatar";
import ReferralLinkCard from "@/components/affiliate/ReferralLinkCard";

// ─── Recruiting Agent · Dashboard ────────────────────────────────────────
// At-a-glance headline of the agent's own recruiting activity + a shortcut
// to their leads list.

const fmtDate = (iso) => {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString("en-NZ", { day: "2-digit", month: "short" });
};

export default function AgentDashboard({ stats = {}, recent = [], referral = null }) {
    const user = usePage().props?.auth?.user;
    const cards = [
        { label: "Total Leads", value: stats.total ?? 0, icon: <UsersIcon className="w-5 h-5" />, dark: true },
        { label: "This Week", value: stats.this_week ?? 0, icon: <CalendarDays className="w-5 h-5" /> },
        { label: "This Month", value: stats.this_month ?? 0, icon: <CalendarRange className="w-5 h-5" /> },
        { label: "Converted", value: stats.converted ?? 0, icon: <CheckCircle2 className="w-5 h-5" /> },
    ];

    return (
        <div className="space-y-6 max-w-[1500px] mx-auto pb-12">
            <Head title="Affiliate Partner Dashboard" />

            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Welcome back{user?.name ? `, ${user.name.split(" ")[0]}` : ""}.
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Track your referrals and manage your affiliate agreement.</p>
                </div>
                <Link
                    href="/portal/agent/leads"
                    className="flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl hover:bg-gray-800 text-sm font-semibold transition-colors shadow-sm"
                >
                    <Plus size={16} /> Add Lead
                </Link>
            </div>

            <div className="affiliate-summary">
                {cards.map(card => (
                    <div key={card.label} className="affiliate-summary-card">
                        <div className="affiliate-summary-label"><span>{card.label}</span><span className="affiliate-icon">{card.icon}</span></div>
                        <p className="affiliate-summary-value">{card.value}</p>
                    </div>
                ))}
            </div>
            <ReferralLinkCard referral={referral} />

            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-bold text-gray-900">Recent Leads</h2>
                    <Link href="/portal/agent/leads" className="inline-flex items-center gap-1 text-[12px] font-bold text-gray-700 hover:text-gray-900">
                        View all <ArrowRight size={12} />
                    </Link>
                </div>
                {recent.length === 0 ? (
                    <div className="px-6 py-16 text-center text-gray-400">
                        <UsersIcon className="w-10 h-10 mx-auto mb-3 text-gray-200" />
                        <p className="font-semibold">No leads yet</p>
                        <p className="text-sm mt-1">
                            <Link href="/portal/agent/leads" className="text-teal-600 font-semibold hover:underline">Add your first lead</Link>.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-50">
                        {recent.map((l) => (
                            <li key={l.id} className="flex items-center gap-3 px-6 py-3.5 hover:bg-gray-50/50 transition-colors">
                                <Avatar name={l.name} colorKey={l.id} size={36} />
                                <div className="min-w-0 flex-1">
                                    <div className="font-semibold text-gray-900 text-sm truncate">{l.name}</div>
                                    {(l.tags || []).filter(tag => tag.startsWith('Affiliate partner:')).map(tag => <span key={tag} className="affiliate-tag my-1">{tag}</span>)}
                                    <div className="flex items-center gap-3 text-[12px] text-gray-500 truncate">
                                        {l.email && <span className="inline-flex items-center gap-1"><Mail size={11} />{l.email}</span>}
                                        {l.phone && <span className="inline-flex items-center gap-1"><Phone size={11} />{l.phone}</span>}
                                    </div>
                                </div>
                                <span className="text-[11px] font-semibold text-gray-400 tabular-nums flex-shrink-0">{fmtDate(l.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

import { Head, Link } from "@inertiajs/react";
import { ArrowLeft, CheckCircle2, XCircle, Clock, Mail, Smartphone } from "lucide-react";

const fmt = (iso) => (iso ? new Date(iso).toLocaleString("en-US", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");

const STATUS_CHIP = {
    scheduled: "bg-amber-50 text-amber-700",
    sending: "bg-blue-50 text-blue-700",
    sent: "bg-emerald-50 text-emerald-700",
    failed: "bg-rose-50 text-rose-700",
    canceled: "bg-gray-100 text-gray-500",
    queued: "bg-blue-50 text-blue-700",
};

const ROW_ICON = {
    sent: <CheckCircle2 size={14} className="text-emerald-500" />,
    queued: <Clock size={14} className="text-blue-500" />,
    failed: <XCircle size={14} className="text-rose-500" />,
};

export default function CampaignDetail({ campaign, recipients = [], basePath = "/portal/sales/bulk-email", channel = "email" }) {
    const isSms = channel === "sms";
    return (
        <div className="space-y-6 max-w-4xl mx-auto pb-12">
            <Head title={`${campaign.name} — Campaign`} />

            <Link href={basePath} className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800">
                <ArrowLeft size={15} /> Back to {isSms ? "Bulk SMS" : "Bulk Email"}
            </Link>

            <header className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">{isSms ? <Smartphone className="w-6 h-6 text-gray-700" /> : <Mail className="w-6 h-6 text-gray-700" />} {campaign.name}</h1>
                    <p className="text-sm text-gray-500 mt-1">by {campaign.created_by || "—"} · {fmt(campaign.created_at)}</p>
                </div>
                <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold capitalize ${STATUS_CHIP[campaign.status] || "bg-gray-100 text-gray-500"}`}>{campaign.status}</span>
            </header>

            <div className={campaign.skipped > 0 ? "grid grid-cols-2 gap-3 sm:grid-cols-4" : "grid grid-cols-3 gap-3"}>
                {[
                    { label: "Recipients", value: campaign.total },
                    { label: "Sent", value: campaign.sent },
                    { label: "Failed", value: campaign.failed },
                    ...(campaign.skipped > 0 ? [{ label: "Skipped", value: campaign.skipped }] : []),
                ].map((s) => (
                    <div key={s.label} className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">{s.label}</p>
                        <p className="text-2xl font-bold text-gray-900 mt-1">{s.value}</p>
                    </div>
                ))}
            </div>

            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-2">
                {!isSms && (
                    <>
                        <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Subject</span>
                        <p className="text-sm font-semibold text-gray-900">{campaign.subject}</p>
                    </>
                )}
                <span className={`text-[10px] font-bold uppercase tracking-wider text-gray-400 block ${isSms ? "" : "pt-2"}`}>{isSms ? "Message" : "Body"}</span>
                {isSms ? (
                    <pre className="whitespace-pre-wrap font-sans text-sm text-gray-700 leading-relaxed">{campaign.body}</pre>
                ) : (
                    <div className="text-sm text-gray-700 leading-relaxed [&_a]:text-blue-600 [&_a]:underline" dangerouslySetInnerHTML={{ __html: campaign.body }} />
                )}
            </div>

            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-5 py-3 border-b border-gray-100"><span className="text-xs font-bold uppercase tracking-wider text-gray-500">Recipients ({recipients.length})</span></div>
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead>
                            <tr className="bg-gray-50/50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                <th className="px-5 py-2.5">Lead</th>
                                <th className="px-5 py-2.5">{isSms ? "Phone" : "Email"}</th>
                                <th className="px-5 py-2.5">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {recipients.length === 0 ? (
                                <tr><td colSpan={3} className="px-5 py-12 text-center text-sm text-gray-400">No recipient logs yet — the campaign may still be queued.</td></tr>
                            ) : recipients.map((r) => (
                                <tr key={r.id} className="hover:bg-gray-50/40">
                                    <td className="px-5 py-2.5 text-sm text-gray-800">{r.name}</td>
                                    <td className="px-5 py-2.5 text-sm text-gray-500">{r.email}</td>
                                    <td className="px-5 py-2.5">
                                        <span className="inline-flex items-center gap-1.5 text-xs font-medium capitalize" title={r.error || ""}>
                                            {ROW_ICON[r.status] || <Clock size={14} className="text-gray-400" />} {r.status}
                                        </span>
                                        {r.status === "skipped" && r.error && <p className="mt-1 text-xs text-gray-500">{r.error}</p>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

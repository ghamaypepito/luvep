import { useEffect, useMemo, useRef, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import {
    ArrowLeft, Mail, Phone, MapPin, Users, FileSignature, Download, Eye,
    Wand2, X, Loader, Plus, FileText, RefreshCw, UsersRound, PenTool,
    CheckCircle2, Circle, Wallet, GraduationCap, TrendingUp,
} from "lucide-react";
import AgreementSignModal from "@/components/AgreementSignModal";
import PdfViewerModal from "@/components/PdfViewerModal";
import Pagination from "@/components/ui/Pagination";
import ReferralLinkCard from "@/components/affiliate/ReferralLinkCard";

export default function AgentShow({
    agent = {},
    leads = [],
    leadsCount = 0,
    leadPagination = [],
    convertedCount = 0,
    agreement = null,
    agreements = [],
    agreementFieldGroups = [],
    agreementDefaults = {},
    previewBase = "",
    commission = null,
    statusCounts = [],
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [signOpen, setSignOpen] = useState(false);
    const [viewOpen, setViewOpen] = useState(false);

    // Same rule as the Sales agent-leads screen: everything not closed out.
    const inPipeline = statusCounts.filter((row) => row.status !== "Closed" && row.status !== "Not Qualified").reduce((total, row) => total + row.count, 0);

    const initials = (name = "") =>
        (name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((s) => s[0].toUpperCase()).join("") || "?";

    const fmtDate = (iso) => iso
        ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric" })
        : "—";
    const fmtSize = (b) => (! b ? "—" : b < 1024 * 1024 ? `${(b / 1024).toFixed(1)} KB` : `${(b / 1024 / 1024).toFixed(2)} MB`);

    const stageChip = (s) => {
        if (! s) return "bg-gray-100 text-gray-500 border-gray-200";
        if (/started course|approved|unconditional offer/i.test(s)) return "bg-emerald-50 text-emerald-700 border-emerald-200";
        if (/not qualified/i.test(s)) return "bg-gray-100 text-gray-500 border-gray-200";
        if (/new/i.test(s)) return "bg-rose-50 text-rose-700 border-rose-200";
        if (/qualified/i.test(s)) return "bg-amber-50 text-amber-700 border-amber-200";
        if (/consultation/i.test(s)) return "bg-purple-50 text-purple-700 border-purple-200";
        if (/proposal|endorsed|offer/i.test(s)) return "bg-teal-50 text-teal-700 border-teal-200";
        return "bg-gray-100 text-gray-600 border-gray-200";
    };

    return (
        <div className="space-y-6 max-w-[1400px] mx-auto pb-12">
            <Head title={`${agent.name} — Affiliate Partner`} />

            <Link href="/admin/agents" className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800">
                <ArrowLeft size={15} /> Back to Affiliate Partners
            </Link>

            {/* Agent header */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 flex items-center gap-4 flex-wrap">
                <div className="w-14 h-14 rounded-full overflow-hidden flex items-center justify-center bg-gray-900 text-white text-lg font-bold shrink-0">
                    {agent.avatar_url ? <img src={agent.avatar_url} alt={agent.name} className="w-full h-full object-cover" /> : initials(agent.name)}
                </div>
                <div className="min-w-0">
                    <p className="text-[10px] font-bold uppercase tracking-[0.28em] text-gray-400">Affiliate Partner</p>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">{agent.name}</h1>
                    <div className="flex items-center gap-4 flex-wrap text-[13px] text-gray-500 mt-1">
                        {agent.email && <span className="inline-flex items-center gap-1.5"><Mail size={13} /> {agent.email}</span>}
                        {agent.phone && <span className="inline-flex items-center gap-1.5"><Phone size={13} /> {agent.phone}</span>}
                        {agent.location && <span className="inline-flex items-center gap-1.5"><MapPin size={13} /> {agent.location}</span>}
                        {agent.registered_at && <span>Registered {fmtDate(agent.registered_at)}</span>}
                        {agent.referral_code && <span className="font-mono text-gray-400">{agent.referral_code}</span>}
                    </div>
                </div>
            </div>

            <ReferralLinkCard referral={agent.referral} />

            {/* Stat cards */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                    <div className="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                        <UsersRound size={20} />
                    </div>
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Leads recruited</div>
                        <div className="text-3xl font-black text-gray-900 tabular-nums leading-none mt-1">{leadsCount}</div>
                    </div>
                </div>
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                    <div className="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <Users size={20} />
                    </div>
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-widest text-gray-400">In pipeline</div>
                        <div className="text-3xl font-black text-gray-900 tabular-nums leading-none mt-1">{inPipeline}</div>
                    </div>
                </div>
            </div>

            {/* Leads by stage — a count per pipeline stage across this agent's leads */}
            {statusCounts.length > 0 && (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-2 mb-4">
                        <TrendingUp size={15} className="text-gray-400" />
                        <h2 className="text-sm font-bold uppercase tracking-wider text-gray-800">Leads by stage</h2>
                    </div>
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        {statusCounts.map((s) => (
                            <div key={s.status} className={`rounded-xl border p-3.5 ${stageChip(s.status)}`}>
                                <div className="text-2xl font-black tabular-nums leading-none">{s.count}</div>
                                <div className="text-[11px] font-bold uppercase tracking-wide mt-1.5 leading-tight">{s.status}</div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Agreement section */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div className="flex items-center justify-between gap-4 flex-wrap">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center">
                            <FileSignature size={18} />
                        </div>
                        <div>
                            <h2 className="text-sm font-bold uppercase tracking-wider text-gray-800">Affiliate Partner Agreement</h2>
                            <p className="text-[12px] text-gray-500 mt-0.5">
                                {agreement
                                    ? `Version ${agreement.version} · ${agreement.status} · ${fmtDate(agreement.created_at)} · ${fmtSize(agreement.size)}`
                                    : "No agreement on file yet."}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {agreement && (
                            <>
                                <button type="button" onClick={() => setViewOpen(true)} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                                    <Eye size={14} /> View
                                </button>
                                <a href={agreement.download_url} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                                    <Download size={14} /> Download
                                </a>
                                {! agreement.company_signed && agreement.can_sign && (
                                    <button type="button" onClick={() => setSignOpen(true)} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold hover:bg-black transition-colors">
                                        <PenTool size={14} /> Sign (ePathways)
                                    </button>
                                )}
                                <button type="button" onClick={() => setModalOpen(true)} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                                    <RefreshCw size={14} /> {agreement.is_draft ? "Edit draft" : "New version"}
                                </button>
                            </>
                        )}
                        {! agreement && (
                            <button type="button" onClick={() => setModalOpen(true)} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold hover:bg-black transition-colors">
                                <Wand2 size={14} /> Generate Affiliate Partner Agreement
                            </button>
                        )}
                    </div>
                </div>

                {agreement?.is_draft && <p className="mt-3 text-sm text-amber-800">Draft. Review and generate the PDF to make this version available to the affiliate partner.</p>}
                {agreement?.legacy && <p className="mt-3 text-sm text-gray-600">This existing PDF is preserved as issued. Generate a new version for further editing or signing.</p>}
                {agreement?.can_send && <button type="button" onClick={() => router.post(`/admin/agents/${agent.id}/agreement/send?agreement=${agreement.id}`)} className="mt-4 rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Send for signature</button>}
                {agreement?.send_requested_at && <p className="mt-3 text-xs text-gray-600">{agreement.sent_at ? "Invitation accepted by the mail transport." : agreement.send_error || "Invitation queued. Delivery status updates after the mail worker runs."}</p>}

                {/* Signature status — both parties */}
                {agreement && (
                    <div className="mt-4 pt-4 border-t border-gray-100 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <SignStatus label="ePathways" signed={agreement.company_signed} name={agreement.company_signer_name} at={agreement.company_signed_at} fmtDate={fmtDate} />
                        <SignStatus label="Affiliate Partner" signed={agreement.agent_signed} name={agreement.agent_signer_name} at={agreement.agent_signed_at} fmtDate={fmtDate} />
                    </div>
                )}
            </div>

            <section className="rounded-2xl border border-gray-100 bg-white p-6">
                <div className="flex justify-between gap-3"><h2 className="text-lg font-semibold">Agreement history</h2><Link className="text-sm text-emerald-800 underline" href="/admin/agents/agreements">All agreements</Link></div>
                <div className="overflow-x-auto mt-4"><table className="w-full text-left text-sm">
                    <thead><tr className="text-gray-500">{["Version", "Status", "Created", "Partner signed", "EP signed", "Expires", "Documents"].map(label => <th className="py-2 pr-4" key={label}>{label}</th>)}</tr></thead>
                    <tbody>{agreements.map(version => <tr key={version.id} className="border-t border-gray-100">
                        <td className="py-3">{version.version}</td><td>{version.status}</td><td>{fmtDate(version.created_at)}</td><td>{fmtDate(version.agent_signed_at)}</td><td>{fmtDate(version.company_signed_at)}</td><td>{version.expires_on || "—"}</td>
                        <td><a className="text-emerald-800 underline mr-3" href={version.view_url} target="_blank" rel="noreferrer">View</a><a className="text-emerald-800 underline" href={version.download_url}>Download</a></td>
                    </tr>)}</tbody>
                </table></div>
                {!agreements.length && <p className="mt-3 text-sm text-gray-500">No agreement versions yet.</p>}
            </section>

            {/* Commission dashboard */}
            {commission && <CommissionSection commission={commission} hasAgreement={!! agreement} />}

            {/* Leads */}
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-bold text-gray-700">Lead history · {leadsCount} referrals · {convertedCount} converted</h3>
                </div>
                {leads.length === 0 ? (
                    <div className="p-12 text-center text-gray-400 text-sm">No leads recruited yet.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr className="bg-gray-50/60 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase tracking-wider">
                                    <th className="px-6 py-3">Lead</th>
                                    <th className="px-3 py-3">Stage</th>
                                    <th className="px-3 py-3">Contact</th>
                                    <th className="px-3 py-3">Location</th>
                                    <th className="px-3 py-3">Added</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {leads.map((l) => (
                                    <tr key={l.id} className="hover:bg-gray-50/60 transition-colors">
                                        <td className="px-6 py-3">
                                            <div className="font-semibold text-gray-900">{l.name}</div>
                                            <div className="text-[10px] text-gray-400 font-mono">{l.lead_id}</div>
                                        </td>
                                        <td className="px-3 py-3">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold border uppercase ${stageChip(l.status)}`}>
                                                {l.status || "—"}
                                            </span>
                                        </td>
                                        <td className="px-3 py-3">
                                            <div className="text-gray-600 truncate max-w-[200px]">{l.email || "—"}</div>
                                            <div className="text-gray-500">{l.phone || "—"}</div>
                                        </td>
                                        <td className="px-3 py-3 text-gray-600">{l.location || "—"}</td>
                                        <td className="px-3 py-3 whitespace-nowrap text-gray-600">{fmtDate(l.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Pagination links={leadPagination} />

            {modalOpen && (
                <AgreementModal
                    agentId={agent.id}
                    fieldGroups={agreementFieldGroups}
                    defaults={agreementDefaults}
                    previewBase={previewBase}
                    isRegenerate={!! agreement && ! agreement.is_draft}
                    draftId={agreement?.is_draft ? agreement.id : null}
                    expiresOn={agreement?.expires_on || ""}
                    onClose={() => setModalOpen(false)}
                />
            )}

            {signOpen && agreement && (
                <AgreementSignModal
                    postUrl={agreement.sign_url}
                    title="Sign for ePathways"
                    reviewUrl={agreement.view_url}
                    onClose={() => setSignOpen(false)}
                />
            )}

            {viewOpen && agreement && (
                <PdfViewerModal
                    url={agreement.view_url}
                    title="Affiliate Partner Agreement"
                    downloadUrl={agreement.download_url}
                    onClose={() => setViewOpen(false)}
                />
            )}
        </div>
    );
}

// Per-party signature status line.
// Commission dashboard — qualifying (started-course) students + the two
// Schedule A tier cards, with the active tier highlighted, and an estimated
// total pulled from the generated agreement's rates.
function CommissionSection({ commission, hasAgreement }) {
    const { qualifying, currency, tiers, total, per_student_amount } = commission;
    const fmtMoney = (n) => (n === null || n === undefined)
        ? "—"
        : new Intl.NumberFormat("en-US", { maximumFractionDigits: 2 }).format(n);
    const cur = currency ? currency : "";

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div className="flex items-center gap-3 mb-4">
                <div className="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center">
                    <Wallet size={18} />
                </div>
                <div>
                    <h2 className="text-sm font-bold uppercase tracking-wider text-gray-800">Commission</h2>
                    <p className="text-[12px] text-gray-500 mt-0.5">
                        Based on referred students who have <strong>started their course</strong>, at the rates in this agent&rsquo;s agreement.
                    </p>
                </div>
            </div>

            {! hasAgreement && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-[12px] text-amber-800">
                    No agreement yet — generate one to set the commission rates. Qualifying students are still counted below.
                </div>
            )}

            {/* Top row: qualifying students + estimated total */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div className="rounded-xl border border-gray-100 bg-gray-50/60 p-4 flex items-center gap-4">
                    <div className="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><GraduationCap size={20} /></div>
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Students started course</div>
                        <div className="text-3xl font-black text-gray-900 tabular-nums leading-none mt-1">{qualifying}</div>
                    </div>
                </div>
                <div className="rounded-xl border border-gray-100 bg-gray-50/60 p-4 flex items-center gap-4">
                    <div className="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><TrendingUp size={20} /></div>
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Estimated commission</div>
                        <div className="text-2xl font-black text-gray-900 tabular-nums leading-none mt-1">
                            {total === null ? "—" : fmtMoney(total)}
                            {cur && total !== null && <span className="text-[11px] font-semibold text-gray-400 ml-1.5">{cur}</span>}
                        </div>
                        {per_student_amount !== null && qualifying > 0 && (
                            <div className="text-[11px] text-gray-400 mt-0.5">{qualifying} × {fmtMoney(per_student_amount)}</div>
                        )}
                    </div>
                </div>
            </div>

            {/* Tier cards */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {tiers.map((t) => (
                    <div key={t.key} className={`rounded-xl border p-4 ${t.active ? "border-emerald-300 bg-emerald-50/60 ring-1 ring-emerald-200" : "border-gray-200 bg-white"}`}>
                        <div className="flex items-center justify-between mb-2">
                            <div className="text-[11px] font-bold uppercase tracking-wider text-gray-600">{t.label}</div>
                            {t.active && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-emerald-600 text-white">
                                    <CheckCircle2 size={10} /> Applies now
                                </span>
                            )}
                        </div>
                        <div className="flex items-end gap-4">
                            <div>
                                <div className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Percentage</div>
                                <div className="text-lg font-black text-gray-900">{t.percent || "—"}</div>
                            </div>
                            <div>
                                <div className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Per student</div>
                                <div className="text-lg font-black text-gray-900">
                                    {t.amount || "—"}{t.amount && cur && <span className="text-[11px] font-semibold text-gray-400 ml-1">{cur}</span>}
                                </div>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function SignStatus({ label, signed, name, at, fmtDate }) {
    return (
        <div className={`flex items-start gap-2.5 rounded-xl border px-4 py-2.5 ${signed ? "border-emerald-200 bg-emerald-50/60" : "border-gray-200 bg-gray-50/60"}`}>
            {signed
                ? <CheckCircle2 size={16} className="text-emerald-600 mt-0.5 shrink-0" />
                : <Circle size={16} className="text-gray-300 mt-0.5 shrink-0" />}
            <div className="min-w-0">
                <p className="text-[10px] font-bold uppercase tracking-widest text-gray-400">{label}</p>
                {signed ? (
                    <p className="text-[13px] font-semibold text-emerald-900 truncate">
                        Signed by {name} <span className="text-[11px] font-normal text-emerald-700">· {fmtDate(at)}</span>
                    </p>
                ) : (
                    <p className="text-[13px] text-gray-500">Not signed yet</p>
                )}
            </div>
        </div>
    );
}

// ── Generate / edit agreement modal ──────────────────────────────────
function AgreementModal({ agentId, fieldGroups, defaults, previewBase, isRegenerate, draftId, expiresOn, onClose }) {
    const [fields, setFields] = useState(() => ({ ...defaults }));
    const [previewFields, setPreviewFields] = useState(() => ({ ...defaults }));
    const [previewLoading, setPreviewLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [expiry, setExpiry] = useState(expiresOn || "");
    const timer = useRef(null);

    const setField = (key, val) => setFields((f) => ({ ...f, [key]: val }));

    // Debounce the preview so it refreshes ~half a second after typing stops.
    useEffect(() => {
        clearTimeout(timer.current);
        timer.current = setTimeout(() => setPreviewFields({ ...fields }), 500);
        return () => clearTimeout(timer.current);
    }, [fields]);

    const previewUrl = useMemo(() => {
        const params = new URLSearchParams();
        Object.entries(previewFields).forEach(([k, v]) => params.set(k, v ?? ""));
        return `${previewBase}?${params.toString()}`;
    }, [previewFields, previewBase]);

    useEffect(() => { setPreviewLoading(true); }, [previewUrl]);

    const submit = (intent = "generate") => {
        setSubmitting(true);
        router.post(`/admin/agents/${agentId}/agreement/generate`, { ...fields, intent, draft_id: draftId, expires_on: expiry || null }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-3">
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-[1400px] h-[94vh] flex flex-col overflow-hidden">
                <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between shrink-0">
                    <div className="flex items-center gap-2.5">
                        <div className="w-9 h-9 rounded-lg bg-gray-900 flex items-center justify-center">
                            <FileSignature size={16} className="text-white" />
                        </div>
                        <div>
                            <h3 className="text-[15px] font-bold text-gray-900 leading-tight">{isRegenerate ? "Regenerate" : "Generate"} agreement</h3>
                            <p className="text-[11px] text-gray-500 mt-0.5">Fill the editable fields — blanks show a grey guide in the document. Preview updates as you type.</p>
                        </div>
                    </div>
                    <button onClick={onClose} className="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center"><X size={18} /></button>
                </div>

                <div className="flex-1 flex min-h-0">
                    {/* Fields */}
                    <div className="w-[420px] border-r border-gray-100 overflow-y-auto p-5 space-y-5 shrink-0">
                        {fieldGroups.map((group) => (
                            <div key={group.group}>
                                <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-500 mb-2">{group.group}</p>
                                <div className="space-y-2.5">
                                    {group.fields.map((f, i) => (
                                        f.type === "pair" ? (
                                            // Percentage | amount side by side under one row label.
                                            <div key={i} className="block">
                                                <span className="block text-[11px] font-semibold text-gray-600 mb-1">{f.label}</span>
                                                <div className="grid grid-cols-[100px_1fr] gap-2">
                                                    <input
                                                        type="text"
                                                        value={fields[f.left.key] ?? ""}
                                                        onChange={(e) => setField(f.left.key, e.target.value)}
                                                        placeholder={f.left.placeholder}
                                                        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                                                    />
                                                    <input
                                                        type="text"
                                                        value={fields[f.right.key] ?? ""}
                                                        onChange={(e) => setField(f.right.key, e.target.value)}
                                                        placeholder={f.right.placeholder}
                                                        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                                                    />
                                                </div>
                                            </div>
                                        ) : (
                                            <label key={f.key} className="block">
                                                <span className="block text-[11px] font-semibold text-gray-600 mb-1">{f.label}</span>
                                                {f.type === "textarea" ? (
                                                    <textarea
                                                        rows={2}
                                                        value={fields[f.key] ?? ""}
                                                        onChange={(e) => setField(f.key, e.target.value)}
                                                        placeholder={f.placeholder}
                                                        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                                                    />
                                                ) : (
                                                    <input
                                                        type="text"
                                                        value={fields[f.key] ?? ""}
                                                        onChange={(e) => setField(f.key, e.target.value)}
                                                        placeholder={f.placeholder}
                                                        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                                                    />
                                                )}
                                            </label>
                                        )
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Preview */}
                    <div className="flex-1 flex flex-col min-h-0 bg-gray-50">
                        <div className="px-4 py-2.5 border-b border-gray-100 bg-white flex items-center gap-2 shrink-0">
                            <span className="text-[11px] font-bold uppercase tracking-[0.14em] text-gray-500">Preview</span>
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-900 text-white text-[11px] font-semibold">
                                <FileText size={11} /> Affiliate Partner Agreement
                            </span>
                        </div>
                        <div className="flex-1 relative min-h-0">
                            <iframe
                                key={previewUrl}
                                src={previewUrl}
                                title="Agreement preview"
                                sandbox="allow-same-origin"
                                onLoad={() => setPreviewLoading(false)}
                                className="absolute inset-0 w-full h-full bg-white"
                            />
                            {previewLoading && (
                                <div className="absolute inset-0 flex items-center justify-center bg-white/85 backdrop-blur-sm z-10">
                                    <Loader size={22} className="animate-spin text-gray-500" />
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-end gap-3 shrink-0 bg-white">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900">Cancel</button>
                    <label className="mr-auto text-xs text-gray-600">Expiry date (optional)<input type="date" value={expiry} onChange={e => setExpiry(e.target.value)} className="ml-2 rounded border border-gray-200 p-1" /></label>
                    <button type="button" disabled={submitting} onClick={() => submit("draft")} className="px-4 py-2 text-sm font-semibold text-emerald-800">Save draft</button>
                    <button
                        type="button"
                        disabled={submitting}
                        onClick={() => submit("generate")}
                        className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-lg text-sm font-bold hover:bg-black disabled:opacity-60 transition-colors"
                    >
                        {submitting ? <Loader size={14} className="animate-spin" /> : <Plus size={14} />}
                        {isRegenerate ? "Generate new version" : "Generate PDF"}
                    </button>
                </div>
            </div>
        </div>
    );
}

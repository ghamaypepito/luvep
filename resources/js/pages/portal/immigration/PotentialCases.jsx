import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import {
    ChevronDown, ChevronRight, Check, Circle, Users, Headphones, FileSignature,
    ShieldCheck, ArrowUpRight, XCircle, UserCheck,
} from "lucide-react";

const STATUS_BADGE = {
    onboarding: "bg-amber-50 text-amber-700 border-amber-200",
    signed: "bg-blue-50 text-blue-700 border-blue-200",
    promoted: "bg-green-50 text-green-700 border-green-200",
    declined: "bg-slate-100 text-slate-500 border-slate-200",
};
const STATUS_LABEL = { onboarding: "Onboarding", signed: "Agreement signed", promoted: "Promoted", declined: "Declined" };

function fmt(dt) {
    if (!dt) return null;
    try { return new Date(dt).toLocaleString(undefined, { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }); }
    catch { return dt; }
}

const base = "/portal/immigration/potential-cases";
const post = (url, data = {}, opts = {}) => router.post(url, data, { preserveScroll: true, ...opts });

// Step row with a done/pending marker.
function Step({ done, icon: Icon, title, children }) {
    return (
        <div className="flex gap-3 py-2.5 border-t border-gray-100 first:border-t-0">
            <div className={`mt-0.5 shrink-0 w-5 h-5 rounded-full flex items-center justify-center ${done ? "bg-green-500 text-white" : "bg-gray-100 text-gray-300"}`}>
                {done ? <Check size={12} /> : <Icon size={12} />}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold text-gray-800">{title}</p>
                <div className="mt-1 text-xs text-gray-600">{children}</div>
            </div>
        </div>
    );
}

// Log-a-consultation form (datetime + attending staff + note).
function MeetingForm({ pc, no, staff }) {
    const [at, setAt] = useState("");
    const [picked, setPicked] = useState([]);
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);

    const toggle = (s) => setPicked((p) => p.find((x) => x.id === s.id) ? p.filter((x) => x.id !== s.id) : [...p, s]);

    const submit = () => {
        if (!at || picked.length === 0) return;
        setSaving(true);
        post(`${base}/${pc.id}/meeting`, { meeting_no: no, at, staff: picked, note: note || null }, { onFinish: () => setSaving(false) });
    };

    return (
        <div className="space-y-2">
            <input type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)}
                className="text-xs border border-gray-200 rounded-md py-1.5 px-2 bg-white" />
            <div className="flex flex-wrap gap-1">
                {staff.map((s) => {
                    const on = picked.find((x) => x.id === s.id);
                    return (
                        <button key={s.id} type="button" onClick={() => toggle(s)}
                            className={`text-[11px] px-2 py-0.5 rounded-full border ${on ? "bg-[#009688] text-white border-[#009688]" : "bg-white text-gray-600 border-gray-200 hover:border-gray-300"}`}>
                            {s.name}
                        </button>
                    );
                })}
            </div>
            <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} placeholder="Note (what was discussed)"
                className="w-full text-xs border border-gray-200 rounded-md py-1.5 px-2 bg-white" />
            <button type="button" onClick={submit} disabled={saving || !at || picked.length === 0}
                className="text-xs font-semibold bg-[#009688] text-white px-3 py-1.5 rounded-md hover:bg-[#00877a] disabled:opacity-40">
                {saving ? "Saving…" : `Log consultation ${no}`}
            </button>
        </div>
    );
}

function MeetingDone({ m }) {
    return (
        <div className="text-xs text-gray-600">
            <span className="font-medium text-gray-800">{fmt(m.at)}</span>
            {m.staff?.length > 0 && <> · with {m.staff.map((s) => s.name).join(", ")}</>}
            {m.note && <p className="text-gray-500 mt-0.5">{m.note}</p>}
        </div>
    );
}

function PotentialCaseCard({ pc, staff, canOverride, expanded, onToggle }) {
    const [overrideReason, setOverrideReason] = useState("");
    const [declineReason, setDeclineReason] = useState("");
    const terminal = pc.status === "promoted" || pc.status === "declined";
    const m1 = pc.meetings?.[1], m2 = pc.meetings?.[2];
    const progress = [!!m1, !!m2, !!pc.audio_ack_at, !!pc.agreement_signed_at].filter(Boolean).length;

    return (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <button type="button" onClick={onToggle} className="w-full flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50/60 text-left">
                <div className="flex items-center gap-3 min-w-0">
                    {expanded ? <ChevronDown size={16} className="text-gray-400 shrink-0" /> : <ChevronRight size={16} className="text-gray-400 shrink-0" />}
                    <div className="min-w-0">
                        <p className="text-sm font-semibold text-gray-900 truncate">{pc.name} {pc.lead_ref && <span className="text-[11px] text-gray-400 font-normal">· {pc.lead_ref}</span>}</p>
                        {pc.email && <p className="text-[11px] text-gray-500 truncate">{pc.email}</p>}
                    </div>
                </div>
                <div className="flex items-center gap-3 shrink-0">
                    {!terminal && <span className="text-[11px] text-gray-400">{progress}/4</span>}
                    <span className={`text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full border ${STATUS_BADGE[pc.status]}`}>{STATUS_LABEL[pc.status]}</span>
                </div>
            </button>

            {expanded && (
                <div className="px-4 pb-4 border-t border-gray-100">
                    {/* Consultation 1 */}
                    <Step done={!!m1} icon={Users} title="Consultation 1 — initial">
                        {m1 ? <MeetingDone m={m1} /> : (terminal ? <span className="text-gray-400">Not logged</span> : <MeetingForm pc={pc} no={1} staff={staff} />)}
                    </Step>
                    {/* Consultation 2 */}
                    <Step done={!!m2} icon={Users} title="Consultation 2 — after agreement sent">
                        {m2 ? <MeetingDone m={m2} /> : (terminal ? <span className="text-gray-400">Not logged</span> : <MeetingForm pc={pc} no={2} staff={staff} />)}
                    </Step>
                    {/* Key-matters audio */}
                    <Step done={!!pc.audio_ack_at} icon={Headphones} title="Key matters acknowledged">
                        {pc.audio_ack_at
                            ? <span>Acknowledged {fmt(pc.audio_ack_at)}{pc.audio_ack_by ? ` · logged by ${pc.audio_ack_by}` : ""}</span>
                            : (terminal ? <span className="text-gray-400">Not acknowledged</span>
                                : <button type="button" onClick={() => post(`${base}/${pc.id}/acknowledge-audio`)}
                                    className="text-xs font-semibold border border-gray-200 px-3 py-1.5 rounded-md hover:bg-gray-50">
                                    Mark client acknowledged
                                </button>)}
                    </Step>
                    {/* Written agreement */}
                    <Step done={!!pc.agreement_signed_at} icon={FileSignature} title="Initial written agreement">
                        {pc.agreement_signed_at ? (
                            <span>Signed {fmt(pc.agreement_signed_at)}</span>
                        ) : terminal ? <span className="text-gray-400">Not signed</span> : (
                            <div className="space-y-2">
                                {!pc.agreement_sent_at
                                    ? <button type="button" onClick={() => post(`${base}/${pc.id}/agreement-sent`)} className="text-xs font-semibold border border-gray-200 px-3 py-1.5 rounded-md hover:bg-gray-50">Mark agreement sent</button>
                                    : <p className="text-[11px] text-gray-500">Sent {fmt(pc.agreement_sent_at)}</p>}
                                {pc.gate_passed ? (
                                    <button type="button" onClick={() => post(`${base}/${pc.id}/sign`)}
                                        className="text-xs font-semibold bg-[#009688] text-white px-3 py-1.5 rounded-md hover:bg-[#00877a]">Record signature</button>
                                ) : (
                                    <p className="text-[11px] text-amber-600">Both consultations + acknowledgment are required before signing.</p>
                                )}
                            </div>
                        )}
                    </Step>

                    {/* Override (licensed adviser) */}
                    {!terminal && !pc.gate_passed && canOverride && (
                        <Step done={false} icon={ShieldCheck} title="Licensed adviser override">
                            <div className="space-y-1.5">
                                <input value={overrideReason} onChange={(e) => setOverrideReason(e.target.value)} placeholder="Reason for overriding the consultation gate"
                                    className="w-full text-xs border border-gray-200 rounded-md py-1.5 px-2 bg-white" />
                                <button type="button" disabled={!overrideReason} onClick={() => post(`${base}/${pc.id}/override`, { reason: overrideReason })}
                                    className="text-xs font-semibold border border-amber-300 text-amber-700 px-3 py-1.5 rounded-md hover:bg-amber-50 disabled:opacity-40">Override gate</button>
                            </div>
                        </Step>
                    )}
                    {pc.override && (
                        <p className="text-[11px] text-amber-600 mt-2 flex items-center gap-1.5"><ShieldCheck size={12} /> Gate overridden by {pc.override.by} — "{pc.override.reason}" ({fmt(pc.override.at)})</p>
                    )}

                    {/* Promote / Decline */}
                    {pc.status === "promoted" ? (
                        <p className="mt-3 text-xs text-green-700 flex items-center gap-1.5"><UserCheck size={13} /> Promoted to official case by {pc.promotion?.by} · {fmt(pc.promotion?.at)}</p>
                    ) : pc.status === "declined" ? (
                        <p className="mt-3 text-xs text-gray-500 flex items-center gap-1.5"><XCircle size={13} /> Declined · {pc.decline?.reason} ({fmt(pc.decline?.at)})</p>
                    ) : (
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <button type="button" disabled={pc.status !== "signed"} onClick={() => post(`${base}/${pc.id}/promote`)}
                                className="text-xs font-semibold bg-green-600 text-white px-3 py-1.5 rounded-md hover:bg-green-700 disabled:opacity-40 inline-flex items-center gap-1.5">
                                <ArrowUpRight size={13} /> Move to official case
                            </button>
                            <div className="flex items-center gap-1.5 ml-auto">
                                <input value={declineReason} onChange={(e) => setDeclineReason(e.target.value)} placeholder="Decline reason"
                                    className="text-xs border border-gray-200 rounded-md py-1.5 px-2 bg-white w-48" />
                                <button type="button" disabled={!declineReason} onClick={() => post(`${base}/${pc.id}/decline`, { reason: declineReason })}
                                    className="text-xs font-semibold border border-gray-200 text-gray-600 px-3 py-1.5 rounded-md hover:bg-gray-50 disabled:opacity-40">Decline</button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Potential Cases — onboarding/vetting pipeline before a lead becomes an
 * official immigration case. Two logged consultations + a key-matters audio
 * acknowledgment gate the written-agreement signing; passing promotes the lead.
 */
export default function PotentialCases({ potentialCases = [], staff = [], eligibleLeads = [], canOverride = false }) {
    const [expandedId, setExpandedId] = useState(null);
    const [addLead, setAddLead] = useState("");
    const active = potentialCases.filter((p) => p.status === "onboarding" || p.status === "signed");
    const done = potentialCases.filter((p) => p.status === "promoted" || p.status === "declined");

    const add = () => {
        if (!addLead) return;
        post(base, { lead_id: addLead }, { onSuccess: () => setAddLead("") });
    };

    return (
        <div className="max-w-4xl mx-auto py-6 px-4 space-y-5">
            <Head title="Potential Cases" />
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-xl font-bold text-gray-900">Potential Cases</h1>
                    <p className="text-sm text-gray-500 mt-0.5">Onboarding checks before a lead becomes an official case — two consultations and the key-matters acknowledgment before signing.</p>
                </div>
                <div className="flex items-center gap-2">
                    <select value={addLead} onChange={(e) => setAddLead(e.target.value)}
                        className="text-xs border border-gray-200 rounded-md py-1.5 px-2 bg-white max-w-[260px]">
                        <option value="">Mark a lead as potential case…</option>
                        {eligibleLeads.map((l) => <option key={l.id} value={l.id}>{l.label}</option>)}
                    </select>
                    <button type="button" onClick={add} disabled={!addLead}
                        className="text-xs font-semibold bg-[#009688] text-white px-3 py-1.5 rounded-md hover:bg-[#00877a] disabled:opacity-40">Add</button>
                </div>
            </div>

            {potentialCases.length === 0 && (
                <div className="bg-white rounded-xl border border-gray-200 p-10 text-center text-sm text-gray-400">
                    No potential cases yet. Mark a lead as a potential case to start its onboarding.
                </div>
            )}

            <div className="space-y-2">
                {active.map((pc) => (
                    <PotentialCaseCard key={pc.id} pc={pc} staff={staff} canOverride={canOverride}
                        expanded={expandedId === pc.id} onToggle={() => setExpandedId(expandedId === pc.id ? null : pc.id)} />
                ))}
            </div>

            {done.length > 0 && (
                <div className="space-y-2">
                    <p className="text-[11px] font-bold uppercase tracking-[0.15em] text-gray-400 pt-2">Closed</p>
                    {done.map((pc) => (
                        <PotentialCaseCard key={pc.id} pc={pc} staff={staff} canOverride={canOverride}
                            expanded={expandedId === pc.id} onToggle={() => setExpandedId(expandedId === pc.id ? null : pc.id)} />
                    ))}
                </div>
            )}
        </div>
    );
}

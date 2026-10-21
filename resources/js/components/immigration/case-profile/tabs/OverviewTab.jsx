/**
 * Case Overview — the at-a-glance dashboard: where the case is in the pipeline,
 * what needs attention, document + family progress, the key facts, and recent
 * activity. Wired to the real case props; a few derived numbers fall back
 * gracefully when the underlying data isn't present.
 */
import { useState, useRef } from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import { Pin, CheckSquare, Square, Paperclip, X, FileText, Plus, Pencil } from "lucide-react";
import { leadBase } from "@/lib/caseRoutes";

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "—");
const fmtShort = (iso) => (iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short" }) : "—");
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString("en-NZ", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "—");
const money = (n) => `$${Number(n || 0).toLocaleString("en-NZ")}`;

export default function OverviewTab(props) {
    const {
        lead = {}, documents = [], checklistProgress = {}, findings = { items: [] },
        financials = {}, dependents = [], activity = [], notes = [], engagement = {},
        tasks = { items: [] }, caseStaff = [],
        onNavigate,
    } = props;

    const go = (tab) => onNavigate && onNavigate(tab);

    const openedIso = lead.created_at || lead.immigration_converted_at || null;
    const daysOpen = openedIso ? Math.max(0, Math.floor((Date.now() - new Date(openedIso).getTime()) / 86400000)) : null;
    const reqTotal = checklistProgress.required_total ?? 0;
    const reqApproved = checklistProgress.required_approved ?? 0;
    const withAdviser = documents.filter((d) => d.status === "Checked").length;
    const outstanding = financials?.totals?.outstanding ?? financials?.totals?.balance ?? 0;
    const paidAt = (financials?.payments || []).map((p) => p.paid_at).filter(Boolean).sort()[0] || null;

    // Document status tally across the case's files.
    const tally = documents.reduce((a, d) => {
        const s = (d.status || "").toLowerCase();
        if (s === "approved") a.approved++;
        else if (s === "checked") a.checked++;
        else if (s === "submitted" || s === "underreview" || s === "under_review") a.submitted++;
        return a;
    }, { approved: 0, checked: 0, submitted: 0 });
    const missing = Math.max(0, reqTotal - (tally.approved + tally.checked + tally.submitted));

    // Per-category document progress (matches the checklist split).
    const infoForm = { done: props.vif && (props.vif.status === "Approved" || props.vif.completed) ? 1 : 0, total: 1 };
    const applicant = { done: reqApproved, total: reqTotal };
    const depDone = dependents.reduce((s, d) => s + (d.progress?.required_done ?? 0), 0);
    const depTotal = dependents.reduce((s, d) => s + (d.progress?.required_total ?? 0), 0);

    // INZ lodgement / decision are driven by the immigration STAGE (Visa Lodged
    // and beyond), with the INZ status field kept as a secondary signal so either
    // lights the milestone.
    const stage = lead.immigration_stage || "";
    const LODGED_STAGES = new Set(["Visa Lodged", "Interim Visa Issued", "Request for Medical", "Request for Information", "RFI Responded", "Approved in Principle", "Approved Visa", "Decline Visa"]);
    const DECISION_STAGES = new Set(["Approved Visa", "Decline Visa", "Withdrawn"]);
    const lodged = LODGED_STAGES.has(stage) || /lodg|decision|approv|declin/i.test(lead.inz_status || "");
    const decided = DECISION_STAGES.has(stage) || /approv|declin/i.test(lead.inz_status || "");
    // Negative outcomes recolour the outcome bars: declined = red, withdrawn =
    // orange (an approval stays teal).
    const declined = stage === "Decline Visa" || /declin/i.test(lead.inz_status || "");
    const withdrawn = stage === "Withdrawn" || /withdraw/i.test(lead.inz_status || "");
    const outcomeTone = declined ? "danger" : withdrawn ? "warn" : null;

    // INZ processing tracker — days since the real lodgement date vs the visa
    // type's expected window (staff-set from the INZ website; see VisaType.
    // expected_processing_days). Same green/amber/red bands as the dashboard.
    const lodgedAt = lead.inz_lodged_at || null;
    const decisionAt = lead.inz_decision_at || null;
    const expectedDays = lead.expected_processing_days ?? null;
    const dayDiff = (a, b) => Math.max(0, Math.floor((new Date(b).getTime() - new Date(a).getTime()) / 86400000));
    const daysSinceLodged = lodgedAt ? dayDiff(lodgedAt, decisionAt || Date.now()) : null;
    const inzBand = (daysSinceLodged != null && expectedDays)
        ? (daysSinceLodged > expectedDays ? "red" : daysSinceLodged >= expectedDays - 5 ? "amber" : "green")
        : null;
    const INZ_BAND = {
        green: { bar: "bg-teal-600", text: "text-teal-600", label: decisionAt ? "On time" : "On track" },
        amber: { bar: "bg-amber-500", text: "text-amber-600", label: decisionAt ? "On time" : "Due soon" },
        red: { bar: "bg-red-600", text: "text-red-600", label: decisionAt ? "Over expected" : "Overdue" },
    };

    // Pipeline stepper — each stage's state derived from case milestones.
    const steps = [
        { key: "assessment", label: "Assessment", done: !!lead.immigration_converted_at, sub: lead.immigration_converted_at ? `converted ${fmtShort(lead.immigration_converted_at)}` : "not converted" },
        { key: "engagement", label: "Engagement", done: !!engagement.sent, sub: engagement.signed ? `signed ${fmtShort(engagement.signed_at)}` : engagement.sent ? `sent ${fmtShort(engagement.sent_at)}` : "not sent" },
        { key: "invoice", label: "Invoice", done: !!engagement.invoice_paid, sub: engagement.invoice_paid ? `paid ${fmtShort(engagement.invoice_paid_at)}` : engagement.sent ? "sent · awaiting payment" : "not sent" },
        { key: "documents", label: "Documents", done: reqTotal > 0 && reqApproved >= reqTotal, sub: `${reqApproved} of ${reqTotal} approved` },
        { key: "lodgement", label: "INZ lodgement", done: lodged, tone: outcomeTone, sub: lodged ? (lead.inz_status || stage || "lodged") : "not started" },
        { key: "decision", label: "Decision", done: decided, tone: outcomeTone, sub: decided ? (lead.inz_status || stage) : "—" },
    ];
    // Colour rule: completed stages are teal; every incomplete stage up to and
    // including the one just past the furthest completed milestone (the current
    // frontier) is amber; stages beyond the frontier are grey.
    const stepFrontier = steps.map((s) => s.done).lastIndexOf(true) + 1;

    // Needs attention — real findings first, then derived document nudges.
    const attentionItems = [];
    (findings.items || []).filter((f) => ["blocking", "check"].includes(f.severity)).slice(0, 4).forEach((f) => {
        attentionItems.push({ id: `f-${f.id}`, severity: f.severity, title: f.title, detail: f.detail, action: "Resolve", tab: "documents" });
    });
    if (withAdviser > 0) {
        attentionItems.unshift({ id: "adviser", severity: "blocking", title: `${withAdviser} document${withAdviser === 1 ? "" : "s"} waiting on the adviser`, detail: "Referred for the licensed adviser's verdict.", action: "Open review", tab: "documents" });
    }
    if (reqTotal > reqApproved) {
        attentionItems.push({ id: "req", severity: "check", title: `${reqTotal - reqApproved} required document${reqTotal - reqApproved === 1 ? "" : "s"} not yet approved`, detail: `${tally.submitted} submitted, ${tally.checked} checked by manager, ${missing} missing`, action: "Request", tab: "documents" });
    }

    const pinned = notes.find((n) => n.pinned) || null;
    const recent = (activity || []).slice(0, 5);

    return (
        <div className="space-y-5">
            {/* Where this case is — full-width row, edge to edge */}
            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div className="flex items-center justify-between gap-3 mb-3">
                    <h2 className="text-[15px] font-bold text-gray-900">Where this case is
                        {daysOpen != null && <span className="text-[12px] font-normal text-gray-400 ml-2">Day {daysOpen}{lead.target_lodgement_at ? ` · target lodgement ${fmtDate(lead.target_lodgement_at)}` : ""}</span>}
                    </h2>
                </div>
                <div className="grid gap-2.5" style={{ gridTemplateColumns: "repeat(6, minmax(0, 1fr))" }}>
                        {steps.map((s, i) => {
                            const state = s.done ? "done" : i <= stepFrontier ? "current" : "todo";
                            // A completed outcome bar recolours by result: declined = red,
                            // withdrawn = orange, otherwise the usual teal.
                            const barClass = state === "done"
                                ? (s.tone === "danger" ? "bg-red-600" : s.tone === "warn" ? "bg-orange-500" : "bg-teal-600")
                                : state === "current" ? "bg-amber-500" : "bg-gray-200";
                            const subClass = state === "done" && s.tone === "danger"
                                ? "text-red-600"
                                : state === "done" && s.tone === "warn"
                                    ? "text-orange-600"
                                    : state === "current" ? "text-amber-600" : "text-gray-400";
                            return (
                                <div key={s.key}>
                                    <div className={`h-1.5 rounded-full ${barClass}`} />
                                    <p className="text-[12.5px] font-semibold text-gray-900 mt-2">{s.label}</p>
                                    <p className={`text-[11px] mt-0.5 ${subClass}`}>{s.sub}</p>
                                </div>
                            );
                        })}
                    </div>
            </section>

            {/* Internal notes + Tasks — one row, directly under the pipeline */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                <InternalNotes leadId={lead.id} notes={notes} onSeeAll={() => go("notes")} />
                <CaseTasks tasks={tasks} leadId={lead.id} caseStaff={caseStaff} onOpenBoard={() => go("tasks")} />
            </div>

            {/* Two-column body below */}
            <div className="flex flex-col lg:flex-row gap-5 items-start">
                <div className="space-y-5 w-full min-w-0" style={{ flex: "1 1 0%" }}>
                {/* Needs attention */}
                {attentionItems.length > 0 && (
                    <section className="bg-red-50/40 rounded-2xl border border-red-100 p-5">
                        <div className="flex items-center gap-2 mb-3">
                            <h2 className="text-[15px] font-bold text-gray-900">Needs attention</h2>
                            <span className="text-[11px] font-bold text-red-600 bg-white border border-red-200 rounded-full px-2 py-0.5">{attentionItems.length}</span>
                        </div>
                        <div className="space-y-2">
                            {attentionItems.map((a) => (
                                <div key={a.id} className="bg-white rounded-xl border border-gray-100 px-4 py-3 flex items-center gap-3">
                                    <span className={`w-2 h-2 rounded-full flex-shrink-0 ${a.severity === "blocking" ? "bg-red-500" : "bg-amber-500"}`} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[13px] font-semibold text-gray-900">{a.title}</p>
                                        {a.detail && <p className="text-[11.5px] text-gray-500 truncate">{a.detail}</p>}
                                    </div>
                                    <button type="button" onClick={() => go(a.tab)} className="px-3 py-1.5 rounded-lg border border-gray-200 text-[12px] font-semibold text-gray-700 hover:bg-gray-50 flex-shrink-0">{a.action}</button>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                {/* Documents */}
                <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div className="flex items-center justify-between gap-3 mb-3">
                        <h2 className="text-[15px] font-bold text-gray-900">Documents <span className="text-[12px] font-normal text-gray-400 ml-1">{reqApproved} of {reqTotal} approved · {documents.length} files on file</span></h2>
                        <button type="button" onClick={() => go("documents")} className="text-[13px] font-semibold text-teal-700 hover:underline flex-shrink-0">Open documents</button>
                    </div>
                    <ProgressBar segments={[
                        { v: tally.approved, cls: "bg-teal-700" },
                        { v: tally.checked, cls: "bg-teal-500" },
                        { v: tally.submitted, cls: "bg-amber-500" },
                        { v: missing, cls: "bg-gray-200" },
                    ]} total={reqTotal || (tally.approved + tally.checked + tally.submitted + missing) || 1} />
                    <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-[11.5px] text-gray-600">
                        <Legend cls="bg-teal-700" label={`${tally.approved} approved`} />
                        <Legend cls="bg-teal-500" label={`${tally.checked} checked by manager`} />
                        <Legend cls="bg-amber-500" label={`${tally.submitted} submitted`} />
                        <Legend cls="bg-gray-200" label={`${missing} missing`} />
                    </div>
                    <div className="mt-4 space-y-3">
                        <CategoryRow label="Information form" done={infoForm.done} total={infoForm.total} />
                        <CategoryRow label="Applicant documents" done={applicant.done} total={applicant.total} />
                        {depTotal > 0 && <CategoryRow label="Dependant documents" done={depDone} total={depTotal} />}
                    </div>
                </section>

                {/* Family included */}
                {dependents.length > 0 && (
                    <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <div className="flex items-center justify-between gap-3 mb-3">
                            <h2 className="text-[15px] font-bold text-gray-900">Family included <span className="text-[12px] font-normal text-gray-400 ml-1">{dependents.length} dependant{dependents.length === 1 ? "" : "s"}</span></h2>
                            <button type="button" onClick={() => go("dependants")} className="text-[13px] font-semibold text-teal-700 hover:underline">Open family</button>
                        </div>
                        <div className="divide-y divide-gray-50">
                            {dependents.map((dep) => {
                                const done = dep.progress?.required_done ?? 0;
                                const total = dep.progress?.required_total ?? 0;
                                return (
                                    <div key={dep.id} className="py-2.5 flex items-center gap-3">
                                        <span className="w-9 h-9 rounded-lg bg-gray-100 flex-shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <p className="text-[13px] font-semibold text-gray-900 truncate">{dep.full_name}</p>
                                            <p className="text-[11px] text-gray-400 truncate">{dep.dob ? `DOB ${dep.dob}` : ""}{dep.nationality ? ` · ${dep.nationality}` : ""}{dep.visa_name ? ` · ${dep.visa_name}` : ""}</p>
                                        </div>
                                        <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 capitalize flex-shrink-0">{dep.relationship}</span>
                                        {total > 0 && (
                                            <>
                                                <div className="w-24 h-1.5 rounded-full bg-gray-100 overflow-hidden flex-shrink-0"><div className="h-full rounded-full bg-teal-600" style={{ width: `${(done / total) * 100}%` }} /></div>
                                                <span className="text-[11px] text-gray-500 tabular-nums w-10 text-right flex-shrink-0">{done}/{total}</span>
                                            </>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}
            </div>

            {/* SIDEBAR */}
            <div className="w-full space-y-4" style={{ flex: "0 0 340px", maxWidth: "100%" }}>
                {/* Case facts */}
                <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h2 className="text-[15px] font-bold text-gray-900 mb-3">Case facts</h2>
                    <dl className="space-y-2.5">
                        <Fact k="Visa type" v={lead.inz_visa_type || "Not set"} />
                        <Fact k="Pipeline" v={[lead.stage || lead.immigration_stage, lead.inz_status].filter(Boolean).join(" · ") || "—"} />
                        <Fact k="Licensed adviser" v={lead.immigration_assignee_name || lead.immigration_assignee || "Unassigned"} />
                        <Fact k="Case manager" v={lead.case_manager_name || lead.immigration_manager_name || "—"} />
                        <Fact k="Opened" v={fmtDate(openedIso)} />
                        <Fact k="Target lodgement" v={lead.target_lodgement_at ? fmtDate(lead.target_lodgement_at) : "—"} />
                        <Fact k="Lodged with INZ" v={lodgedAt ? fmtDate(lodgedAt) : "Not yet lodged"} />
                        {decisionAt && <Fact k="INZ decision" v={fmtDate(decisionAt)} />}
                        <Fact k="Source" v={lead.is_assessment_converted ? "Assessment conversion" : "Sales-converted"} />
                    </dl>

                    {/* INZ processing tracker — days since lodgement vs the visa's
                        expected window. Only shown once a lodgement date is set. */}
                    {lodgedAt && (
                        <div className="mt-4 pt-3 border-t border-gray-100">
                            <div className="flex items-center justify-between mb-1.5">
                                <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                    {decisionAt ? "INZ outcome time" : "INZ processing"}
                                </span>
                                {inzBand && <span className={`text-[11px] font-bold ${INZ_BAND[inzBand].text}`}>{INZ_BAND[inzBand].label}</span>}
                            </div>
                            {expectedDays ? (
                                <>
                                    <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
                                        <div className={`h-full rounded-full ${INZ_BAND[inzBand]?.bar || "bg-gray-300"}`}
                                            style={{ width: `${Math.min(100, Math.round((daysSinceLodged / expectedDays) * 100))}%` }} />
                                    </div>
                                    <p className="text-[11px] text-gray-500 mt-1.5">
                                        {decisionAt
                                            ? `Decided in ${daysSinceLodged} days · expected ~${expectedDays}`
                                            : `Day ${daysSinceLodged} of ~${expectedDays} expected · ${
                                                daysSinceLodged > expectedDays
                                                    ? `${daysSinceLodged - expectedDays} over`
                                                    : `${expectedDays - daysSinceLodged} remaining`}`}
                                    </p>
                                </>
                            ) : (
                                <p className="text-[11px] text-gray-500">
                                    {decisionAt ? `Decided in ${daysSinceLodged} days.` : `Day ${daysSinceLodged} since lodgement.`}
                                    <span className="text-gray-400"> Set an expected window on the visa type to track against INZ.</span>
                                </p>
                            )}
                        </div>
                    )}
                </section>

                {/* Stat tiles */}
                <div className="grid grid-cols-2 gap-3">
                    <Stat big={daysOpen != null ? String(daysOpen) : "—"} label="days open" />
                    <Stat big={`${reqApproved}/${reqTotal}`} label="documents approved" tone="amber" />
                    <Stat big={String(withAdviser)} label="with the adviser" tone={withAdviser > 0 ? "red" : undefined} />
                    <Stat big={money(outstanding)} label="outstanding on invoice" tone={outstanding > 0 ? "amber" : "teal"} />
                </div>

                {/* Recent activity */}
                {recent.length > 0 && (
                    <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <div className="flex items-center justify-between mb-3">
                            <h2 className="text-[14px] font-bold text-gray-900">Recent activity</h2>
                            <button type="button" onClick={() => go("notes")} className="text-[12px] font-semibold text-teal-700 hover:underline">All</button>
                        </div>
                        <ul className="space-y-3">
                            {recent.map((a, i) => (
                                <li key={a.id || i} className="flex items-start gap-2.5">
                                    <span className="mt-1.5 w-1.5 h-1.5 rounded-full bg-gray-300 flex-shrink-0" />
                                    <div className="min-w-0">
                                        <p className="text-[12px] text-gray-700 leading-snug">{a.description || a.title || "Activity"}</p>
                                        <p className="text-[11px] text-gray-400">{fmtDateTime(a.created_at)}{a.actor_name ? ` · ${a.actor_name}` : ""}</p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                </div>
            </div>
        </div>
    );
}

const NOTE_KINDS = [
    { key: "general", label: "Note", tone: "text-teal-700" },
    { key: "risk", label: "Risk", tone: "text-red-600" },
    { key: "client_contact", label: "Client contact", tone: "text-blue-600" },
];
const kindMeta = (k) =>
    NOTE_KINDS.find((x) => x.key === k) || (k === "engagement" ? { label: "Note", tone: "text-amber-600" } : { label: "Note", tone: "text-gray-500" });

// Selected-file chips shown under a composer before posting.
function PendingFiles({ files, onRemove }) {
    if (! files.length) return null;
    return (
        <div className="flex flex-wrap gap-1.5 mt-2">
            {files.map((f, i) => (
                <span key={i} className="inline-flex items-center gap-1 text-[11px] bg-gray-100 text-gray-600 rounded px-1.5 py-0.5">
                    <FileText size={11} className="text-gray-400" />
                    <span className="max-w-[130px] truncate">{f.name}</span>
                    <button type="button" onClick={() => onRemove(i)} className="text-gray-400 hover:text-gray-700"><X size={11} /></button>
                </span>
            ))}
        </div>
    );
}

// Attachments already saved on a note/reply — image thumbnails or file chips,
// opening the private-disk file in a new tab.
function NoteAttachments({ attachments = [] }) {
    if (! attachments.length) return null;
    return (
        <div className="flex flex-wrap gap-2 mt-2">
            {attachments.map((a, i) => (
                a.is_image ? (
                    <a key={i} href={a.view_url} target="_blank" rel="noreferrer" className="block">
                        <img src={a.view_url} alt={a.name} className="h-16 w-16 object-cover rounded-lg border border-gray-200" />
                    </a>
                ) : (
                    <a key={i} href={a.view_url} target="_blank" rel="noreferrer"
                        className="inline-flex items-center gap-1.5 text-[11px] bg-gray-50 border border-gray-200 rounded-lg px-2 py-1 text-gray-600 hover:bg-white">
                        <FileText size={12} className="text-gray-400" />
                        <span className="max-w-[150px] truncate">{a.name}</span>
                    </a>
                )
            ))}
        </div>
    );
}

// A reply/compose box with an attach control. Posts to the notes endpoint;
// `parentId` set makes it a threaded reply.
function NoteReplyBox({ leadId, parentId = null, placeholder, onDone, onCancel }) {
    const [body, setBody] = useState("");
    const [files, setFiles] = useState([]);
    const [posting, setPosting] = useState(false);
    const fileRef = useRef(null);

    const submit = () => {
        if (! body.trim() && ! files.length) return;
        setPosting(true);
        router.post(`${leadBase()}/${leadId}/notes`, { body: body || "(attachment)", parent_id: parentId, files }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { setBody(""); setFiles([]); onDone && onDone(); },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not reply"),
            onFinish: () => setPosting(false),
        });
    };

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-2.5">
            <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={2} maxLength={2000}
                placeholder={placeholder} className="w-full text-[13px] outline-none resize-none placeholder-gray-400" />
            <PendingFiles files={files} onRemove={(i) => setFiles((fs) => fs.filter((_, x) => x !== i))} />
            <div className="flex items-center justify-between mt-1.5 pt-1.5 border-t border-gray-50">
                <button type="button" onClick={() => fileRef.current?.click()} title="Attach a file"
                    className="inline-flex items-center justify-center w-7 h-7 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                    <Plus size={15} />
                </button>
                <input ref={fileRef} type="file" multiple accept="application/pdf,image/*" className="hidden"
                    onChange={(e) => { setFiles((fs) => [...fs, ...Array.from(e.target.files || [])]); e.target.value = ""; }} />
                <div className="flex items-center gap-3">
                    {onCancel && <button type="button" onClick={onCancel} className="text-[12px] text-gray-500 hover:text-gray-800">Cancel</button>}
                    <button type="button" onClick={submit} disabled={posting || (! body.trim() && ! files.length)}
                        className="px-3 py-1 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-gray-800 disabled:opacity-40">
                        {parentId ? "Reply" : "Post"}
                    </button>
                </div>
            </div>
        </div>
    );
}

// The case team's private notebook — categorised notes (Note / Risk / Client
// contact), pinnable, threaded (staff can reply), with media attachments.
// Never shown to the client.
function InternalNotes({ leadId, notes = [], onSeeAll }) {
    const [kind, setKind] = useState("general");
    const [body, setBody] = useState("");
    const [pinned, setPinned] = useState(false);
    const [files, setFiles] = useState([]);
    const [posting, setPosting] = useState(false);
    const fileRef = useRef(null);

    const submit = () => {
        if (! body.trim() && ! files.length) return;
        setPosting(true);
        router.post(`${leadBase()}/${leadId}/notes`, { body: body || "(attachment)", pinned, kind, files }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { setBody(""); setPinned(false); setKind("general"); setFiles([]); },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not add note"),
            onFinish: () => setPosting(false),
        });
    };

    const pinnedNotes = notes.filter((n) => n.pinned);
    const rest = notes.filter((n) => ! n.pinned);

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div className="flex items-center justify-between gap-2 mb-3">
                <h2 className="text-[14px] font-bold text-gray-900">Internal notes <span className="text-[10px] font-bold uppercase tracking-wider text-gray-400 ml-1">Team only</span></h2>
                <button type="button" onClick={onSeeAll} className="text-[12px] font-semibold text-teal-700 hover:underline flex-shrink-0">All {notes.length} →</button>
            </div>

            {/* Composer */}
            <div className="rounded-xl border border-gray-200 p-2.5 bg-gray-50/40">
                <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={2} maxLength={2000}
                    placeholder="Write something for the team…"
                    className="w-full text-[13px] bg-transparent outline-none resize-none placeholder-gray-400" />
                <PendingFiles files={files} onRemove={(i) => setFiles((fs) => fs.filter((_, x) => x !== i))} />
                <div className="flex items-center justify-between mt-1.5 pt-1.5 border-t border-gray-100 gap-2 flex-wrap">
                    <div className="flex items-center gap-2.5">
                        {NOTE_KINDS.map((k) => (
                            <button key={k.key} type="button" onClick={() => setKind(k.key)}
                                className={`text-[12px] font-semibold px-2 py-0.5 rounded ${kind === k.key ? "bg-gray-900 text-white" : "text-gray-500 hover:text-gray-800"}`}>
                                {k.label}
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={() => fileRef.current?.click()} title="Attach a file"
                            className="inline-flex items-center justify-center w-7 h-7 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-700">
                            <Plus size={15} />
                        </button>
                        <input ref={fileRef} type="file" multiple accept="application/pdf,image/*" className="hidden"
                            onChange={(e) => { setFiles((fs) => [...fs, ...Array.from(e.target.files || [])]); e.target.value = ""; }} />
                        <button type="button" onClick={() => setPinned((p) => ! p)} title="Pin to top"
                            className={`inline-flex items-center justify-center w-7 h-7 rounded-full ${pinned ? "text-amber-600 bg-amber-50" : "text-gray-400 hover:bg-gray-200 hover:text-gray-700"}`}>
                            <Pin size={13} />
                        </button>
                        <button type="button" onClick={submit} disabled={posting || (! body.trim() && ! files.length)}
                            className="px-3.5 py-1.5 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-gray-800 disabled:opacity-40">Post</button>
                    </div>
                </div>
            </div>

            {/* Notes */}
            {notes.length === 0 ? (
                <p className="text-[12px] text-gray-400 mt-3">No notes yet.</p>
            ) : (
                <div className="mt-4 space-y-4">
                    {pinnedNotes.length > 0 && (
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-wider text-amber-600 mb-2">Pinned</p>
                            <div className="space-y-3">{pinnedNotes.map((n) => <NoteItem key={n.id} n={n} leadId={leadId} />)}</div>
                        </div>
                    )}
                    <div className="space-y-3">{rest.map((n) => <NoteItem key={n.id} n={n} leadId={leadId} />)}</div>
                </div>
            )}
        </section>
    );
}

// Task Board tasks tied to this case — a read view on the Overview so board
// work is visible from the case. "Open board" jumps to the department board.
function CaseTasks({ tasks = { items: [] }, leadId, caseStaff = [] }) {
    const items = tasks.items || [];
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const openBoard = () => {
        const p = typeof window !== "undefined" ? window.location.pathname : "";
        const base = p.startsWith("/portal/immigration-adviser") ? "/portal/immigration-adviser" : "/portal/immigration";
        router.visit(`${base}/tasks`);
    };
    const toggleComplete = (t) => {
        setBusyId(t.id);
        router.patch(`/api/tasks/${t.id}`, { status: t.completed ? "not_started" : "completed" }, {
            preserveScroll: true,
            onSuccess: () => {},   // flash message handles the toast
            onError: (e) => toast.error(Object.values(e)[0] || "Could not update task"),
            onFinish: () => setBusyId(null),
        });
    };
    const dotTone = (t) => t.completed ? "bg-teal-500"
        : t.priority === "urgent" ? "bg-red-500"
            : t.priority === "high" ? "bg-orange-500"
                : t.priority === "medium" ? "bg-amber-500"
                    : "bg-blue-500";
    const statusPill = (s) => {
        const label = (s || "").replace(/_/g, " ") || "Not started";
        const tone = /progress|doing/i.test(s) ? "bg-orange-50 text-orange-700"
            : /review/i.test(s) ? "bg-pink-50 text-pink-700"
                : /done|complete/i.test(s) ? "bg-teal-50 text-teal-700"
                    : "bg-blue-50 text-blue-700";
        return <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full capitalize ${tone}`}>{label}</span>;
    };
    const dueLabel = (t) => {
        if (! t.due_at) return null;
        const days = Math.ceil((new Date(t.due_at) - Date.now()) / 86400000);
        if (days < 0) return <span className="text-[10.5px] font-semibold text-red-600">{Math.abs(days)}d overdue</span>;
        if (days === 0) return <span className="text-[10.5px] font-semibold text-amber-600">Due today</span>;
        return <span className="text-[10.5px] text-gray-400">In {days} day{days === 1 ? "" : "s"}</span>;
    };

    return (
        <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div className="flex items-center justify-between gap-3 mb-3">
                <h2 className="text-[14px] font-bold text-gray-900">Tasks on this case <span className="text-[11px] font-normal text-gray-400">{tasks.open ?? items.filter((t) => ! t.completed).length} open · from the task board</span></h2>
                <button type="button" onClick={openBoard} className="text-[12px] font-semibold text-teal-700 hover:underline flex-shrink-0">Open board</button>
            </div>
            {items.length === 0 ? (
                <p className="text-[12px] text-gray-400">No tasks linked to this case yet.</p>
            ) : (
                <div className="space-y-3">
                    {items.map((t) => (
                        editingId === t.id ? (
                            <EditTask key={t.id} task={t} caseStaff={caseStaff} onClose={() => setEditingId(null)} />
                        ) : (
                        <div key={t.id} className="group flex items-start gap-2.5">
                            <button type="button" onClick={() => toggleComplete(t)} disabled={busyId === t.id}
                                title={t.completed ? "Reopen task" : "Mark complete"} className="flex-shrink-0 mt-0.5 disabled:opacity-50">
                                {t.completed
                                    ? <CheckSquare size={16} className="text-teal-600" />
                                    : <Square size={16} className="text-gray-300 hover:text-gray-500" />}
                            </button>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-start justify-between gap-2">
                                    <p className={`text-[12.5px] font-semibold leading-snug ${t.completed ? "text-gray-400 line-through" : "text-gray-800"}`}>{t.title}</p>
                                    <div className="flex items-center gap-2 flex-shrink-0">
                                        {statusPill(t.status)}
                                        {dueLabel(t)}
                                        <button type="button" onClick={() => setEditingId(t.id)} title="Edit task"
                                            className="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-gray-700 transition-opacity"><Pencil size={13} /></button>
                                    </div>
                                </div>
                                {t.description && <p className="text-[11.5px] text-gray-500 leading-snug mt-0.5 whitespace-pre-wrap">{t.description}</p>}
                                <div className="flex items-center gap-1.5 mt-1">
                                    {/* Assignee — avatar + name, so it's clear who owns the task. */}
                                    {t.assignee ? (
                                        <span className="inline-flex items-center gap-1 bg-gray-100 rounded-full pl-0.5 pr-2 py-0.5">
                                            <span className="w-4 h-4 rounded-full bg-teal-600 text-white text-[8px] font-bold flex items-center justify-center">
                                                {(t.assignee.name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((s) => s[0].toUpperCase()).join("")}
                                            </span>
                                            <span className="text-[10.5px] font-medium text-gray-600">{t.assignee.name}</span>
                                        </span>
                                    ) : (
                                        <span className="text-[10.5px] text-gray-400">Unassigned</span>
                                    )}
                                    {t.created_at && <span className="text-[10.5px] text-gray-400">· added {fmtShort(t.created_at)}</span>}
                                </div>
                            </div>
                        </div>
                        )
                    ))}
                </div>
            )}
            {adding ? (
                <QuickAddTask leadId={leadId} caseStaff={caseStaff} onClose={() => setAdding(false)} />
            ) : (
                <button type="button" onClick={() => setAdding(true)}
                    className="mt-4 inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-700 border border-gray-200 rounded-lg px-3 py-1.5 hover:bg-gray-50">
                    <Plus size={13} /> Add a task
                </button>
            )}
        </section>
    );
}

// Inline quick-add for a case task — creates a Task Board task linked to this
// case (title + due date + priority), then refreshes the Overview's tasks.
function QuickAddTask({ leadId, caseStaff = [], onClose }) {
    const today = new Date().toISOString().slice(0, 10);
    const [title, setTitle] = useState("");
    const [dueAt, setDueAt] = useState(today);
    const [priority, setPriority] = useState("normal");
    const [assigneeId, setAssigneeId] = useState("");
    const [saving, setSaving] = useState(false);

    const submit = () => {
        if (! title.trim()) return toast.error("Give the task a title");
        setSaving(true);
        router.post("/api/tasks", {
            task_type: "linked",
            title: title.trim(),
            due_at: dueAt,
            priority,
            assignee_id: assigneeId || null,
            lead_id: leadId,
            department: "immigration",
        }, {
            preserveScroll: true,
            onSuccess: () => { onClose(); },   // flash message handles the toast
            onError: (e) => toast.error(Object.values(e)[0] || "Could not add task"),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="mt-4 rounded-xl border border-gray-200 p-2.5 space-y-2">
            <input value={title} onChange={(e) => setTitle(e.target.value)} autoFocus
                placeholder="Task title (e.g. Chase police certificate)"
                className="w-full text-[13px] outline-none placeholder-gray-400"
                onKeyDown={(e) => { if (e.key === "Enter") submit(); }} />
            <div className="flex flex-wrap items-center gap-2 pt-1.5 border-t border-gray-50">
                <input type="date" value={dueAt} min={today} onChange={(e) => setDueAt(e.target.value)}
                    className="text-[12px] border border-gray-200 rounded-lg px-2 py-1 text-gray-700" />
                <select value={priority} onChange={(e) => setPriority(e.target.value)}
                    className="text-[12px] border border-gray-200 rounded-lg px-2 py-1 text-gray-700 capitalize">
                    {["urgent", "high", "normal", "low"].map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <select value={assigneeId} onChange={(e) => setAssigneeId(e.target.value)}
                    className="text-[12px] border border-gray-200 rounded-lg px-2 py-1 text-gray-700 max-w-[130px]" title="Assign to">
                    <option value="">Assign to…</option>
                    {caseStaff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
                <div className="flex items-center gap-3 ml-auto">
                    <button type="button" onClick={onClose} className="text-[12px] text-gray-500 hover:text-gray-800">Cancel</button>
                    <button type="button" onClick={submit} disabled={saving || ! title.trim()}
                        className="px-3 py-1 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-gray-800 disabled:opacity-40">Add</button>
                </div>
            </div>
        </div>
    );
}

// Inline edit for an existing case task — updates title / due date / priority /
// assignee / status via the task API, then refreshes the Overview's tasks.
function EditTask({ task, caseStaff = [], onClose }) {
    const [title, setTitle] = useState(task.title || "");
    const [description, setDescription] = useState(task.description || "");
    const [dueAt, setDueAt] = useState(task.due_at ? String(task.due_at).slice(0, 10) : "");
    const [priority, setPriority] = useState(task.priority || "normal");
    const [status, setStatus] = useState(task.status || "not_started");
    const [assigneeId, setAssigneeId] = useState(task.assignee?.id ? String(task.assignee.id) : "");
    const [saving, setSaving] = useState(false);

    const submit = () => {
        if (! title.trim()) return toast.error("Give the task a title");
        setSaving(true);
        router.patch(`/api/tasks/${task.id}`, {
            title: title.trim(),
            description: description || null,
            due_at: dueAt || null,
            priority,
            status,
            assignee_id: assigneeId || null,
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
            onError: (e) => toast.error(Object.values(e)[0] || "Could not save task"),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="rounded-xl border border-gray-300 p-2.5 space-y-2 bg-gray-50/60">
            <input value={title} onChange={(e) => setTitle(e.target.value)} autoFocus
                placeholder="Task title" className="w-full text-[13px] bg-transparent outline-none placeholder-gray-400 font-semibold"
                onKeyDown={(e) => { if (e.key === "Enter") submit(); }} />
            <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2}
                placeholder="Description (optional)" className="w-full text-[12px] bg-transparent outline-none placeholder-gray-400 resize-none" />
            <div className="flex flex-wrap items-center gap-2 pt-1.5 border-t border-gray-100">
                <input type="date" value={dueAt} onChange={(e) => setDueAt(e.target.value)}
                    className="text-[12px] border border-gray-200 rounded-lg px-2 py-1 text-gray-700" />
                <select value={priority} onChange={(e) => setPriority(e.target.value)}
                    className="text-[12px] border border-gray-200 rounded-lg px-2 py-1 text-gray-700 capitalize">
                    {["urgent", "high", "normal", "low"].map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
                <select value={status} onChange={(e) => setStatus(e.target.value)}
                    className="text-[12px] border border-gray-200 rounded-lg px-2 py-1 text-gray-700">
                    {[["not_started", "Not started"], ["in_progress", "In progress"], ["in_review", "In review"], ["completed", "Completed"]].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <select value={assigneeId} onChange={(e) => setAssigneeId(e.target.value)}
                    className="text-[12px] border border-gray-200 rounded-lg px-2 py-1 text-gray-700 max-w-[130px]" title="Assign to">
                    <option value="">Unassigned</option>
                    {caseStaff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
                <div className="flex items-center gap-3 ml-auto">
                    <button type="button" onClick={onClose} className="text-[12px] text-gray-500 hover:text-gray-800">Cancel</button>
                    <button type="button" onClick={submit} disabled={saving || ! title.trim()}
                        className="px-3 py-1 rounded-lg bg-gray-900 text-white text-[12px] font-semibold hover:bg-gray-800 disabled:opacity-40">Save</button>
                </div>
            </div>
        </div>
    );
}

function NoteItem({ n, leadId }) {
    const meta = kindMeta(n.kind);
    const replies = n.replies || [];
    const [replyOpen, setReplyOpen] = useState(false);
    const initials = (n.author || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((s) => s[0].toUpperCase()).join("") || "?";

    return (
        <div className={`rounded-xl border p-3 ${n.kind === "risk" ? "border-red-100 bg-red-50/40" : n.pinned ? "border-teal-100 bg-teal-50/40" : "border-gray-100 bg-white"}`}>
            <div className="flex items-start gap-2.5">
                <span className="w-7 h-7 rounded-full bg-teal-600 text-white text-[10px] font-bold flex items-center justify-center flex-shrink-0 mt-0.5">{initials}</span>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-[12.5px] font-bold text-gray-900">{n.author || "Unknown"}</span>
                        <span className={`text-[9.5px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded ${meta.tone} bg-gray-50`}>{meta.label}</span>
                        {n.pinned && <span className="text-[9.5px] font-bold uppercase tracking-wider text-teal-700">Pinned</span>}
                        <span className="text-[11px] text-gray-400 ml-auto">{fmtShort(n.created_at)}</span>
                    </div>
                    <p className="text-[12.5px] text-gray-700 leading-relaxed mt-1 whitespace-pre-wrap">{n.body}</p>
                    <NoteAttachments attachments={n.attachments} />

                    {/* Replies */}
                    {replies.length > 0 && (
                        <div className="mt-3 space-y-2.5 pl-3 border-l-2 border-gray-100">
                            {replies.map((r) => {
                                const ri = (r.author || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((s) => s[0].toUpperCase()).join("") || "?";
                                return (
                                    <div key={r.id} className="flex items-start gap-2">
                                        <span className="w-6 h-6 rounded-full bg-gray-200 text-gray-600 text-[9px] font-bold flex items-center justify-center flex-shrink-0 mt-0.5">{ri}</span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-[12px] font-bold text-gray-800">{r.author || "Unknown"}</span>
                                                <span className="text-[10.5px] text-gray-400">{fmtDateTime(r.created_at)}</span>
                                            </div>
                                            <p className="text-[12px] text-gray-600 leading-relaxed whitespace-pre-wrap">{r.body}</p>
                                            <NoteAttachments attachments={r.attachments} />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    <div className="mt-2">
                        {replyOpen ? (
                            <NoteReplyBox leadId={leadId} parentId={n.id} placeholder="Reply to the team…"
                                onDone={() => setReplyOpen(false)} onCancel={() => setReplyOpen(false)} />
                        ) : (
                            <button type="button" onClick={() => setReplyOpen(true)} className="text-[12px] font-semibold text-teal-700 hover:text-teal-900">Reply</button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function ProgressBar({ segments, total }) {
    return (
        <div className="h-2 rounded-full bg-gray-100 overflow-hidden flex">
            {segments.filter((s) => s.v > 0).map((s, i) => (
                <div key={i} className={s.cls} style={{ width: `${(s.v / total) * 100}%` }} />
            ))}
        </div>
    );
}

function Legend({ cls, label }) {
    return <span className="inline-flex items-center gap-1.5"><span className={`w-2 h-2 rounded-sm ${cls}`} /> {label}</span>;
}

function CategoryRow({ label, done, total }) {
    const pct = total > 0 ? Math.min(100, (done / total) * 100) : 0;
    return (
        <div className="flex items-center gap-4">
            <span className="text-[13px] text-gray-700 w-40 flex-shrink-0">{label}</span>
            <div className="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden"><div className="h-full rounded-full bg-teal-600" style={{ width: `${pct}%` }} /></div>
            <span className="text-[12px] text-gray-500 tabular-nums w-12 text-right flex-shrink-0">{done}/{total}</span>
        </div>
    );
}

function Fact({ k, v }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <dt className="text-[10px] font-bold uppercase tracking-wider text-gray-400 flex-shrink-0 pt-0.5">{k}</dt>
            <dd className="text-[12.5px] font-semibold text-gray-900 text-right min-w-0">{v}</dd>
        </div>
    );
}

function Stat({ big, label, tone }) {
    const color = tone === "amber" ? "text-amber-600" : tone === "red" ? "text-red-600" : tone === "teal" ? "text-teal-600" : "text-gray-900";
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <p className={`text-2xl font-bold tabular-nums ${color}`}>{big}</p>
            <p className="text-[11px] text-gray-500 mt-0.5">{label}</p>
        </div>
    );
}

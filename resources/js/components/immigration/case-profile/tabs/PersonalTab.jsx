import { useState, useEffect, useRef } from "react";
import { useForm } from "@inertiajs/react";
import { toast } from "sonner";
import { Save, ClipboardList, Eye, Download, FileText, Sparkles, Loader2, CheckCircle2, XCircle, AlertTriangle, RefreshCw } from "lucide-react";

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || "");
import { ASSESSMENT_SECTIONS, formatAssessmentValue } from "@/data/assessmentSections";
import SendAssessmentButton from "../SendAssessmentButton";
import PhoneField from "@/components/PhoneField";
import { immBase } from "@/lib/caseRoutes";

// Case Profile "Personal" tab — the applicant's details in INZ-form-shaped
// sections (left) with the document-verification / lodgement sidebar (right),
// then the read-only visa-assessment submission below.
// Posts to /portal/immigration/cases/{id}/personal (CaseProfileController).

const IC = "w-full px-3 py-2 rounded-lg border border-gray-200 text-[13px] bg-white focus:border-gray-400 outline-none transition-colors";
const ICLocked = "w-full px-3 py-2 rounded-lg border border-gray-200 text-[13px] bg-gray-50 text-gray-600 outline-none";

const GENDERS = ["", "Male", "Female", "Other", "Prefer not to say"];
const MARITAL = ["", "Single", "Married", "De facto", "Divorced", "Widowed", "Separated"];

const VISA_LABEL = {
    resident: "Resident Visa (SMC)", work: "Work Visa (AEWV)",
    student: "Student Visa", visitor: "Visitor Visa", family: "Family Visa (Partner / Child)",
};

export default function PersonalTab({ lead = {}, intake = null, assessmentCompleteness = null, activity = [] }) {
    const form = useForm({
        first_name:        lead.first_name || "",
        middle_name:       lead.middle_name || "",
        last_name:         lead.last_name || "",
        suffix:            lead.suffix || "",
        gender:            lead.gender || "",
        marital_status:    lead.marital_status || "",
        dob:               lead.dob || "",
        email:             lead.email || "",
        phone:             lead.phone || "",
        citizenship:       lead.citizenship || "",
        residence_country: lead.residence_country || "",
        passport_number:   lead.passport_number || "",
        passport_expiry:   lead.passport_expiry || "",
        inz_client_number:      lead.inz_client_number || "",
        inz_application_number: lead.inz_application_number || "",
        inz_medical_ref:        lead.inz_medical_ref || "",
        nzer_number:            lead.nzer_number || "",
        ird_number:             lead.ird_number || "",
        inz_lodged_at:          lead.inz_lodged_at || "",
        target_lodgement_at:    lead.target_lodgement_at || "",
    });
    const { data, setData, post, processing, errors, isDirty } = form;

    // "saved just now" indicator (auto-save + manual save both set it).
    const [savedAt, setSavedAt] = useState(0);

    const save = (opts = {}) => {
        post(`${immBase()}/cases/${lead.id}/personal`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { form.defaults(); setSavedAt(Date.now()); },
            onError: () => { if (opts.loud) toast.error("Please fix the highlighted fields."); },
        });
    };

    const submit = (e) => {
        e.preventDefault();
        save({ loud: true });
    };

    // Auto-save: debounce edits and persist ~800ms after typing stops. `defaults()`
    // on success resets the dirty baseline so it doesn't re-fire in a loop.
    const firstRun = useRef(true);
    useEffect(() => {
        if (firstRun.current) { firstRun.current = false; return; }
        if (! isDirty || processing) return;
        const t = setTimeout(() => save(), 800);
        return () => clearTimeout(t);
    }, [data, isDirty]); // eslint-disable-line react-hooks/exhaustive-deps

    // Completeness across the tracked personal fields (real).
    const tracked = ["first_name", "last_name", "dob", "gender", "marital_status", "email", "phone", "citizenship", "residence_country", "passport_number", "passport_expiry"];
    const filled = tracked.filter((k) => String(data[k] || "").trim() !== "").length;
    const total = tracked.length;

    // Passport expiry proximity — a real flag when inside the 3-month buffer.
    const expiryDays = data.passport_expiry ? Math.ceil((new Date(data.passport_expiry).getTime() - Date.now()) / 86400000) : null;
    const expiryFlagged = expiryDays != null && expiryDays >= 0 && expiryDays <= 90;

    // Fields still needed before lodgement (empty of the important set).
    const missing = [];
    if (!data.marital_status) missing.push("Marital status");
    if (!data.citizenship) missing.push("Nationality");
    if (!data.passport_number) missing.push("Passport number");
    if (!data.passport_expiry) missing.push("Passport expiry");

    const changes = (activity || []).filter((a) => (a.description || "").length).slice(0, 4);

    // Real "Confirmed by document" — AI reads the passport and compares to the
    // case record. On demand (costs an AI call); results drive the field badges.
    const [scan, setScan] = useState(null); // { loading, ok, rows, error }
    const runScan = () => {
        setScan({ loading: true });
        fetch(`${immBase()}/cases/${lead.id}/identity-scan`, {
            method: "POST",
            headers: { "X-XSRF-TOKEN": xsrf(), Accept: "application/json", "Content-Type": "application/json" },
            body: "{}",
        })
            .then((r) => r.json())
            .then((d) => setScan({ loading: false, ...d }))
            .catch(() => setScan({ loading: false, ok: false, error: "The scan could not be completed." }));
    };
    const byLabel = {};
    if (scan?.ok) (scan.rows || []).forEach((r) => { byLabel[r.label] = r; });
    const verdictBadge = (label) => {
        const v = byLabel[label]?.verdict;
        if (v === "match") return { text: "Verified", tone: "ok" };
        if (v === "conflict") return { text: "Conflict", tone: "req" };
        if (v === "review") return { text: "Review", tone: "flag" };
        return null;
    };

    return (
      <div className="space-y-8">
        <form onSubmit={submit} className="space-y-5">
            {/* Header */}
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h3 className="text-lg font-bold text-gray-900">Personal details</h3>
                    <p className="text-[12.5px] text-gray-500 mt-0.5">Fields feed the INZ forms. Values confirmed against a verified document are locked.</p>
                </div>
                <div className="flex items-center gap-3 flex-shrink-0">
                    <div className="text-right">
                        <p className="text-[11px] font-semibold text-gray-600 tabular-nums">{filled} of {total} complete</p>
                        <div className="w-40 h-1.5 rounded-full bg-gray-100 overflow-hidden mt-1"><div className="h-full bg-teal-600 rounded-full" style={{ width: `${(filled / total) * 100}%` }} /></div>
                    </div>
                    {/* Auto-save status — changes persist automatically; the button is a manual fallback. */}
                    <span className="text-[12px] font-medium inline-flex items-center gap-1 min-w-[92px] justify-end">
                        {processing ? (
                            <span className="text-gray-400 inline-flex items-center gap-1"><Loader2 size={12} className="animate-spin" /> Saving…</span>
                        ) : isDirty ? (
                            <span className="text-amber-600">Unsaved…</span>
                        ) : savedAt ? (
                            <span className="text-emerald-600 inline-flex items-center gap-1"><CheckCircle2 size={12} /> Saved</span>
                        ) : (
                            <span className="text-gray-300">Auto-saves</span>
                        )}
                    </span>
                    <button type="submit" disabled={processing || ! isDirty} title="Changes save automatically — this saves now" className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[13px] font-bold hover:bg-gray-800 transition-colors disabled:opacity-40">
                        <Save size={13} /> Save now
                    </button>
                </div>
            </div>

            {Object.keys(errors).length > 0 && (
                <div className="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-[12px]">
                    <ul className="list-disc pl-4 space-y-0.5">{Object.values(errors).map((m, i) => <li key={i}>{m}</li>)}</ul>
                </div>
            )}

            <div className="flex flex-col lg:flex-row gap-5 items-start">
                {/* LEFT — form sections */}
                <div className="space-y-4 w-full min-w-0" style={{ flex: "1 1 0%" }}>
                    <Section title="Identity" note="Feeds INZ 1012 · section A">
                        <F label="First name" required badge={verdictBadge("Full name") || (!data.first_name ? { text: "Required", tone: "req" } : null)}>
                            <input type="text" required value={data.first_name} onChange={(e) => setData("first_name", e.target.value)} className={IC} maxLength={120} />
                        </F>
                        <F label="Middle name">
                            <input type="text" value={data.middle_name} onChange={(e) => setData("middle_name", e.target.value)} className={IC} maxLength={120} />
                        </F>
                        <F label="Last name">
                            <input type="text" value={data.last_name} onChange={(e) => setData("last_name", e.target.value)} className={IC} maxLength={120} />
                        </F>
                        <F label="Date of birth" badge={verdictBadge("Date of birth")}>
                            <input type="date" value={data.dob || ""} onChange={(e) => setData("dob", e.target.value)} className={IC} />
                        </F>
                        <F label="Gender">
                            <select value={data.gender} onChange={(e) => setData("gender", e.target.value)} className={IC}>{GENDERS.map((g) => <option key={g} value={g}>{g || "—"}</option>)}</select>
                        </F>
                        <F label="Marital status" badge={!data.marital_status ? { text: "Required", tone: "req" } : null}>
                            <select value={data.marital_status} onChange={(e) => setData("marital_status", e.target.value)} className={IC}>{MARITAL.map((m) => <option key={m} value={m}>{m || "—"}</option>)}</select>
                        </F>
                    </Section>

                    <Section title="Nationality & passport" note="Compared against the verified passport">
                        <F label="Nationality" badge={!data.citizenship ? { text: "Required", tone: "req" } : null}>
                            <input type="text" value={data.citizenship} onChange={(e) => setData("citizenship", e.target.value)} className={IC} maxLength={120} placeholder="Nationality" />
                        </F>
                        <F label="Passport number" badge={verdictBadge("Passport number") || (!data.passport_number ? { text: "Required", tone: "req" } : null)}>
                            <input type="text" value={data.passport_number} onChange={(e) => setData("passport_number", e.target.value)} className={IC} maxLength={60} placeholder="Not supplied" />
                        </F>
                        <F label="Passport expiry" badge={verdictBadge("Passport expiry") || (expiryFlagged ? { text: `Flagged · ${expiryDays} days`, tone: "flag" } : null)}>
                            <input type="date" value={data.passport_expiry || ""} onChange={(e) => setData("passport_expiry", e.target.value)} className={IC} />
                        </F>
                        <F label="Country of residence">
                            <input type="text" value={data.residence_country} onChange={(e) => setData("residence_country", e.target.value)} className={IC} maxLength={120} />
                        </F>
                        <F label="INZ application number" badge={!data.inz_application_number ? { text: "Required", tone: "req" } : null}>
                            <input type="text" value={data.inz_application_number} onChange={(e) => setData("inz_application_number", e.target.value)} className={IC} maxLength={60} placeholder="Not supplied" />
                        </F>
                        <F label="INZ client number" badge={!data.inz_client_number ? { text: "Required", tone: "req" } : null}>
                            <input type="text" value={data.inz_client_number} onChange={(e) => setData("inz_client_number", e.target.value)} className={IC} maxLength={60} placeholder="Not supplied" />
                        </F>
                        <F label="Medical reference number">
                            <input type="text" value={data.inz_medical_ref} onChange={(e) => setData("inz_medical_ref", e.target.value)} className={IC} maxLength={60} placeholder="Not supplied" />
                        </F>
                        <F label="NZER number">
                            <input type="text" value={data.nzer_number} onChange={(e) => setData("nzer_number", e.target.value)} className={IC} maxLength={60} placeholder="Not supplied" />
                        </F>
                        <F label="IRD number">
                            <input type="text" value={data.ird_number} onChange={(e) => setData("ird_number", e.target.value)} className={IC} maxLength={60} placeholder="Not supplied" />
                        </F>
                        <F label="INZ lodgement date" badge={data.inz_lodged_at ? { text: "Lodged", tone: "ok" } : null}>
                            <input type="date" value={data.inz_lodged_at || ""} onChange={(e) => setData("inz_lodged_at", e.target.value)} className={IC} />
                        </F>
                        <F label="Target lodgement date">
                            <input type="date" value={data.target_lodgement_at || ""} onChange={(e) => setData("target_lodgement_at", e.target.value)} className={IC} />
                        </F>
                        <F label="Suffix">
                            <input type="text" value={data.suffix} onChange={(e) => setData("suffix", e.target.value)} className={IC} maxLength={20} placeholder="Jr., Sr., III…" />
                        </F>
                    </Section>

                    <Section title="Contact & address" note="Used for correspondence">
                        <F label="Email" required badge={!data.email ? { text: "Required", tone: "req" } : null}>
                            <input type="email" required value={data.email} onChange={(e) => setData("email", e.target.value)} className={IC} maxLength={255} />
                        </F>
                        <F label="Mobile" badge={data.phone ? { text: "Preferred", tone: "ok" } : null}>
                            {/* Same international field the visa assessment uses — the
                                country code is parsed from / stored back into the single
                                `phone` value as "+<dial> <number>". */}
                            <div className="px-3 rounded-lg border border-gray-200 bg-white [&_.border-b]:border-b-0">
                                <PhoneField value={data.phone} onChange={(v) => setData("phone", v)} placeholder="Mobile number" />
                            </div>
                        </F>
                        <F label="Citizenship">
                            <input type="text" value={data.citizenship} onChange={(e) => setData("citizenship", e.target.value)} className={IC} maxLength={120} />
                        </F>
                    </Section>
                </div>

                {/* RIGHT — verification / lodgement sidebar */}
                <div className="w-full space-y-4" style={{ flex: "0 0 320px", maxWidth: "100%" }}>
                    {/* Confirmed by document — real AI comparison of the passport
                        to the case record. */}
                    <section className="rounded-2xl border border-gray-100 bg-teal-50/30 shadow-sm p-4">
                        <div className="flex items-center justify-between gap-2">
                            <h4 className="text-[14px] font-bold text-gray-900">Confirmed by document</h4>
                            {scan?.ok && (
                                <button type="button" onClick={runScan} className="text-[11px] font-semibold text-gray-400 hover:text-gray-700 inline-flex items-center gap-1"><RefreshCw size={11} /> Re-scan</button>
                            )}
                        </div>
                        <p className="text-[11.5px] text-gray-500 mt-0.5 mb-3">AI compares the passport to the case file. Indicative only — the adviser confirms.</p>

                        {!scan ? (
                            <button type="button" onClick={runScan} className="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-lg bg-slate-800 text-white text-[13px] font-bold hover:bg-slate-700">
                                <Sparkles size={14} /> Scan passport with AI
                            </button>
                        ) : scan.loading ? (
                            <p className="flex items-center justify-center gap-2 py-3 text-[13px] text-gray-500"><Loader2 size={15} className="animate-spin" /> Reading the document…</p>
                        ) : !scan.ok ? (
                            <div>
                                <p className="text-[12px] text-amber-700 bg-amber-50 rounded-lg px-3 py-2.5">{scan.error || "The scan could not be completed."}</p>
                                <button type="button" onClick={runScan} className="mt-2 inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-600 hover:text-gray-900"><RefreshCw size={12} /> Try again</button>
                            </div>
                        ) : (
                            <ul className="space-y-2.5">
                                {(scan.rows || []).map((r) => (
                                    <Verify key={r.label}
                                        state={r.verdict === "match" ? "ok" : r.verdict === "conflict" ? "bad" : "warn"}
                                        label={r.label}
                                        sub={r.note || (r.value ? `Document reads ${r.value}` : "Not found")} />
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* Missing before lodgement */}
                    {missing.length > 0 && (
                        <section className="rounded-2xl border border-amber-100 bg-amber-50/40 shadow-sm p-4">
                            <h4 className="text-[14px] font-bold text-gray-900 mb-2.5">Missing before lodgement</h4>
                            <ul className="space-y-1.5">
                                {missing.map((m) => (
                                    <li key={m} className="flex items-center justify-between gap-2 text-[12.5px]">
                                        <span className="inline-flex items-center gap-2 text-gray-700"><span className="w-1.5 h-1.5 rounded-full bg-amber-500" /> {m}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {/* Change history */}
                    {changes.length > 0 && (
                        <section className="rounded-2xl border border-gray-100 bg-white shadow-sm p-4">
                            <h4 className="text-[14px] font-bold text-gray-900 mb-2.5">Change history</h4>
                            <ul className="space-y-2">
                                {changes.map((c, i) => (
                                    <li key={c.id || i} className="text-[12px] text-gray-600 leading-snug">
                                        {c.description}
                                        {(c.actor_name || c.created_at) && <span className="block text-[11px] text-gray-400">{c.actor_name || ""}{c.created_at ? ` · ${new Date(c.created_at).toLocaleDateString("en-NZ", { day: "numeric", month: "short" })}` : ""}</span>}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </div>
        </form>

        {intake?.data ? (
            <AssessmentSubmission intake={intake} leadId={lead.id} completeness={assessmentCompleteness} />
        ) : (
            <div className="max-w-4xl text-center py-10 border border-dashed border-gray-200 rounded-xl bg-gray-50/50">
                <ClipboardList size={26} className="mx-auto text-gray-300" />
                <p className="mt-2 text-sm font-semibold text-gray-700">No visa assessment on file</p>
                <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">Request an assessment linked to this case. Clients without portal access need administrator approval first.</p>
                <div className="mt-4"><SendAssessmentButton leadId={lead.id} /></div>
            </div>
        )}
      </div>
    );
}

function Section({ title, note, children }) {
    return (
        <section className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
            <div className="flex items-center justify-between gap-3 mb-3">
                <h4 className="text-[10px] font-bold uppercase tracking-[0.15em] text-gray-400">{title}</h4>
                {note && <span className="text-[11px] text-gray-400">{note}</span>}
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">{children}</div>
        </section>
    );
}

const BADGE = {
    req: "bg-red-50 text-red-600",
    flag: "bg-amber-50 text-amber-700",
    ok: "bg-emerald-50 text-emerald-700",
    lock: "bg-gray-100 text-gray-600",
};

function F({ label, required, badge, children }) {
    return (
        <div className="min-w-0">
            <label className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                {label}{required && <span className="text-red-500">*</span>}
                {badge && <span className={`text-[9px] font-bold px-1.5 py-0.5 rounded normal-case tracking-normal ${BADGE[badge.tone] || BADGE.lock}`}>{badge.text}</span>}
            </label>
            {children}
        </div>
    );
}

function Verify({ state, label, sub }) {
    const icon = state === "ok" ? <CheckCircle2 size={15} className="text-emerald-500" /> : state === "bad" ? <XCircle size={15} className="text-red-500" /> : <AlertTriangle size={15} className="text-amber-500" />;
    return (
        <li className="flex items-start gap-2.5">
            <span className="mt-0.5 flex-shrink-0">{icon}</span>
            <div className="min-w-0">
                <p className="text-[12.5px] font-semibold text-gray-900">{label}</p>
                <p className={`text-[11px] ${state === "bad" ? "text-red-500" : state === "warn" ? "text-amber-600" : "text-gray-400"}`}>{sub}</p>
            </div>
        </li>
    );
}

// Read-only render of the applicant's full visa-assessment submission.
function AssessmentSubmission({ intake, leadId, completeness = null }) {
    const { type, data } = intake;
    const sections = ASSESSMENT_SECTIONS[type] || [];
    const base = `${immBase()}/intakes/${type}/${data.id}`;
    const submitted = data.created_at
        ? new Date(data.created_at).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" })
        : null;

    const [aiNote, setAiNote] = useState(null);
    const [aiBusy, setAiBusy] = useState(false);
    const [aiTried, setAiTried] = useState(false);
    const fetchAiNote = () => {
        if (aiBusy) return;
        setAiBusy(true); setAiTried(true);
        fetch(`${immBase()}/cases/${leadId}/assessment-ai-note`, {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => setAiNote(d?.note || "No note available."))
            .catch(() => setAiNote("Could not reach the assistant."))
            .finally(() => setAiBusy(false));
    };

    const pct = completeness?.pct ?? null;
    const tone = pct === null ? "gray" : pct >= 80 ? "emerald" : pct >= 50 ? "amber" : "rose";
    const BAR = { emerald: "bg-emerald-500", amber: "bg-amber-500", rose: "bg-rose-500", gray: "bg-gray-300" }[tone];

    return (
        <div className="space-y-5">
            {completeness && (
                <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
                    <div className="flex items-center justify-between gap-3 flex-wrap mb-2">
                        <h3 className="text-[13px] font-bold text-gray-900">Assessment completeness</h3>
                        <span className="text-[12px] font-bold text-gray-700 tabular-nums">{pct}% · {completeness.filled}/{completeness.total}</span>
                    </div>
                    <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
                        <div className={`h-full rounded-full ${BAR}`} style={{ width: `${pct}%` }} />
                    </div>
                    <div className="mt-3">
                        {!aiTried ? (
                            <button type="button" onClick={fetchAiNote}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-indigo-200 text-indigo-600 text-[11px] font-bold uppercase tracking-wider hover:bg-indigo-50">
                                <Sparkles size={12} /> AI: what's missing
                            </button>
                        ) : aiBusy ? (
                            <p className="inline-flex items-center gap-1.5 text-[12px] text-gray-400"><Loader2 size={13} className="animate-spin" /> Thinking…</p>
                        ) : (
                            <div className="rounded-lg bg-indigo-50/60 border border-indigo-100 p-3">
                                <p className="text-[12.5px] text-gray-700 leading-relaxed">{aiNote}</p>
                                <p className="text-[10px] text-gray-400 mt-1.5">Internal &amp; indicative — not immigration advice.</p>
                            </div>
                        )}
                    </div>
                </div>
            )}

            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 inline-flex items-center gap-2">
                        <FileText size={13} className="text-gray-400" /> Visa assessment submission
                    </h3>
                    <p className="text-[12px] text-gray-500 mt-1">
                        {VISA_LABEL[type] || type}
                        {submitted && <span> · {data.status === "Draft" ? "draft created" : "submitted"} {submitted}</span>}
                        {data.intake_id && <span className="ml-2 font-mono text-gray-400">{data.intake_id}</span>}
                    </p>
                </div>
                {data.id && (
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <SendAssessmentButton leadId={leadId} />
                        <a href={`${base}/preview`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 text-[11px] font-semibold hover:bg-gray-50">
                            <Eye size={12} /> Preview
                        </a>
                        <a href={`${base}/pdf`} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#009688] text-white text-[11px] font-semibold hover:bg-[#00796b]">
                            <Download size={12} /> PDF
                        </a>
                        <a href={`${base}/word`} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-[#009688]/30 text-[#009688] text-[11px] font-semibold hover:bg-[#009688]/5">
                            <Download size={12} /> Word
                        </a>
                    </div>
                )}
            </div>

            <div className="space-y-4">
                {sections.map((sec) => (
                    <section key={sec.title} className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
                        <h4 className="text-[14px] font-bold text-gray-900 mb-4 pb-2 border-b border-gray-100">{sec.title}</h4>
                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4">
                            {sec.fields.map(([key, label]) => {
                                const { text, provided } = formatAssessmentValue(data[key]);
                                return (
                                    <div key={key} className="min-w-0">
                                        <dt className="text-[12px] font-medium text-gray-500 mb-0.5">{label}</dt>
                                        <dd className={`text-[13px] whitespace-pre-line break-words ${provided ? "text-gray-800" : "text-gray-300"}`}>{text}</dd>
                                    </div>
                                );
                            })}
                        </dl>
                    </section>
                ))}
            </div>
        </div>
    );
}

import AddPathwayButton from '@/components/leads/AddPathwayButton';
import { useState, useRef, useEffect } from "react";
import { Link, router } from "@inertiajs/react";
import { toast } from "sonner";
import CaseHealthBadge from "@/components/ai/CaseHealthBadge";
import { caseNav } from "@/lib/caseNav";
import {
    ArrowLeft, Globe, FileSignature, MessageSquarePlus, FilePlus2, Plane,
    BadgeCheck, Briefcase, Archive, Eye, Link2, ChevronDown, Phone, MoreHorizontal,
    Clock, ShieldCheck, MapPin, CheckCircle2,
} from "lucide-react";
import { AvatarPhoto } from "@/components/ui/Avatar";
import { immBase, leadBase } from "@/lib/caseRoutes";

const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric" }) : "—";
const fmtDay = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "2-digit", month: "short" }) : "—";
const fmtDateTime = (iso) =>
    iso ? new Date(iso).toLocaleString("en-NZ", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const initials = (name = "") =>
    name.trim().split(/\s+/).slice(0, 2).map((w) => w[0] || "").join("").toUpperCase() || "C";

const daysUntil = (iso) => (iso ? Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000) : null);
// Urgency tone from a "days left" figure — mirrors the mockup's red/amber cards.
const toneForDays = (d) => (d == null ? "none" : d <= 30 ? "red" : d <= 90 ? "amber" : "teal");

const ageFrom = (iso) => {
    if (! iso) return null;
    const d = new Date(iso);
    if (isNaN(d)) return null;
    const now = new Date();
    let a = now.getFullYear() - d.getFullYear();
    const m = now.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < d.getDate())) a--;
    return a >= 0 && a < 130 ? a : null;
};

export default function CaseProfileHeader({
    lead = {}, intake = null, attention = null, tiedTo = null, engagement = {},
    visaTypes = [], financials = {}, tasks = { items: [] }, dependents = [],
    checklist = { items: [] }, checklistProgress = {}, onNavigate,
}) {
    const [visaEditing, setVisaEditing] = useState(false);
    const [savingVisa, setSavingVisa] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef(null);

    useEffect(() => {
        const onDoc = (e) => { if (menuRef.current && ! menuRef.current.contains(e.target)) setMenuOpen(false); };
        document.addEventListener("mousedown", onDoc);
        return () => document.removeEventListener("mousedown", onDoc);
    }, []);

    const go = (tab) => onNavigate && onNavigate(tab);

    const fullName = `${lead.first_name ?? ""} ${lead.last_name ?? ""}`.trim() || lead.lead_id || "Unnamed case";
    const visa = lead.inz_visa_type || intake?.data?.visa_type_label || "Visa type not set";
    const currentVisaId = visaTypes.find((v) => v.name === lead.inz_visa_type)?.id ?? "";
    const stage = lead.immigration_stage || lead.stage || "Stage not set";
    const adviser = lead.assignee?.name || lead.immigration_assignee_name || null;
    const openedIso = lead.created_at || lead.immigration_converted_at || null;
    const conversionOrigin = lead.is_assessment_converted ? "Assessment-converted" : "Sales-converted";

    // Onshore / offshore — derived from where the applicant currently lives. We
    // don't assert lawful status (not recorded), just location, when known.
    const rc = (lead.residence_country || "").toLowerCase();
    const onshore = rc ? /new zealand|\bnz\b|aotearoa/.test(rc) : null;

    // Outstanding fee for the "Fee unpaid" chip — real arithmetic from financials.
    const outstanding = financials?.totals?.outstanding ?? financials?.totals?.balance ?? 0;

    const changeVisa = (id) => {
        setSavingVisa(true);
        router.post(`${immBase()}/cases/${lead.id}/visa`, { visa_type_id: id || null }, {
            preserveScroll: true,
            onSuccess: () => { toast.success("Visa type updated"); setVisaEditing(false); },
            onError: (e) => toast.error(Object.values(e)[0] || "Could not update the visa type"),
            onFinish: () => setSavingVisa(false),
        });
    };

    const archive = () => {
        if (! lead?.id) return;
        if (! window.confirm(`Archive ${fullName}?\n\nThe case will be hidden from the Cases list. Notes, tasks, documents, and audit history are preserved and the case can be restored later.`)) return;
        router.delete(`${leadBase()}/${lead.id}`, {
            preserveScroll: false,
            onSuccess: () => toast.success(`${fullName} archived`),
            onError: () => toast.error("Could not archive — please try again."),
        });
    };

    // ── Passport countdown (real) ──────────────────────────────────────────
    const passportDays = daysUntil(lead.passport_expiry);
    const passportTone = toneForDays(passportDays);

    // ── Supporting evidence (real checklist + document statuses) ───────────
    // Numerator/denominator mirror the Documents tab: required items approved
    // out of required items total (not every file ever attached to the case).
    const items = (checklist?.items || []).filter((i) => i.required ?? true);
    const reqTotal = checklistProgress?.required_total ?? items.length;
    const reqApproved = checklistProgress?.required_approved ?? 0;
    const evidenceRows = items.slice(0, 4).map((i) => ({ label: i.label, status: i.status }));

    // ── Travelling with (real dependants) ──────────────────────────────────
    const travellingWith = summariseDependants(dependents);
    const missingPassports = dependents.filter((d) => ! d.passport_expiry).length;

    // ── Next deadlines (real): passport expiry, target lodgement, open tasks ─
    const deadlines = buildDeadlines(lead, tasks);

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <Link href={caseNav().cases} className="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-900 transition-colors">
                    <ArrowLeft size={14} /> Back to cases
                </Link>
                <AddPathwayButton leadId={lead.id} />
            </div>

            <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
              <div className="flex flex-col lg:flex-row gap-6 items-start">
                {/* ══ LEFT — the full applicant header ══ */}
                <div className="w-full min-w-0" style={{ flex: "1 1 0%" }}>
                    {/* Identity */}
                    <div className="flex items-start gap-4">
                        <div className="w-16 h-16 rounded-2xl overflow-hidden bg-gradient-to-br from-gray-100 to-gray-200 text-gray-500 flex items-center justify-center font-black text-xl flex-shrink-0 border border-gray-200">
                            <AvatarPhoto src={lead.avatar_url} title={fullName}>{initials(fullName)}</AvatarPhoto>
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-start justify-between gap-3">
                                <h1 className="text-[26px] leading-tight font-bold text-gray-900 tracking-tight truncate">{fullName}</h1>
                                {/* Status pills */}
                                <div className="flex items-center gap-1.5 flex-shrink-0 pt-1">
                                    {onshore != null && (
                                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold ${onshore ? "bg-emerald-50 text-emerald-700 border border-emerald-200" : "bg-gray-100 text-gray-600 border border-gray-200"}`}>
                                            <MapPin size={11} /> {onshore ? "Onshore" : "Offshore"}
                                        </span>
                                    )}
                                    <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold bg-gray-50 text-gray-600 border border-gray-200">{stage}</span>
                                </div>
                            </div>

                            {/* Visa · id · opened · adviser */}
                            <div className="text-[13px] text-gray-500 mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                {visaEditing ? (
                                    <span className="inline-flex items-center gap-1.5">
                                        <Globe size={13} className="text-gray-400" />
                                        <select autoFocus defaultValue={currentVisaId} disabled={savingVisa}
                                            onChange={(e) => changeVisa(e.target.value)} onBlur={() => setVisaEditing(false)}
                                            className="text-[13px] border border-gray-300 rounded-md px-1.5 py-0.5 focus:outline-none focus:border-gray-500 disabled:opacity-50">
                                            <option value="">Visa type not set</option>
                                            {visaTypes.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                                        </select>
                                    </span>
                                ) : (
                                    <button type="button" onClick={() => setVisaEditing(true)} title="Change visa type"
                                        className="inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 -ml-1.5 hover:bg-gray-100 transition-colors font-medium text-gray-700">
                                        <Globe size={13} className="text-gray-400" /> {visa} <ChevronDown size={12} className="text-gray-400" />
                                    </button>
                                )}
                                {lead.lead_id && <><span className="text-gray-300">·</span><span className="font-mono text-gray-400">{lead.lead_id}</span></>}
                                {openedIso && <><span className="text-gray-300">·</span><span>opened {fmtDate(openedIso)}</span></>}
                                {adviser && <><span className="text-gray-300">·</span><span>adviser <span className="font-semibold text-gray-700">{adviser}</span></span></>}
                            </div>

                            {/* Milestone chips */}
                            <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                {engagement.signed && <Chip icon={FileSignature} tone="teal">Agreement signed</Chip>}
                                {engagement.sent && <Chip icon={FileSignature} tone="teal">Engagement {engagement.signed ? "signed" : "sent"}</Chip>}
                                <Chip icon={lead.is_assessment_converted ? BadgeCheck : Briefcase} tone="gray">{conversionOrigin}</Chip>
                                {outstanding > 0 && <Chip tone="red">Fee unpaid · ${Number(outstanding).toLocaleString("en-NZ")}</Chip>}
                                {tiedTo && (
                                    <Link href={caseNav().profile(tiedTo.id)} title={`Included as a dependant on ${tiedTo.name}'s case`}
                                        className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-sky-50 border border-sky-200 text-[11px] font-semibold text-sky-700 hover:bg-sky-100 transition-colors">
                                        <Link2 size={11} /> Tied to {tiedTo.name}
                                    </Link>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <button type="button" onClick={() => go("documents")}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-[13px] font-bold bg-emerald-600 text-white border border-emerald-600 hover:bg-emerald-700 transition-colors">
                            <Plane size={14} /> Lodge visa application
                        </button>
                        <HeaderBtn icon={FilePlus2} label="Request document" onClick={() => go("documents")} />
                        <HeaderBtn icon={MessageSquarePlus} label="Compose message" onClick={() => go("communications")} />
                        <HeaderBtn icon={Phone} label="Log call" onClick={() => go("notes")} />
                        <div className="relative" ref={menuRef}>
                            <button type="button" onClick={() => setMenuOpen((o) => ! o)} title="More actions"
                                className="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-200 bg-white text-gray-500 hover:bg-gray-50">
                                <MoreHorizontal size={16} />
                            </button>
                            {menuOpen && (
                                <div className="absolute left-0 z-30 mt-1 w-56 rounded-xl border border-gray-200 bg-white shadow-xl py-1">
                                    {lead.id && (
                                        <MenuItem icon={FileSignature} onClick={() => { setMenuOpen(false); router.visit(caseNav().engagement(lead.id)); }}>
                                            {engagement.sent ? "Manage engagement" : "Generate engagement"}
                                        </MenuItem>
                                    )}
                                    <MenuItem icon={Globe} onClick={() => { setMenuOpen(false); setVisaEditing(true); }}>Change visa type</MenuItem>
                                    <div className="my-1 border-t border-gray-100" />
                                    <MenuItem icon={Archive} tone="red" onClick={() => { setMenuOpen(false); archive(); }}>Archive case</MenuItem>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Countdown cards */}
                    <div className="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <CountdownCard
                            eyebrow={`Passport${lead.citizenship ? ` · ${lead.citizenship}` : ""}`}
                            code={lead.passport_number}
                            days={passportDays} tone={passportTone}
                            footer={lead.passport_expiry
                                ? `Expires ${fmtDate(lead.passport_expiry)}.${passportDays != null && passportDays <= 180 ? " Under 6 months validity — renew before lodging." : ""}`
                                : "No passport expiry on file."}
                        />
                        <CountdownCard
                            eyebrow="Current visa"
                            days={null} tone="none"
                            muted={! (lead.inz_status)}
                            bigText={lead.inz_status || "Not on file"}
                            footer={lead.inz_status ? `Immigration status: ${lead.inz_status}.` : "Current visa details not recorded on the case."}
                        />
                        <section className="rounded-xl border border-gray-200 bg-gray-50/60 p-4">
                            <div className="flex items-center justify-between">
                                <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-500">Supporting evidence</p>
                                <span className="text-[11px] font-semibold text-gray-500 tabular-nums">{reqApproved} of {reqTotal}</span>
                            </div>
                            <ul className="mt-2.5 space-y-1.5">
                                {evidenceRows.length === 0 ? (
                                    <li className="text-[12px] text-gray-400">No checklist items yet.</li>
                                ) : evidenceRows.map((r, i) => (
                                    <li key={i} className="flex items-center justify-between gap-2 text-[12px]">
                                        <span className="text-gray-700 truncate">{r.label}</span>
                                        <StatusText status={r.status} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>

                    {/* Contact & facts grid */}
                    <div className="mt-5 pt-5 border-t border-gray-100 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-4">
                        <Field label="Email" value={lead.email} />
                        <Field label="Mobile" value={lead.phone} />
                        <Field label="Located in" value={lead.residence_country} />
                        <Field label="Nationality / DOB"
                            value={[lead.citizenship, lead.dob ? `${fmtDate(lead.dob)}${ageFrom(lead.dob) != null ? ` (${ageFrom(lead.dob)})` : ""}` : null].filter(Boolean).join(" · ")} />
                        {travellingWith && (
                            <Field label="Travelling with" value={travellingWith}
                                sub={missingPassports > 0 ? `${missingPassports} missing passport detail${missingPassports === 1 ? "" : "s"}` : null}
                                subTone="amber" />
                        )}
                        {(lead.inz_client_number || lead.inz_application_number) && (
                            <Field label="INZ references"
                                value={[lead.inz_application_number && `App ${lead.inz_application_number}`, lead.inz_client_number && `Client ${lead.inz_client_number}`].filter(Boolean).join(" · ")} />
                        )}
                    </div>
                </div>

                {/* ══ RIGHT — AI summary, deadlines, since last opened (inside the card) ══ */}
                <div className="w-full space-y-3" style={{ flex: "0 0 320px", maxWidth: "100%" }}>
                    {lead.id && <CaseHealthBadge caseId={lead.id} variant="card" />}

                    {deadlines.length > 0 && (
                        <section className="rounded-2xl border border-gray-100 bg-white shadow-sm p-4">
                            <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500 mb-2.5 inline-flex items-center gap-1.5"><Clock size={12} className="text-gray-400" /> Next {Math.min(3, deadlines.length)} deadline{deadlines.length === 1 ? "" : "s"}</p>
                            <ul className="space-y-2.5">
                                {deadlines.slice(0, 3).map((d, i) => (
                                    <li key={i} className="flex items-start gap-3">
                                        <span className={`text-[11px] font-bold tabular-nums w-12 flex-shrink-0 ${d.tone === "red" ? "text-red-600" : d.tone === "amber" ? "text-amber-600" : "text-gray-500"}`}>{fmtDay(d.date)}</span>
                                        <span className="text-[12.5px] text-gray-700 leading-snug">{d.label}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <SinceLastOpened attention={attention} />
                </div>
              </div>
            </section>
        </div>
    );
}

// ── Small building blocks ──────────────────────────────────────────────────

function Chip({ icon: Icon, tone = "gray", children }) {
    const cls = {
        teal: "bg-teal-50 border-teal-100 text-teal-700",
        gray: "bg-gray-50 border-gray-100 text-gray-600",
        red: "bg-red-50 border-red-200 text-red-700",
    }[tone];
    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border text-[11px] font-semibold ${cls}`}>
            {Icon && <Icon size={11} />}{children}
        </span>
    );
}

function HeaderBtn({ icon: Icon, label, onClick }) {
    return (
        <button type="button" onClick={onClick}
            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-[13px] font-semibold border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-colors">
            <Icon size={14} className="text-gray-400" /> {label}
        </button>
    );
}

function MenuItem({ icon: Icon, tone, onClick, children }) {
    return (
        <button type="button" onClick={onClick}
            className={`w-full flex items-center gap-2.5 px-3 py-2 text-[13px] text-left transition-colors ${tone === "red" ? "text-red-600 hover:bg-red-50" : "text-gray-700 hover:bg-gray-50"}`}>
            {Icon && <Icon size={14} className={tone === "red" ? "text-red-400" : "text-gray-400"} />} {children}
        </button>
    );
}

function CountdownCard({ eyebrow, code, days, tone = "none", footer, muted = false, bigText }) {
    const border = { red: "border-red-200 bg-red-50/40", amber: "border-amber-200 bg-amber-50/40", teal: "border-teal-100 bg-teal-50/30", none: "border-gray-200 bg-gray-50/60" }[tone];
    const num = { red: "text-red-600", amber: "text-amber-600", teal: "text-teal-700", none: "text-gray-700" }[tone];
    return (
        <section className={`rounded-xl border p-4 ${border}`}>
            <div className="flex items-center justify-between gap-2">
                <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-gray-500 truncate">{eyebrow}</p>
                {code && <span className="text-[10px] font-mono text-gray-400 flex-shrink-0">{code}</span>}
            </div>
            {days != null ? (
                <p className="mt-1.5"><span className={`text-3xl font-black tabular-nums ${num}`}>{days < 0 ? "Expired" : days}</span>{days >= 0 && <span className="text-[13px] font-semibold text-gray-400 ml-1.5">days left</span>}</p>
            ) : (
                <p className={`mt-1.5 text-xl font-bold ${muted ? "text-gray-400" : num}`}>{bigText}</p>
            )}
            {footer && <p className="mt-2 pt-2 border-t border-black/5 text-[11px] text-gray-500 leading-snug">{footer}</p>}
        </section>
    );
}

function StatusText({ status }) {
    const s = (status || "").toLowerCase();
    const map = s === "approved" ? { t: "Approved", c: "text-teal-600" }
        : s === "checked" ? { t: "Checked", c: "text-teal-600" }
        : (s === "submitted" || s === "underreview" || s === "under_review") ? { t: "Awaiting review", c: "text-amber-600" }
        : s === "rejected" ? { t: "Rejected", c: "text-red-600" }
        : { t: "Missing", c: "text-gray-400" };
    return <span className={`text-[11px] font-semibold flex-shrink-0 ${map.c}`}>{map.t}</span>;
}

function Field({ label, value, sub, subTone }) {
    const has = value != null && String(value).trim() !== "";
    return (
        <div className="min-w-0">
            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">{label}</p>
            <p className={`text-[13px] mt-0.5 break-words ${has ? "text-gray-800 font-medium" : "text-gray-300"}`}>{has ? value : "Not recorded"}</p>
            {sub && <p className={`text-[11px] mt-0.5 ${subTone === "amber" ? "text-amber-600 font-semibold" : "text-gray-400"}`}>{sub}</p>}
        </div>
    );
}

// Passive "what changed since you last opened" — unchanged from before.
function SinceLastOpened({ attention }) {
    if (! attention?.last_opened_at) return null;
    const changes = attention.changed_since || [];
    return (
        <div className="rounded-2xl border border-gray-100 bg-gray-50/70 px-4 py-3">
            <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-gray-500 inline-flex items-center gap-1.5">
                <Eye size={12} className="text-teal-500" /> Since you last opened · {fmtDateTime(attention.last_opened_at)}
            </p>
            {changes.length === 0 ? (
                <p className="mt-1.5 text-[11.5px] text-gray-400">No changes since then.</p>
            ) : (
                <ul className="mt-1.5 space-y-1">
                    {changes.slice(0, 6).map((c) => (
                        <li key={c.id} className="text-[11.5px] text-gray-600 leading-snug">
                            <span className="text-gray-900">{c.description || "Update"}</span>
                            <span className="text-gray-400"> · {c.actor_name} · {fmtDateTime(c.created_at)}</span>
                        </li>
                    ))}
                    {changes.length > 6 && <li className="text-[11px] text-gray-400">+{changes.length - 6} more</li>}
                </ul>
            )}
        </div>
    );
}

// A readable "Partner + 2 children" phrase from the real dependants list.
function summariseDependants(dependents = []) {
    if (! dependents.length) return null;
    const rel = {};
    dependents.forEach((d) => { const r = (d.relationship || "other").toLowerCase(); rel[r] = (rel[r] || 0) + 1; });
    const partners = (rel.partner || 0) + (rel.spouse || 0) + (rel.husband || 0) + (rel.wife || 0);
    const kids = (rel.child || 0) + (rel.son || 0) + (rel.daughter || 0) + (rel.children || 0);
    const others = dependents.length - partners - kids;
    const parts = [];
    if (partners) parts.push(partners === 1 ? "Partner" : `${partners} partners`);
    if (kids) parts.push(`${kids} child${kids === 1 ? "" : "ren"}`);
    if (others > 0) parts.push(`${others} other${others === 1 ? "" : "s"}`);
    return parts.join(" + ") || `${dependents.length} dependant${dependents.length === 1 ? "" : "s"}`;
}

// Soonest real dates: passport expiry, target lodgement, open task due dates.
function buildDeadlines(lead, tasks) {
    const out = [];
    if (lead.passport_expiry) out.push({ date: lead.passport_expiry, label: "Passport expiry", tone: toneForDays(daysUntil(lead.passport_expiry)) });
    if (lead.target_lodgement_at) out.push({ date: lead.target_lodgement_at, label: "Target lodgement", tone: toneForDays(daysUntil(lead.target_lodgement_at)) });
    (tasks?.items || []).filter((t) => ! t.completed && t.due_at).forEach((t) => {
        out.push({ date: t.due_at, label: t.title || "Task due", tone: toneForDays(daysUntil(t.due_at)) });
    });
    return out.filter((d) => daysUntil(d.date) != null && daysUntil(d.date) >= -1).sort((a, b) => new Date(a.date) - new Date(b.date));
}

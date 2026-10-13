import { useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { DayPicker } from "react-day-picker";
import "react-day-picker/style.css";
import {
    ShieldCheck, Calendar, CalendarCheck, Clock, Video, FileText, CheckCircle2,
    Circle, Lock, ClipboardCheck, Loader2, ArrowRight, UploadCloud, Image as ImageIcon,
    ExternalLink, Wallet, Receipt, FileSignature,
} from "lucide-react";
import AgreementSignModal from "@/components/AgreementSignModal";

const money = (n) => "$" + Number(n || 0).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { weekday: "short", day: "numeric", month: "long", year: "numeric" }) : "";

// Teal-branded calendar styling for react-day-picker (matches the page accent).
const RDP_TEAL = {
    "--rdp-accent-color": "#009688",
    "--rdp-accent-background-color": "#00968815",
    "--rdp-day-width": "38px",
    "--rdp-day-height": "38px",
    "--rdp-day_button-width": "38px",
    "--rdp-day_button-height": "38px",
    "--rdp-nav_button-width": "30px",
    "--rdp-nav_button-height": "30px",
    fontSize: "0.8rem",
};

// The earliest a meeting can be booked — we schedule at least 5 days ahead.
const MEETING_LEAD_DAYS = 5;

// Bookable half-hour slots, 9:00 AM – 4:30 PM.
const TIME_SLOTS = (() => {
    const out = [];
    for (let h = 9; h <= 16; h++) { out.push(`${String(h).padStart(2, "0")}:00`); out.push(`${String(h).padStart(2, "0")}:30`); }
    return out;
})();
const slotLabel = (t) => {
    const [h, m] = t.split(":").map(Number);
    const ap = h >= 12 ? "PM" : "AM";
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, "0")} ${ap}`;
};
const toYmd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

// Client-facing pre-screening stages (process only — not an outcome/advice).
const STEPS = [
    { key: "assessment", label: "Assessment" },
    { key: "consultation", label: "Consultation" },
    { key: "consent", label: "Consent" },
    { key: "decision", label: "Decision" },
];

function Stepper({ pre }) {
    // Derive how far along we are from the pre-screening summary booleans.
    const done = {
        assessment: true,
        consultation: pre.consult_booked || pre.status === "consent" || pre.status === "eligible" || pre.status === "converted",
        consent: pre.consent_ack || pre.status === "eligible" || pre.status === "converted",
        decision: pre.status === "eligible" || pre.status === "converted" || pre.status === "declined",
    };
    const declined = pre.status === "declined";
    return (
        <div className="flex items-center gap-1.5 flex-wrap">
            {STEPS.map((s, idx) => {
                const isDone = done[s.key] && !(declined && s.key === "decision");
                return (
                    <span key={s.key} className="flex items-center gap-1.5">
                        <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[12px] font-semibold border ${
                            isDone ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                                : "bg-white/70 text-gray-400 border-gray-200"}`}>
                            {isDone ? <CheckCircle2 size={13} /> : <Circle size={13} />} {s.label}
                        </span>
                        {idx < STEPS.length - 1 && <span className={`w-5 h-px ${isDone ? "bg-emerald-300" : "bg-gray-200"}`} />}
                    </span>
                );
            })}
        </div>
    );
}

function MeetingCard({ title, subtitle, booking, token, meeting }) {
    const booked = !!booking;
    const pending = booking?.status === "pending";
    const [open, setOpen] = useState(false);
    const [day, setDay] = useState(null);
    const [time, setTime] = useState("");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);

    // Meetings are booked at least MEETING_LEAD_DAYS ahead.
    const minDate = (() => { const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() + MEETING_LEAD_DAYS); return d; })();

    const submit = (e) => {
        e.preventDefault();
        if (!day || !time) return;
        setSaving(true);
        router.post(`/application/${token}/book`, { meeting, date: toYmd(day), time, note }, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
            <div className="flex items-center gap-2 flex-wrap">
                <Calendar size={16} className="text-[#009688]" />
                <h3 className="text-[14px] font-bold text-gray-900">{title}</h3>
                {booked ? (
                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${pending ? "bg-amber-50 text-amber-700 border-amber-200" : "bg-emerald-50 text-emerald-700 border-emerald-200"}`}>
                        <CalendarCheck size={11} /> {pending ? "Time requested" : (booking.status || "Booked")}
                    </span>
                ) : (
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border bg-gray-50 text-gray-400 border-gray-200">Not scheduled</span>
                )}
            </div>
            <p className="text-[12.5px] text-gray-500 mt-1">{subtitle}</p>

            {booked ? (
                <div className="mt-3 space-y-1.5 text-[13px] text-gray-700">
                    <p className="flex items-center gap-1.5"><Calendar size={14} className="text-gray-400" /> {fmtDate(booking.at || booking.date) || "—"}</p>
                    {booking.time && <p className="flex items-center gap-1.5"><Clock size={14} className="text-gray-400" /> {booking.time}</p>}
                    {pending && <p className="text-[12px] text-amber-600">We'll confirm this time with you shortly.</p>}
                    {booking.consultant && !pending && <p className="text-[12px] text-gray-500">With {booking.consultant}</p>}
                    {booking.meet_link && (
                        <a href={booking.meet_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-[#009688] hover:underline">
                            <Video size={14} /> Join meeting
                        </a>
                    )}
                </div>
            ) : open ? (
                <form onSubmit={submit} className="mt-3 space-y-3">
                    {/* Pick a day — earliest is MEETING_LEAD_DAYS out. */}
                    <div>
                        <span className="text-[11px] font-semibold text-gray-500">Choose a date</span>
                        <div className="mt-1 rounded-xl border border-gray-200 p-2 flex justify-center">
                            <DayPicker
                                mode="single"
                                selected={day}
                                onSelect={(d) => { setDay(d); setTime(""); }}
                                startMonth={minDate}
                                today={minDate}
                                disabled={{ before: minDate }}
                                style={RDP_TEAL}
                            />
                        </div>
                        <p className="text-[11px] text-gray-400 mt-1">Earliest available {fmtDate(minDate)} — we schedule at least {MEETING_LEAD_DAYS} days ahead.</p>
                    </div>

                    {/* Pick a time slot — enabled once a day is chosen. */}
                    <div>
                        <span className="text-[11px] font-semibold text-gray-500">Choose a time</span>
                        {!day ? (
                            <p className="mt-1 text-[12px] text-gray-400">Choose a date first.</p>
                        ) : (
                            <div className="mt-1 grid grid-cols-3 sm:grid-cols-4 gap-1.5">
                                {TIME_SLOTS.map((t) => (
                                    <button key={t} type="button" onClick={() => setTime(t)}
                                        className={`px-2 py-1.5 rounded-lg text-[12px] font-semibold border transition-colors ${
                                            time === t ? "bg-[#009688] text-white border-[#009688]"
                                                : "bg-white text-gray-600 border-gray-200 hover:border-[#009688] hover:text-[#009688]"}`}>
                                        {slotLabel(t)}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    <input type="text" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Anything we should know? (optional)" maxLength={500}
                        className="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688]" />
                    <div className="flex items-center gap-2">
                        <button type="submit" disabled={saving || !day || !time}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                            {saving ? <Loader2 size={14} className="animate-spin" /> : <CalendarCheck size={14} />} Send request
                        </button>
                        <button type="button" onClick={() => setOpen(false)} className="px-3 py-2 rounded-lg text-[13px] font-semibold text-gray-500 hover:bg-gray-50">Cancel</button>
                    </div>
                </form>
            ) : (
                <button type="button" onClick={() => setOpen(true)}
                    className="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b]">
                    Request a time <ArrowRight size={14} />
                </button>
            )}
        </div>
    );
}

function PlanSection({ plan, token }) {
    const [choosing, setChoosing] = useState(null);
    if (!plan) return null;

    const choose = (c) => {
        setChoosing(c);
        router.post(`/application/${token}/plan`, { choice: c }, { preserveScroll: true, onFinish: () => setChoosing(null) });
    };

    const draft = plan.status === "draft";
    const active = plan.status === "active";
    const completed = plan.status === "completed";

    return (
        <section className="rounded-2xl bg-white border border-gray-100 shadow-sm p-6">
            <div className="flex items-center gap-2 mb-1">
                <Wallet size={16} className="text-[#009688]" />
                <h2 className="text-[15px] font-bold text-gray-900">Payment</h2>
            </div>

            {draft && (
                <>
                    <p className="text-[13px] text-gray-600">Total fee <span className="font-bold text-gray-900">{money(plan.total_amount)}</span>. Choose how you'd like to pay:</p>
                    <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button type="button" onClick={() => choose("pay_now")} disabled={choosing}
                            className="text-left rounded-xl border border-gray-200 p-4 hover:border-[#009688] hover:bg-[#009688]/5 transition-colors disabled:opacity-60">
                            <p className="text-[13px] font-bold text-gray-900">Pay in full</p>
                            <p className="text-[20px] font-bold text-gray-900 mt-1 tabular-nums">{money(plan.total_amount)}</p>
                            <p className="text-[11.5px] text-gray-500 mt-0.5">One payment.</p>
                        </button>
                        <button type="button" onClick={() => choose("pay_later")} disabled={choosing}
                            className="text-left rounded-xl border border-gray-200 p-4 hover:border-[#009688] hover:bg-[#009688]/5 transition-colors disabled:opacity-60">
                            <p className="text-[13px] font-bold text-gray-900">Pay weekly</p>
                            <p className="text-[20px] font-bold text-gray-900 mt-1 tabular-nums">{money(plan.weekly_amount)}<span className="text-[12px] font-medium text-gray-400"> / week × {plan.weeks}</span></p>
                            <p className="text-[11.5px] text-gray-500 mt-0.5">After a {money(plan.deposit_amount)} deposit.</p>
                        </button>
                    </div>
                    {choosing && <p className="mt-2 inline-flex items-center gap-1.5 text-[12px] text-gray-400"><Loader2 size={12} className="animate-spin" /> Saving…</p>}
                </>
            )}

            {(active || completed) && (
                <>
                    <div className="grid grid-cols-3 gap-3">
                        <div><p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Weekly</p><p className="text-[16px] font-bold text-gray-900 tabular-nums">{money(plan.weekly_amount)}</p></div>
                        <div><p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Paid</p><p className="text-[16px] font-bold text-emerald-600 tabular-nums">{money(plan.total_paid)}</p></div>
                        <div><p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">Remaining</p><p className="text-[16px] font-bold text-gray-900 tabular-nums">{money(plan.amount_owed)}</p></div>
                    </div>
                    <div className="mt-4 overflow-x-auto rounded-xl border border-gray-100">
                        <table className="w-full text-[12.5px]">
                            <thead className="bg-gray-50 text-gray-400 text-[10px] uppercase tracking-wider">
                                <tr><th className="text-left px-3 py-2 font-bold">Payment</th><th className="text-left px-3 py-2 font-bold">Due</th><th className="text-right px-3 py-2 font-bold">Amount</th><th className="text-right px-3 py-2 font-bold">Status</th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {(plan.schedule || []).map((r) => (
                                    <tr key={r.id} className={r.status === "overdue" ? "bg-rose-50/40" : ""}>
                                        <td className="px-3 py-2 text-gray-700">{r.kind === "deposit" ? "Deposit" : `Week ${r.sequence}`}</td>
                                        <td className="px-3 py-2 text-gray-500">{r.due_date}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-gray-900">{money(r.amount)}</td>
                                        <td className="px-3 py-2 text-right">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${
                                                r.status === "paid" ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                                                    : r.status === "overdue" ? "bg-rose-50 text-rose-600 border-rose-200"
                                                    : "bg-gray-50 text-gray-500 border-gray-200"}`}>{r.status}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-3 text-[12px] text-gray-500 flex items-center gap-1.5"><Receipt size={13} /> Payments are recorded by our team as they're received.</p>
                    {completed && <p className="mt-2 inline-flex items-center gap-1.5 text-[13px] font-semibold text-emerald-700"><CheckCircle2 size={15} /> Fully paid — thank you.</p>}
                </>
            )}
        </section>
    );
}

// Your next step — one clear current task, so the client always knows what to
// do now. Derived from the existing state; buttons scroll to / open the step.
function NextStep({ pre, agreement, bookings, plan, onSign }) {
    if (pre.status === "declined" || pre.status === "converted") return null;
    const go = (id) => document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });

    let kind = "Your next step", tone = "do", title, body, cta = null;
    if (agreement?.can_sign) {
        kind = "Action required"; title = "Review & sign your agreement";
        body = "Please review your engagement agreement and sign to continue."; cta = { text: "Review & sign", onClick: onSign };
    } else if (pre.consent_sent && !pre.consent_ack) {
        kind = "Action required"; title = "Review & acknowledge consent";
        body = "Please review the consent information and acknowledge it."; cta = { text: "Review consent", onClick: () => go("consent") };
    } else if (!bookings.consult) {
        kind = "Your next step"; title = "Book your immigration consultation";
        body = "Choose a preferred date and time and our team will confirm it."; cta = { text: "Request a time", onClick: () => go("consultations") };
    } else if (bookings.consult?.status === "pending") {
        kind = "Waiting for our team"; tone = "wait"; title = "Consultation request received";
        body = "We'll confirm the time here once it has been scheduled.";
    } else if (plan?.status === "draft") {
        kind = "Action required"; title = "Choose how you'd like to pay";
        body = "Select pay in full or weekly payments to continue."; cta = { text: "Choose payment", onClick: () => go("payment") };
    } else {
        kind = "You're up to date"; tone = "done"; title = "Nothing to do right now";
        body = "We'll let you know here when there's a next step.";
    }
    const dot = tone === "wait" ? "bg-amber-500" : tone === "done" ? "bg-emerald-500" : "bg-[#009688]";
    const lab = tone === "wait" ? "text-amber-600" : tone === "done" ? "text-emerald-600" : "text-[#009688]";

    return (
        <section className="rounded-2xl bg-white border border-gray-200 p-5 sm:p-6">
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div className="min-w-0">
                    <p className={`inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.18em] ${lab}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${dot}`} /> {kind}
                    </p>
                    <h2 className="text-[17px] font-bold text-gray-900 mt-1">{title}</h2>
                    <p className="text-[13px] text-gray-500 mt-1 max-w-md">{body}</p>
                </div>
                {cta && (
                    <button type="button" onClick={cta.onClick}
                        className="flex-shrink-0 inline-flex items-center gap-1.5 px-5 py-2.5 rounded-lg bg-[#009688] text-white text-[13.5px] font-semibold hover:bg-[#00796b]">
                        {cta.text} <ArrowRight size={15} />
                    </button>
                )}
            </div>
        </section>
    );
}

/**
 * Private client pre-screening page (/application/{token}). The pre-screening
 * token is the credential. Shows status + process only — status of the visa
 * assessment, consultations and consent — never immigration advice.
 */
export default function ApplicationPage({ applicant, visa_label, submitted_at, pre, bookings = {}, documents = [], plan = null, agreement = null, booking_url = "/booking" }) {
    const [acking, setAcking] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [signOpen, setSignOpen] = useState(false);
    const fileRef = useRef(null);

    const ackConsent = () => {
        setAcking(true);
        router.post(`/application/${pre.token}/consent-ack`, {}, {
            preserveScroll: true,
            onFinish: () => setAcking(false),
        });
    };

    const onPickFiles = (e) => {
        const files = Array.from(e.target.files || []);
        if (!files.length) return;
        setUploading(true);
        router.post(`/application/${pre.token}/document`, { files }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => { setUploading(false); if (fileRef.current) fileRef.current.value = ""; },
        });
    };

    const terminal = pre.status === "converted" || pre.status === "declined";

    return (
        <div className="min-h-screen bg-gray-50 flex flex-col">
            <Head title="Your application — EP" />

            {/* Slim private header */}
            <header className="bg-white border-b border-gray-100">
                <div className="max-w-[880px] mx-auto px-4 py-3.5 flex items-center justify-between">
                    <span className="text-[17px] font-extrabold tracking-tight text-gray-900">EP<span className="text-[#009688]">.</span></span>
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-400">
                        <Lock size={12} /> Private link
                    </span>
                </div>
            </header>

            <main className="flex-1 max-w-[880px] w-full mx-auto px-4 py-6 space-y-5">
                {/* Hero / status */}
                <section className="rounded-2xl bg-white border border-gray-100 shadow-sm p-6">
                    <h1 className="text-2xl font-extrabold text-gray-900">Hi {applicant.first_name || applicant.name} 👋</h1>
                    <p className="text-[15px] font-semibold text-gray-700 mt-1">Your {visa_label} application</p>
                    <p className="text-[13px] text-gray-500 mt-1">
                        We'll guide you through each step of your application here.
                        {submitted_at && <> Submitted {fmtDate(submitted_at)}.</>}
                    </p>
                    <div className="mt-5">
                        <Stepper pre={pre} />
                    </div>
                    {pre.status === "declined" && (
                        <div className="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <p className="text-[14px] font-bold text-gray-900">Your pre-screening has been closed</p>
                            <p className="text-[13px] text-gray-500 mt-1">
                                Our team has determined that we cannot proceed with this application at this stage.
                                {pre.decline_reason ? ` ${pre.decline_reason}` : ""}
                            </p>
                        </div>
                    )}
                    {pre.status === "eligible" && (
                        <div className="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                            <p className="inline-flex items-center gap-1.5 text-[14px] font-bold text-emerald-800"><CheckCircle2 size={16} /> You're ready to proceed</p>
                            <p className="text-[13px] text-emerald-700/90 mt-1">Based on your current pre-screening, our team has identified you as a strong potential candidate. Continue with the next steps below.</p>
                        </div>
                    )}
                </section>

                {/* Your next step — one clear current task */}
                <NextStep pre={pre} agreement={agreement} bookings={bookings} plan={plan} onSign={() => setSignOpen(true)} />

                {/* Consultation — a single booking; the client books one meeting
                    with an adviser and isn't shown the internal meeting steps. */}
                {!terminal && (
                    <section id="consultations" className="space-y-3 scroll-mt-4">
                        <div className="flex items-center gap-2">
                            <Calendar size={16} className="text-[#009688]" />
                            <h2 className="text-[15px] font-bold text-gray-900">Your consultation</h2>
                        </div>
                        <p className="text-[12.5px] text-gray-500 -mt-1">You can book this at any time — no need to finish everything else first.</p>
                        <MeetingCard
                            title="Book a consultation"
                            subtitle="Meet an adviser to talk through your situation."
                            booking={bookings.consult}
                            token={pre.token}
                            meeting="consult"
                        />
                    </section>
                )}

                {/* Consent */}
                {!terminal && (
                    <section id="consent" className="rounded-2xl bg-white border border-gray-100 shadow-sm p-6 scroll-mt-4">
                        <div className="flex items-center gap-2 mb-2">
                            <ShieldCheck size={16} className="text-[#009688]" />
                            <h2 className="text-[15px] font-bold text-gray-900">Consent</h2>
                        </div>
                        {pre.consent_ack ? (
                            <p className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-emerald-700">
                                <CheckCircle2 size={15} /> Thank you — your consent has been recorded.
                            </p>
                        ) : pre.consent_sent ? (
                            <div>
                                <p className="text-[13px] text-gray-600">
                                    Please review the consent information we shared with you (including the key-matters audio).
                                    When you're ready, confirm below so we can continue.
                                </p>
                                <button type="button" onClick={ackConsent} disabled={acking}
                                    className="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                                    {acking ? <Loader2 size={14} className="animate-spin" /> : <ClipboardCheck size={14} />} I acknowledge the consent
                                </button>
                            </div>
                        ) : (
                            <p className="text-[13px] text-gray-500">We'll share your consent information here after your consultation.</p>
                        )}
                    </section>
                )}

                {/* Engagement agreement — review & sign */}
                {!terminal && agreement && (
                    <section className="rounded-2xl bg-white border border-gray-100 shadow-sm p-6">
                        <div className="flex items-center gap-2 mb-1">
                            <FileSignature size={16} className="text-[#009688]" />
                            <h2 className="text-[15px] font-bold text-gray-900">Your agreement</h2>
                        </div>
                        {agreement.signed ? (
                            <p className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-emerald-700">
                                <CheckCircle2 size={15} /> Signed{agreement.signer_name ? ` by ${agreement.signer_name}` : ""} — thank you.
                                {agreement.pdf_url && <a href={agreement.pdf_url} target="_blank" rel="noreferrer" className="ml-2 text-[#009688] hover:underline font-semibold">View PDF</a>}
                            </p>
                        ) : agreement.can_sign ? (
                            <div>
                                <p className="text-[13px] text-gray-600">Please review your engagement agreement and sign to continue.</p>
                                <div className="mt-3 flex items-center gap-2">
                                    <a href={agreement.pdf_url} target="_blank" rel="noreferrer"
                                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50"><FileText size={14} /> Review</a>
                                    <button type="button" onClick={() => setSignOpen(true)}
                                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b]"><FileSignature size={14} /> Review &amp; sign</button>
                                </div>
                            </div>
                        ) : (
                            <p className="text-[13px] text-gray-500">Your agreement is being prepared.</p>
                        )}
                    </section>
                )}
                {signOpen && agreement?.sign_url && (
                    <AgreementSignModal postUrl={agreement.sign_url} title="Sign your agreement" requireTerms reviewUrl={agreement.pdf_url} onClose={() => setSignOpen(false)} />
                )}

                {/* Payment — pay now / pay later, or the active schedule */}
                {!terminal && <div id="payment" className="scroll-mt-4"><PlanSection plan={plan} token={pre.token} /></div>}

                {/* Documents — upload + view your own files */}
                {!terminal && (
                    <section className="rounded-2xl bg-white border border-gray-100 shadow-sm p-6">
                        <div className="flex items-center gap-2 mb-1">
                            <FileText size={16} className="text-[#009688]" />
                            <h2 className="text-[15px] font-bold text-gray-900">Your documents</h2>
                        </div>
                        <p className="text-[12.5px] text-gray-500">Upload supporting documents (PDF or image). Our team will review them.</p>

                        <label className={`mt-3 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-7 cursor-pointer transition-colors ${uploading ? "border-gray-200 bg-gray-50" : "border-gray-200 hover:border-[#009688] hover:bg-[#009688]/5"}`}>
                            {uploading ? <Loader2 size={22} className="animate-spin text-[#009688]" /> : <UploadCloud size={22} className="text-gray-400" />}
                            <span className="text-[13px] font-semibold text-gray-700">{uploading ? "Uploading…" : "Choose files to upload"}</span>
                            <span className="text-[11px] text-gray-400">PDF, JPG or PNG</span>
                            <input ref={fileRef} type="file" multiple accept=".pdf,.jpg,.jpeg,.png" className="hidden" onChange={onPickFiles} disabled={uploading} />
                        </label>

                        {documents.length > 0 && (
                            <ul className="mt-4 space-y-2">
                                {documents.map((d) => (
                                    <li key={d.id}>
                                        <a href={d.url} target="_blank" rel="noreferrer"
                                            className="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 hover:border-[#009688] hover:bg-gray-50 transition-colors">
                                            <span className="flex-shrink-0 w-9 h-9 rounded-lg bg-[#009688]/10 text-[#00796b] flex items-center justify-center">
                                                {d.is_image ? <ImageIcon size={16} /> : <FileText size={16} />}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-[13px] font-semibold text-gray-800 truncate">{d.name}</span>
                                                <span className="block text-[11px] text-gray-400">{d.status}</span>
                                            </span>
                                            <ExternalLink size={14} className="text-gray-400 flex-shrink-0" />
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </main>

            <footer className="border-t border-gray-100 bg-white">
                <div className="max-w-[880px] mx-auto px-4 py-5 text-center">
                    <p className="text-[11.5px] text-gray-400">
                        This link is private to you — please don't share it. Information here describes your status and process only and is not immigration advice.
                    </p>
                </div>
            </footer>
        </div>
    );
}

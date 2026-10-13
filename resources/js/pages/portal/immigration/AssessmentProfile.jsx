import { useEffect, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { DayPicker } from "react-day-picker";
import "react-day-picker/style.css";
import {
    ChevronLeft, FileText, ExternalLink, Calendar, CalendarCheck, Clock,
    Images, Loader2, Video, CheckCircle2, Circle, ClipboardList, Copy,
    Wallet, Receipt, Check, ArrowRightCircle, Eye,
} from "lucide-react";
import { toast } from "sonner";
import { IntakeViewModal, PRE_STATUS } from "./Assessments";
import RichTextEditor from "@/components/templates/RichTextEditor";

// Teal-branded calendar styling for react-day-picker (matches the page accent).
const RDP_TEAL = {
    "--rdp-accent-color": "#009688",
    "--rdp-accent-background-color": "#00968815",
    "--rdp-day-width": "34px",
    "--rdp-day-height": "34px",
    "--rdp-day_button-width": "34px",
    "--rdp-day_button-height": "34px",
    "--rdp-nav_button-width": "28px",
    "--rdp-nav_button-height": "28px",
    fontSize: "0.78rem",
};

// Bookable half-hour slots, 9:00 AM – 4:30 PM.
const TIME_SLOTS = (() => {
    const out = [];
    for (let h = 9; h <= 16; h++) { out.push(`${String(h).padStart(2, "0")}:00`); out.push(`${String(h).padStart(2, "0")}:30`); }
    return out;
})();
const slotLabel = (t) => {
    const [h, m] = t.split(":").map(Number);
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, "0")} ${h >= 12 ? "PM" : "AM"}`;
};
const toYmd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

const VISA_LABEL = {
    resident: "Resident Visa", work: "Work Visa", student: "Student Visa",
    visitor: "Visitor Visa", family: "Family Visa", free: "Free Assessment",
};
const VISA_AVATAR_BG = {
    resident: "bg-emerald-600", work: "bg-indigo-600", student: "bg-sky-600",
    visitor: "bg-amber-600", family: "bg-rose-500", free: "bg-gray-500",
};
const IMG_EXT = /\.(jpe?g|png|gif|webp|bmp|svg|heic)$/i;

const initials = (name) =>
    (name || "?").split(" ").filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("") || "?";
const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { day: "numeric", month: "long", year: "numeric" }) : "";

// A deadline countdown pill (amber while pending, rose once overdue).
function DeadlinePill({ iso, overdue, label }) {
    if (!iso) return null;
    const d = new Date(iso);
    const days = Math.ceil((d - new Date()) / 86400000);
    const when = d.toLocaleDateString("en-NZ", { day: "numeric", month: "short" });
    const tail = overdue ? "overdue" : days <= 0 ? "due today" : `${days} day${days === 1 ? "" : "s"} left`;
    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${
            overdue ? "bg-rose-50 text-rose-600 border-rose-200" : "bg-amber-50 text-amber-700 border-amber-200"}`}>
            <Clock size={10} /> {label} {when} · {tail}
        </span>
    );
}

// Pre-screening pipeline — the visual stages a potential client moves through.
const STEPS = [
    { key: "new", label: "New" },
    { key: "consultation", label: "Consultation" },
    { key: "consent", label: "Agreement" },
    { key: "eligible", label: "Eligible" },
];
const STEP_ORDER = { new: 0, consultation: 1, consent: 2, eligible: 3, converted: 4, declined: 4 };

function Stepper({ status }) {
    const at = STEP_ORDER[status] ?? 0;
    const declined = status === "declined";
    return (
        <div className="flex items-center gap-1 flex-wrap">
            {STEPS.map((s, idx) => {
                const done = idx < at || status === "converted";
                const current = idx === at && !done;
                return (
                    <span key={s.key} className="flex items-center gap-1">
                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border ${
                            done ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                                : current ? "bg-[#009688]/10 text-[#00796b] border-[#009688]/30"
                                : "bg-gray-50 text-gray-400 border-gray-200"}`}>
                            {done ? <CheckCircle2 size={12} /> : <Circle size={12} />} {s.label}
                        </span>
                        {idx < STEPS.length - 1 && <span className={`w-5 h-px ${done ? "bg-emerald-300" : "bg-gray-200"}`} />}
                    </span>
                );
            })}
            {status === "converted" && <span className="ml-1 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold border bg-green-50 text-green-700 border-green-200">Converted</span>}
            {declined && <span className="ml-1 inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold border bg-rose-50 text-rose-600 border-rose-200">Declined</span>}
        </div>
    );
}

// Calendar-style month/day block for a meeting date.
function DateBadge({ iso, date }) {
    const d = iso ? new Date(iso) : date ? new Date(date) : null;
    if (!d || isNaN(d)) {
        return (
            <div className="flex-shrink-0 w-14 h-14 rounded-xl border border-dashed border-gray-200 flex flex-col items-center justify-center text-gray-300">
                <Calendar size={18} />
            </div>
        );
    }
    return (
        <div className="flex-shrink-0 w-14 h-14 rounded-xl border border-[#009688]/30 bg-[#009688]/5 flex flex-col items-center justify-center leading-none">
            <span className="text-[9px] font-bold uppercase tracking-wider text-[#009688]">{d.toLocaleDateString("en-NZ", { month: "short" })}</span>
            <span className="text-[20px] font-bold text-gray-900 tabular-nums">{d.getDate()}</span>
        </div>
    );
}

function BookingCard({ title, subtitle, booking, token, meeting }) {
    const booked = !!booking;
    const pending = booking?.status === "pending";
    const [open, setOpen] = useState(false);
    const [day, setDay] = useState(null);
    const [time, setTime] = useState("");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);
    const inputCls = "w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688]";
    const todayMidnight = (() => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; })();

    const submit = (e) => {
        e.preventDefault();
        if (!day || !time || !token) return;
        setSaving(true);
        router.post(`/portal/immigration/pre-screening/${token}/book`, { meeting, date: toYmd(day), time, note }, {
            preserveScroll: true,
            onSuccess: () => { setOpen(false); setDay(null); setTime(""); setNote(""); },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
            <div className="flex items-start gap-4">
                <DateBadge iso={booking?.at} date={booking?.date} />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="text-[14px] font-bold text-gray-900">{title}</h3>
                        {booked ? (
                            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${pending ? "bg-amber-50 text-amber-700 border-amber-200" : "bg-emerald-50 text-emerald-700 border-emerald-200"}`}>
                                <CalendarCheck size={11} /> {pending ? "Time requested" : (booking.status || "Confirmed")}
                            </span>
                        ) : (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border bg-gray-50 text-gray-400 border-gray-200">Not scheduled</span>
                        )}
                    </div>
                    <p className="text-[11.5px] text-gray-500 mt-0.5">{subtitle}</p>
                    {booked && (
                        <div className="mt-3 space-y-1.5 text-[12.5px] text-gray-700">
                            <p className="flex items-center gap-1.5"><Calendar size={13} className="text-gray-400" /> {fmtDate(booking.at || booking.date) || "—"}</p>
                            {booking.time && <p className="flex items-center gap-1.5"><Clock size={13} className="text-gray-400" /> {booking.time}</p>}
                            {pending && <p className="text-[11.5px] text-amber-600">Client requested this time — confirm it or reschedule below.</p>}
                            {booking.consultant && !pending && <p className="text-[11.5px] text-gray-500">With {booking.consultant}</p>}
                            {booking.meet_link && (
                                <a href={booking.meet_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-[#009688] hover:underline">
                                    <Video size={13} /> Join meeting
                                </a>
                            )}
                        </div>
                    )}
                    {!booked && !open && (
                        <p className="mt-3 text-[12px] text-gray-500">The client requests a time from their application link, or schedule it here.</p>
                    )}

                    {/* Staff schedule / reschedule directly from the profile. */}
                    {token && (open ? (
                        <form onSubmit={submit} className="mt-3 space-y-3">
                            <div>
                                <span className="text-[11px] font-semibold text-gray-500">Choose a date</span>
                                <div className="mt-1 rounded-xl border border-gray-200 p-2 flex justify-center">
                                    <DayPicker
                                        mode="single"
                                        selected={day}
                                        onSelect={(d) => { setDay(d); setTime(""); }}
                                        startMonth={todayMidnight}
                                        disabled={{ before: todayMidnight }}
                                        style={RDP_TEAL}
                                    />
                                </div>
                            </div>
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
                            <input type="text" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Note (optional)" maxLength={500} className={inputCls} />
                            <div className="flex items-center gap-2">
                                <button type="submit" disabled={saving || !day || !time}
                                    className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                                    {saving ? <Loader2 size={14} className="animate-spin" /> : <CalendarCheck size={14} />} {booked ? "Reschedule" : "Schedule meeting"}
                                </button>
                                <button type="button" onClick={() => setOpen(false)} className="px-3 py-2 rounded-lg text-[13px] font-semibold text-gray-500 hover:bg-gray-50">Cancel</button>
                            </div>
                        </form>
                    ) : (
                        <button type="button" onClick={() => setOpen(true)}
                            className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-[#009688] text-[#009688] text-[12.5px] font-semibold hover:bg-[#009688]/5">
                            <CalendarCheck size={13} /> {booked ? "Reschedule" : "Schedule a time"}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}

function DocumentsGallery({ documents }) {
    if (!documents?.length) {
        return (
            <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-12 text-center">
                <Images size={28} className="mx-auto text-gray-300" />
                <p className="mt-3 text-sm text-gray-500">No documents uploaded yet.</p>
                <p className="text-[12px] text-gray-400 mt-1">Files the client uploads from their application link appear here.</p>
            </div>
        );
    }
    return (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            {documents.map((doc, k) => {
                const isImg = IMG_EXT.test(doc.label || "") || IMG_EXT.test(doc.url || "");
                return (
                    <a key={k} href={doc.url} target="_blank" rel="noreferrer"
                        className="group rounded-2xl border border-gray-100 bg-white shadow-sm overflow-hidden hover:border-[#009688] hover:shadow-md transition-all">
                        <div className="aspect-[4/3] bg-gray-50 flex items-center justify-center overflow-hidden">
                            {isImg ? (
                                <img src={doc.url} alt={doc.label} loading="lazy" className="w-full h-full object-cover group-hover:scale-[1.03] transition-transform" />
                            ) : (
                                <FileText size={34} className="text-gray-300" />
                            )}
                        </div>
                        <div className="p-3">
                            <p className="text-[12px] font-semibold text-gray-800 truncate">{doc.label}</p>
                            <p className="text-[10.5px] text-gray-400 mt-0.5 flex items-center gap-1">
                                {doc.ext || "file"} <ExternalLink size={10} className="opacity-0 group-hover:opacity-100 transition-opacity" />
                            </p>
                        </div>
                    </a>
                );
            })}
        </div>
    );
}

const money = (n) => "$" + Number(n || 0).toLocaleString("en-NZ", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Payment plan (pay-later) — staff set the deposit % + plan length; the figures
 * preview live. Once the client accepts pay-later on their application link the
 * plan activates and the weekly schedule shows here for staff to record against.
 */
function PaymentPlanCard({ plan, feeQuote, config, token }) {
    const presets = config?.presets || { 3: 13, 6: 26 };
    const dep = config?.deposit || { default: 25, min: 20, max: 30 };
    const monthsList = Object.keys(presets).map(Number).sort((a, b) => a - b);

    const [months, setMonths] = useState(plan?.plan_months || monthsList[0] || 3);
    const [pct, setPct] = useState(plan?.deposit_percent ?? dep.default);
    const [professional, setProfessional] = useState(plan?.professional_fee ?? feeQuote?.normal_professional ?? feeQuote?.normal_total ?? 0);
    const [inz, setInz] = useState(plan?.inz_fee ?? feeQuote?.inz_fee ?? 0);
    const [discountType, setDiscountType] = useState(plan?.discount_type ?? "none");
    const [discountValue, setDiscountValue] = useState(plan?.discount_value ?? 0);
    const [adminFee, setAdminFee] = useState(plan?.admin_fee ?? config?.admin_fee ?? 0);
    const [saving, setSaving] = useState(false);
    const [payingId, setPayingId] = useState(null);

    // Discount applies to the professional fee only; admin fee + INZ then added.
    const discountAmount = Math.max(0, Math.min(
        discountType === "percent" ? (Number(professional) * Number(discountValue)) / 100
            : discountType === "fixed" ? Number(discountValue) : 0,
        Number(professional),
    ));
    const total = Math.max(0, Number(professional) - discountAmount) + Number(inz) + Number(adminFee);

    const weeks = presets[months] || months * 4;
    const deposit = Math.min(Math.max((Number(total) * Number(pct)) / 100, Number(inz)), Number(total));
    const financed = Math.max(0, Number(total) - deposit);
    const weekly = weeks > 0 ? financed / weeks : 0;

    const active = plan && plan.status === "active";
    const completed = plan && plan.status === "completed";

    const save = () => {
        setSaving(true);
        router.post(`/portal/immigration/pre-screening/${token}/plan`,
            {
                professional_fee: Number(professional), inz_fee: Number(inz),
                discount_type: discountType, discount_value: Number(discountValue), admin_fee: Number(adminFee),
                plan_months: Number(months), deposit_percent: Number(pct),
            },
            { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    const payRow = (id, amount) => {
        if (!window.confirm(`Record this payment of ${money(amount)} as received?`)) return;
        setPayingId(id);
        router.post(`/portal/immigration/pre-screening/${token}/installments/${id}/pay`, {},
            { preserveScroll: true, onFinish: () => setPayingId(null) });
    };

    return (
        <section className="space-y-3">
            <div className="flex items-center gap-2">
                <Wallet size={16} className="text-[#009688]" />
                <h2 className="text-[15px] font-bold text-gray-900">Payment plan</h2>
                <span className="text-[12px] text-gray-400">· pay-later terms (deposit + weekly)</span>
            </div>

            <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
                {/* Terms — editable until the plan is active */}
                {!active && !completed && (
                    <>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <label className="block">
                                <span className="text-[11px] font-semibold text-gray-500">Professional fee</span>
                                <input type="number" min="0" step="0.01" value={professional} onChange={(e) => setProfessional(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688]" />
                            </label>
                            <label className="block">
                                <span className="text-[11px] font-semibold text-gray-500">INZ fee</span>
                                <input type="number" min="0" step="0.01" value={inz} onChange={(e) => setInz(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688]" />
                            </label>
                            <label className="block">
                                <span className="text-[11px] font-semibold text-gray-500">Admin fee</span>
                                <input type="number" min="0" step="0.01" value={adminFee} onChange={(e) => setAdminFee(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688]" />
                            </label>
                            <label className="block">
                                <span className="text-[11px] font-semibold text-gray-500">Discount</span>
                                <select value={discountType} onChange={(e) => setDiscountType(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688] bg-white">
                                    <option value="none">No discount</option>
                                    <option value="percent">Percent off</option>
                                    <option value="fixed">Fixed $ off</option>
                                </select>
                            </label>
                            <label className="block">
                                <span className="text-[11px] font-semibold text-gray-500">{discountType === "percent" ? "Discount %" : "Discount $"}</span>
                                <input type="number" min="0" step="0.01" value={discountValue} onChange={(e) => setDiscountValue(e.target.value)} disabled={discountType === "none"}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688] disabled:bg-gray-50 disabled:text-gray-400" />
                            </label>
                            <label className="block">
                                <span className="text-[11px] font-semibold text-gray-500">Plan length</span>
                                <select value={months} onChange={(e) => setMonths(Number(e.target.value))}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688] bg-white">
                                    {monthsList.map((m) => <option key={m} value={m}>{m} month{m === 1 ? "" : "s"} · {presets[m]} wks</option>)}
                                </select>
                            </label>
                            <label className="block">
                                <span className="text-[11px] font-semibold text-gray-500">Deposit % ({dep.min}–{dep.max})</span>
                                <input type="number" min={dep.min} max={dep.max} step="1" value={pct} onChange={(e) => setPct(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688]" />
                            </label>
                        </div>

                        <p className="mt-2 text-[11.5px] text-gray-400">
                            Professional <span className="font-semibold text-gray-600 tabular-nums">{money(professional)}</span>
                            {discountAmount > 0 && <> − discount <span className="font-semibold text-emerald-600 tabular-nums">{money(discountAmount)}</span></>}
                            {" + "}admin <span className="font-semibold text-gray-600 tabular-nums">{money(adminFee)}</span>
                            {" + "}INZ <span className="font-semibold text-gray-600 tabular-nums">{money(inz)}</span>
                            {" = "}<span className="font-bold text-gray-800 tabular-nums">{money(total)}</span>
                        </p>

                        {/* Live preview */}
                        <div className="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <Figure label="Deposit (incl. INZ)" value={money(deposit)} strong />
                            <Figure label="Financed" value={money(financed)} />
                            <Figure label={`Weekly × ${weeks}`} value={money(weekly)} strong />
                            <Figure label="Total" value={money(total)} />
                        </div>
                        <p className="mt-2 text-[11px] text-gray-400">Deposit is {pct}% of the total, raised to cover the INZ fee if needed. The client chooses pay-now or pay-later on their application link; pay-later activates this schedule.</p>

                        <button type="button" onClick={save} disabled={saving || !Number(total)}
                            className="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                            {saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} {plan ? "Update plan" : "Save plan"}
                        </button>
                        {plan?.status === "draft" && <span className="ml-2 text-[12px] text-amber-600">Draft saved — awaiting the client's pay-later choice.</span>}
                    </>
                )}

                {/* Active / completed — the schedule */}
                {(active || completed) && (
                    <>
                        <p className="mb-3 text-[11.5px] text-gray-400">
                            Professional <span className="font-semibold text-gray-600 tabular-nums">{money(plan.professional_fee)}</span>
                            {Number(plan.discount_amount) > 0 && <> − discount <span className="font-semibold text-emerald-600 tabular-nums">{money(plan.discount_amount)}</span></>}
                            {Number(plan.admin_fee) > 0 && <> + admin <span className="font-semibold text-gray-600 tabular-nums">{money(plan.admin_fee)}</span></>}
                            {" + "}INZ <span className="font-semibold text-gray-600 tabular-nums">{money(plan.inz_fee)}</span>
                            {" = "}<span className="font-bold text-gray-800 tabular-nums">{money(plan.total_amount)}</span>
                        </p>
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                            <Figure label="Deposit" value={money(plan.deposit_amount)} strong />
                            <Figure label={`Weekly × ${plan.weeks}`} value={money(plan.weekly_amount)} />
                            <Figure label="Paid" value={money(plan.total_paid)} strong />
                            <Figure label="Owed" value={money(plan.amount_owed)} />
                        </div>
                        <div className="overflow-x-auto rounded-xl border border-gray-100">
                            <table className="w-full text-[12.5px]">
                                <thead className="bg-gray-50 text-gray-400 text-[10px] uppercase tracking-wider">
                                    <tr>
                                        <th className="text-left px-3 py-2 font-bold">#</th>
                                        <th className="text-left px-3 py-2 font-bold">Due</th>
                                        <th className="text-right px-3 py-2 font-bold">Amount</th>
                                        <th className="text-right px-3 py-2 font-bold">Status</th>
                                        <th className="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {(plan.schedule || []).map((r) => (
                                        <tr key={r.id} className={r.status === "overdue" ? "bg-rose-50/40" : ""}>
                                            <td className="px-3 py-2 text-gray-500">{r.kind === "deposit" ? "Deposit" : r.sequence}</td>
                                            <td className="px-3 py-2 text-gray-700">{r.due_date}</td>
                                            <td className="px-3 py-2 text-right tabular-nums text-gray-900">{money(r.amount)}</td>
                                            <td className="px-3 py-2 text-right">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${
                                                    r.status === "paid" ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                                                        : r.status === "overdue" ? "bg-rose-50 text-rose-600 border-rose-200"
                                                        : "bg-gray-50 text-gray-500 border-gray-200"}`}>{r.status}</span>
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {r.status !== "paid" && (
                                                    <button type="button" onClick={() => payRow(r.id, r.amount)} disabled={payingId === r.id}
                                                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-bold border border-[#009688] text-[#009688] hover:bg-[#009688]/5 disabled:opacity-50">
                                                        {payingId === r.id ? <Loader2 size={11} className="animate-spin" /> : <Receipt size={11} />} Mark paid
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {completed && <p className="mt-3 inline-flex items-center gap-1.5 text-[13px] font-semibold text-emerald-700"><CheckCircle2 size={15} /> Fully paid.</p>}
                    </>
                )}
            </div>
        </section>
    );
}

const AGR_STATUS = {
    draft: { label: "Draft", cls: "bg-slate-100 text-slate-500 border-slate-200" },
    sent: { label: "Sent", cls: "bg-amber-50 text-amber-700 border-amber-200" },
    viewed: { label: "Viewed", cls: "bg-blue-50 text-blue-700 border-blue-200" },
    signed: { label: "Signed", cls: "bg-emerald-50 text-emerald-700 border-emerald-200" },
};

function EngagementCard({ agreement, token }) {
    const [sending, setSending] = useState(false);
    const [mode, setMode] = useState("initial");
    const [body, setBody] = useState("");
    const [title, setTitle] = useState("Engagement Agreement");
    const [composing, setComposing] = useState(!agreement);

    const st = agreement ? (AGR_STATUS[agreement.status] || AGR_STATUS.draft) : null;
    const signed = agreement?.status === "signed";

    const generate = () => {
        const payload = mode === "engagement_pack" ? { mode: "engagement_pack" } : { mode: "initial", body, title };
        setSending(true);
        router.post(`/portal/immigration/pre-screening/${token}/agreement`, payload, {
            preserveScroll: true,
            onSuccess: () => setComposing(false),
            onFinish: () => setSending(false),
        });
    };

    return (
        <section className="space-y-3">
            <div className="flex items-center gap-2">
                <FileText size={16} className="text-[#009688]" />
                <h2 className="text-[15px] font-bold text-gray-900">Engagement agreement</h2>
            </div>
            <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5 space-y-4">
                {/* Current agreement (initial path), if one exists. */}
                {agreement && (
                    <div className="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <p className="text-[13.5px] font-semibold text-gray-900">{agreement.title}</p>
                            <p className="text-[12px] text-gray-500 mt-0.5">
                                {signed ? `Signed${agreement.signer_name ? ` by ${agreement.signer_name}` : ""}` : "Sent to the client to review and sign."}
                            </p>
                            <div className="mt-1.5 flex items-center gap-1.5 flex-wrap">
                                {!signed && agreement.sign_by && <DeadlinePill iso={agreement.sign_by} overdue={agreement.sign_overdue} label="Sign by" />}
                                {signed && agreement.choose_by && <DeadlinePill iso={agreement.choose_by} overdue={agreement.choose_overdue} label="Choose payment by" />}
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${st.cls}`}>{st.label}</span>
                            <a href={`/application/${token}/agreement/pdf`} target="_blank" rel="noreferrer"
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[12px] font-semibold text-gray-700 hover:bg-gray-50"><FileText size={13} /> View</a>
                            {!composing && (
                                <button type="button" onClick={() => setComposing(true)}
                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[12px] font-semibold text-gray-700 hover:bg-gray-50">
                                    <Check size={13} /> New / replace
                                </button>
                            )}
                        </div>
                    </div>
                )}

                {/* Compose: choose Initial agreement (blank, editable) or the pack. */}
                {composing && (
                    <div className={agreement ? "pt-4 border-t border-gray-100" : ""}>
                        <div className="inline-flex rounded-lg border border-gray-200 p-0.5 mb-3">
                            {[["initial", "Initial agreement"], ["engagement_pack", "Engagement pack + invoice"]].map(([k, label]) => (
                                <button key={k} type="button" onClick={() => setMode(k)}
                                    className={`px-3 py-1.5 rounded-md text-[12px] font-semibold transition-colors ${
                                        mode === k ? "bg-[#009688] text-white" : "text-gray-600 hover:text-gray-900"}`}>
                                    {label}
                                </button>
                            ))}
                        </div>

                        {mode === "initial" ? (
                            <div className="space-y-2">
                                <label className="block">
                                    <span className="text-[11px] font-semibold text-gray-500">Title</span>
                                    <input type="text" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200}
                                        className="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] outline-none focus:border-[#009688]" />
                                </label>
                                <span className="text-[11px] font-semibold text-gray-500">Agreement text</span>
                                <RichTextEditor value={body} onChange={setBody} variant="email" />
                                <p className="text-[11px] text-gray-400">Type the agreement the client will sign on their application page. You can use {"{{first_name}}"} and other placeholders.</p>
                            </div>
                        ) : (
                            <p className="text-[12.5px] text-gray-500">
                                Generates the full engagement pack — Written Agreement, Professional Standards, Code of Conduct and Complaints Procedure — plus a tax invoice reflecting the payment plan&apos;s professional fee, discount and admin fee. The client signs via their engagement link.
                            </p>
                        )}

                        <div className="mt-3 flex items-center gap-2">
                            <button type="button" onClick={generate} disabled={sending || (mode === "initial" && !body.trim())}
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                                {sending ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Generate &amp; send
                            </button>
                            {agreement && (
                                <button type="button" onClick={() => setComposing(false)} className="px-3 py-2 rounded-lg text-[13px] font-semibold text-gray-500 hover:bg-gray-50">Cancel</button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}

function Figure({ label, value, strong }) {
    return (
        <div className="rounded-xl border border-gray-100 bg-gray-50 p-3">
            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400">{label}</p>
            <p className={`mt-1 tabular-nums ${strong ? "text-[17px] font-bold text-gray-900" : "text-[15px] font-semibold text-gray-700"}`}>{value}</p>
        </div>
    );
}

const ACTION_TONE = {
    do: { dot: "bg-[#009688]", label: "text-[#009688]" },
    wait: { dot: "bg-amber-500", label: "text-amber-600" },
    done: { dot: "bg-emerald-500", label: "text-emerald-600" },
    muted: { dot: "bg-gray-300", label: "text-gray-400" },
};

/**
 * Next action — tells the staff member exactly what to do next for this
 * pre-screening, driven by the existing pre-screen state. Replaces the flat
 * action bar with a clear primary action + secondary options. Same endpoints.
 */
function NextAction({ i }) {
    const p = i?.pre_screen;
    if (!p) return null;

    const base = `/portal/immigration/pre-screening/${p.token}`;
    const act = (path, data) => router.post(`${base}/${path}`, data || {}, { preserveScroll: true });
    const copyLink = () => {
        try { navigator.clipboard.writeText(window.location.origin + i.application_link); toast.success("Application link copied"); }
        catch { toast.error("Couldn't copy"); }
    };
    const convert = () => {
        if (window.confirm("Convert this assessment to an immigration case? A case will be created (or matched on email).")) {
            router.post(`/portal/immigration/assessments/${i.assessment_id ?? i.id}/convert-to-case`, { intake_type: i.visa_type, intake_id: i.id }, { preserveScroll: true });
        }
    };
    const skip = () => { const r = window.prompt("Reason to skip the consultation/agreement steps?"); if (r) act("skip", { reason: r }); };
    const decline = () => { const r = window.prompt("Reason for declining?"); if (r) act("decline", { reason: r }); };

    const terminal = p.status === "converted" || p.status === "declined";

    let kind = "NEXT ACTION", tone = "do", title, body, primary = null;
    if (p.status === "declined") {
        kind = "DECLINED"; tone = "muted"; title = "Pre-screening declined";
        body = p.decline_reason ? `Reason: ${p.decline_reason}` : "This pre-screening was declined.";
    } else if (p.status === "converted") {
        kind = "CONVERTED"; tone = "done"; title = "Converted to a case";
        body = "This applicant is now an immigration case.";
    } else if (p.status === "eligible") {
        kind = "ELIGIBLE"; tone = "done"; title = "Eligible — ready to convert";
        body = "Flagged as a strong potential client. Convert to an official case when ready.";
        primary = { label: "Convert to case", onClick: convert };
    } else if (p.gate_passed) {
        kind = "READY FOR ELIGIBILITY"; tone = "do"; title = "Agreement acknowledged";
        body = "The applicant can now be marked as an eligible potential client.";
        primary = { label: "Mark eligible", onClick: () => act("eligible") };
    } else if (p.consent_sent) {
        kind = "WAITING FOR CLIENT"; tone = "wait"; title = "Agreement sent";
        body = "Waiting for the client to acknowledge the agreement on their application link.";
        primary = { label: "Mark acknowledged", onClick: () => act("consent-ack"), ghost: true };
    } else {
        kind = "NEXT ACTION"; tone = "do"; title = "Send the agreement";
        body = "The agreement has not yet been sent. Send it so the client can review and acknowledge it.";
        primary = { label: "Send agreement", onClick: () => act("consent-sent") };
    }

    const t = ACTION_TONE[tone];

    return (
        <div className="mt-4 pt-4 border-t border-gray-100">
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div className="min-w-0">
                    <p className={`inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.18em] ${t.label}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${t.dot}`} /> {kind}
                    </p>
                    <p className="text-[14px] font-bold text-gray-900 mt-1">{title}</p>
                    <p className="text-[12.5px] text-gray-500 mt-0.5 max-w-xl">{body}</p>
                </div>
                {primary && (
                    <button type="button" onClick={primary.onClick}
                        className={`flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-[13px] font-semibold ${
                            primary.ghost ? "border border-gray-200 text-gray-700 hover:bg-gray-50"
                                : "bg-[#009688] text-white hover:bg-[#00796b]"}`}>
                        {tone === "done" ? <ArrowRightCircle size={15} /> : <Check size={15} />} {primary.label}
                    </button>
                )}
            </div>

            {/* Secondary options */}
            <div className="mt-3 flex items-center gap-3 flex-wrap text-[12px]">
                {i.application_link && (
                    <button type="button" onClick={copyLink} className="inline-flex items-center gap-1.5 text-gray-500 hover:text-[#009688] font-semibold">
                        <Copy size={13} /> Copy application link
                    </button>
                )}
                {!terminal && !p.gate_passed && !p.skipped && (
                    <button type="button" onClick={skip} className="text-gray-400 hover:text-gray-700 font-semibold">Skip steps</button>
                )}
                {!terminal && (
                    <button type="button" onClick={decline} className="text-gray-400 hover:text-rose-600 font-semibold">Decline</button>
                )}
            </div>
        </div>
    );
}

const RECREATE_TYPES = [
    { key: "resident", label: "Resident" },
    { key: "work", label: "Work (AEWV)" },
    { key: "student", label: "Student" },
    { key: "visitor", label: "Visitor" },
    { key: "family", label: "Family" },
];

/**
 * "Wrong visa type?" — re-create this submitted assessment as a different visa
 * type, carrying over every shared answer. Only for real submitted intakes
 * (drafts/free have no intake to copy). Procedural fix, not advice.
 */
function RecreateVisaControl({ intake }) {
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState("");
    const [saving, setSaving] = useState(false);

    // Needs a submitted intake record to copy from.
    if (intake.record !== "intake") return null;
    const options = RECREATE_TYPES.filter((t) => t.key !== intake.visa_type);

    const go = () => {
        if (!target) return;
        if (!window.confirm(`Re-create this as a ${target} assessment? Shared answers carry over; the applicant completes the visa-specific sections. The original assessment is kept.`)) return;
        setSaving(true);
        router.post(`/portal/immigration/assessments/${intake.visa_type}/${intake.id}/recreate-as`, { target }, { onFinish: () => setSaving(false) });
    };

    if (!open) {
        return (
            <button type="button" onClick={() => setOpen(true)} className="text-[11.5px] font-semibold text-gray-400 hover:text-[#009688]">
                Wrong visa type?
            </button>
        );
    }
    return (
        <span className="inline-flex items-center gap-1.5">
            <select value={target} onChange={(e) => setTarget(e.target.value)}
                className="rounded-lg border border-gray-200 px-2 py-1 text-[12px] outline-none focus:border-[#009688] bg-white">
                <option value="">Re-create as…</option>
                {options.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
            </select>
            <button type="button" onClick={go} disabled={saving || !target}
                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-bold bg-[#009688] text-white hover:bg-[#00796b] disabled:bg-gray-200 disabled:text-gray-400">
                {saving ? <Loader2 size={11} className="animate-spin" /> : null} Re-create
            </button>
            <button type="button" onClick={() => setOpen(false)} className="text-[11px] font-semibold text-gray-400 hover:underline">Cancel</button>
        </span>
    );
}

/**
 * Pre-screening potential-client profile. One place to work a Visa Assessment
 * before it becomes a case: the submitted form, the two pre-screening meetings
 * (consultation + consent), and the client's uploaded documents — with the
 * pre-screening pipeline + actions on top. Procedural, not immigration advice.
 */
export default function AssessmentProfile({ intake, bookings = {}, client_documents = [], plan = null, fee_quote = null, installment_config = null, agreement = null }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [showAssessment, setShowAssessment] = useState(false);

    const backToList = () => router.visit("/portal/immigration/assessments");

    useEffect(() => {
        const url = intake.data_url || `/portal/immigration/intakes/${intake.visa_type}/${intake.id}/data`;
        setLoading(true);
        fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => setData(d))
            .catch(() => {})
            .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const pct = data?.readiness?.pct ?? intake.readiness_pct ?? 0;
    const ring = 2 * Math.PI * 22;
    const ring2 = 2 * Math.PI * 24;
    const readiness = data?.readiness || null;
    const gaps = readiness ? Math.max(0, (readiness.total || 0) - (readiness.filled || 0)) : null;
    // Intake-attached files + the client's /application uploads, shown together.
    const docs = [...(data?.documents || []), ...client_documents];
    const status = intake.pre_screen?.status || "new";
    const st = PRE_STATUS[status] || PRE_STATUS.new;

    return (
        <>
            <Head title={`${intake?.name || "Assessment"} · Pre-screening`} />
            <div className="p-4 sm:p-6 max-w-[1440px] mx-auto space-y-4">
                {/* Profile header */}
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div className="flex items-start gap-4 flex-wrap">
                        <div className="relative flex-shrink-0" style={{ width: 52, height: 52 }}>
                            <svg width="52" height="52" viewBox="0 0 52 52" className="-rotate-90">
                                <circle cx="26" cy="26" r="22" fill="none" stroke="#e5e7eb" strokeWidth="4" />
                                <circle cx="26" cy="26" r="22" fill="none" stroke="#009688" strokeWidth="4" strokeLinecap="round"
                                    strokeDasharray={ring} strokeDashoffset={ring * (1 - pct / 100)} />
                            </svg>
                            <span className="absolute inset-0 flex items-center justify-center text-[12px] font-bold text-gray-900 tabular-nums">{pct}%</span>
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center justify-between gap-2 mb-0.5">
                                <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-[#009688]">
                                    Visa assessment · {data?.visa_label || VISA_LABEL[intake.visa_type] || intake.visa_type}
                                </p>
                                <RecreateVisaControl intake={intake} />
                            </div>
                            <div className="flex items-center gap-2.5 flex-wrap">
                                <span className={`w-8 h-8 rounded-full inline-flex items-center justify-center text-white text-[11px] font-bold ${VISA_AVATAR_BG[intake.visa_type] || "bg-gray-500"}`}>{initials(intake.name)}</span>
                                <h1 className="text-lg font-bold text-gray-900 truncate">{data?.name || intake.name}</h1>
                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${st.cls}`}>{st.label}</span>
                            </div>
                            <p className="text-[11.5px] text-gray-500 mt-1 leading-snug break-words">
                                <span className="font-mono text-gray-600">{data?.reference || intake.intake_id}</span>
                                {data?.submitted_at && <span> · Submitted {fmtDate(data.submitted_at)}</span>}
                                {(data?.email || intake.email) && <span> · {data?.email || intake.email}</span>}
                                {(data?.phone || intake.phone) && <span> · {data?.phone || intake.phone}</span>}
                                <span className="text-gray-400"> · Internal &amp; indicative — not immigration advice</span>
                            </p>
                        </div>
                        <div className="flex items-center gap-2 flex-shrink-0">
                            <a href={`/portal/immigration/intakes/${intake.visa_type}/${intake.id}/pdf`} target="_blank" rel="noreferrer"
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50"><FileText size={14} /> PDF</a>
                            <a href={`/portal/immigration/intakes/${intake.visa_type}/${intake.id}/word`}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50"><FileText size={14} /> Word</a>
                            <button type="button" onClick={backToList} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-200 text-[13px] font-semibold text-gray-700 hover:bg-gray-50"><ChevronLeft size={14} /> Back</button>
                        </div>
                    </div>

                    {/* Pipeline */}
                    <div className="mt-4 pt-4 border-t border-gray-100">
                        <Stepper status={status} />
                    </div>

                    {/* Next action — what the staff member should do now */}
                    <NextAction i={intake} />
                </div>

                {/* Visa assessment — a compact "available" card; opens the full
                    form workspace in a modal so it doesn't dominate the page. */}
                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <ClipboardList size={16} className="text-[#009688]" />
                        <h2 className="text-[15px] font-bold text-gray-900">Visa assessment</h2>
                        <span className="text-[12px] text-gray-400">· the submitted form</span>
                    </div>
                    <button type="button" onClick={() => setShowAssessment(true)}
                        className="group w-full text-left rounded-2xl border border-gray-100 bg-white shadow-sm p-5 hover:border-[#009688] hover:shadow-md transition-all">
                        <div className="flex items-center gap-4 flex-wrap">
                            {/* Completeness ring */}
                            <div className="relative flex-shrink-0" style={{ width: 56, height: 56 }}>
                                <svg width="56" height="56" viewBox="0 0 56 56" className="-rotate-90">
                                    <circle cx="28" cy="28" r="24" fill="none" stroke="#e5e7eb" strokeWidth="4" />
                                    <circle cx="28" cy="28" r="24" fill="none" stroke="#009688" strokeWidth="4" strokeLinecap="round"
                                        strokeDasharray={ring2} strokeDashoffset={ring2 * (1 - pct / 100)} />
                                </svg>
                                <span className="absolute inset-0 flex items-center justify-center text-[13px] font-bold text-gray-900 tabular-nums">{pct}%</span>
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <h3 className="text-[14px] font-bold text-gray-900">
                                        {data?.visa_label || VISA_LABEL[intake.visa_type] || intake.visa_type} assessment
                                    </h3>
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border bg-emerald-50 text-emerald-700 border-emerald-200">
                                        <ClipboardList size={11} /> Available
                                    </span>
                                </div>
                                <p className="text-[12px] text-gray-500 mt-1">
                                    {loading ? (
                                        <span className="inline-flex items-center gap-1.5"><Loader2 size={12} className="animate-spin" /> Loading the submitted form…</span>
                                    ) : readiness ? (
                                        <>
                                            <span className="font-semibold text-gray-700 tabular-nums">{readiness.filled} / {readiness.total}</span> fields answered
                                            {gaps > 0 && <> · <span className="font-semibold text-amber-600 tabular-nums">{gaps} gap{gaps === 1 ? "" : "s"}</span></>}
                                        </>
                                    ) : "Submitted form ready to review"}
                                    {data?.submitted_at && <> · Submitted {fmtDate(data.submitted_at)}</>}
                                </p>
                            </div>
                            <span className="flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold group-hover:bg-[#00796b]">
                                <Eye size={15} /> View assessment
                            </span>
                        </div>
                    </button>
                </section>

                {/* Consultations — the two pre-screening meetings. */}
                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <Calendar size={16} className="text-[#009688]" />
                        <h2 className="text-[15px] font-bold text-gray-900">Consultations</h2>
                        <span className="text-[12px] text-gray-400">· two pre-screening meetings</span>
                    </div>
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <BookingCard
                            title="Immigration consultation"
                            subtitle="Initial consultation with the adviser."
                            booking={bookings.consult}
                            token={intake.pre_screen?.token}
                            meeting="consult"
                        />
                        <BookingCard
                            title="Agreement meeting"
                            subtitle="Agreement + key-matters audio, if proceeding."
                            booking={bookings.consent}
                            token={intake.pre_screen?.token}
                            meeting="consent"
                        />
                    </div>
                </section>

                {/* Engagement agreement — generate + send; client signs on /application. */}
                {intake.pre_screen?.token && (
                    <EngagementCard agreement={agreement} token={intake.pre_screen.token} />
                )}

                {/* Payment plan — pay-later deposit + weekly schedule. */}
                {intake.pre_screen?.token && (
                    <PaymentPlanCard plan={plan} feeQuote={fee_quote} config={installment_config} token={intake.pre_screen.token} />
                )}

                {/* Documents — gallery of the client's uploads. */}
                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <Images size={16} className="text-[#009688]" />
                        <h2 className="text-[15px] font-bold text-gray-900">Documents</h2>
                        <span className="text-[12px] text-gray-400">· uploaded by the client</span>
                    </div>
                    {loading ? (
                        <div className="flex items-center justify-center gap-2 text-gray-400 text-sm py-12">
                            <Loader2 size={18} className="animate-spin" /> Loading documents…
                        </div>
                    ) : (
                        <DocumentsGallery documents={docs} />
                    )}
                </section>
            </div>

            {/* Full assessment workspace — opened from the card above. */}
            {showAssessment && (
                <IntakeViewModal
                    intake={intake}
                    data={data}
                    loading={loading}
                    onClose={() => setShowAssessment(false)}
                />
            )}
        </>
    );
}

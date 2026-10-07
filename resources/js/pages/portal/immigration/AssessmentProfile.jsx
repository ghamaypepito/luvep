import { useEffect, useState } from "react";
import { Head, router } from "@inertiajs/react";
import {
    ChevronLeft, FileText, ExternalLink, Calendar, CalendarCheck, Clock,
    Images, Loader2, Video, CheckCircle2, Circle, ClipboardList, Copy,
} from "lucide-react";
import { toast } from "sonner";
import { IntakeViewModal, PreScreenBar, PRE_STATUS } from "./Assessments";

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

// Pre-screening pipeline — the visual stages a potential client moves through.
const STEPS = [
    { key: "new", label: "New" },
    { key: "consultation", label: "Consultation" },
    { key: "consent", label: "Consent" },
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

function BookingCard({ title, subtitle, booking, applicationLink }) {
    const booked = !!booking;
    const copyLink = () => {
        try { navigator.clipboard.writeText(window.location.origin + applicationLink); toast.success("Application link copied"); }
        catch { toast.error("Couldn't copy"); }
    };
    return (
        <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
            <div className="flex items-start gap-4">
                <DateBadge iso={booking?.at} date={booking?.date} />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="text-[14px] font-bold text-gray-900">{title}</h3>
                        {booked ? (
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border bg-emerald-50 text-emerald-700 border-emerald-200">
                                <CalendarCheck size={11} /> {booking.status || "Booked"}
                            </span>
                        ) : (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border bg-gray-50 text-gray-400 border-gray-200">Not scheduled</span>
                        )}
                    </div>
                    <p className="text-[11.5px] text-gray-500 mt-0.5">{subtitle}</p>
                    {booked ? (
                        <div className="mt-3 space-y-1.5 text-[12.5px] text-gray-700">
                            <p className="flex items-center gap-1.5"><Calendar size={13} className="text-gray-400" /> {fmtDate(booking.at || booking.date) || "—"}</p>
                            {booking.time && <p className="flex items-center gap-1.5"><Clock size={13} className="text-gray-400" /> {booking.time}</p>}
                            {booking.consultant && <p className="text-[11.5px] text-gray-500">With {booking.consultant}</p>}
                            {booking.meet_link && (
                                <a href={booking.meet_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-[#009688] hover:underline">
                                    <Video size={13} /> Join meeting
                                </a>
                            )}
                        </div>
                    ) : (
                        <div className="mt-3">
                            <p className="text-[12px] text-gray-500">The client books this from their application link, or staff can schedule it in Appointments.</p>
                            {applicationLink && (
                                <button type="button" onClick={copyLink} className="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 text-[12px] font-semibold text-gray-700 hover:bg-gray-50">
                                    <Copy size={13} /> Copy application link
                                </button>
                            )}
                        </div>
                    )}
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

/**
 * Pre-screening potential-client profile. One place to work a Visa Assessment
 * before it becomes a case: the submitted form, the two pre-screening meetings
 * (consultation + consent), and the client's uploaded documents — with the
 * pre-screening pipeline + actions on top. Procedural, not immigration advice.
 */
export default function AssessmentProfile({ intake, bookings = {} }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

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
    const docs = data?.documents || [];
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
                            <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-[#009688] mb-0.5">
                                Visa assessment · {data?.visa_label || VISA_LABEL[intake.visa_type] || intake.visa_type}
                            </p>
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

                    {/* Pipeline + actions */}
                    <div className="mt-4 pt-4 border-t border-gray-100 flex items-center justify-between gap-4 flex-wrap">
                        <Stepper status={status} />
                    </div>
                    <div className="mt-2 -mx-5 -mb-5 rounded-b-2xl overflow-hidden">
                        <PreScreenBar i={intake} />
                    </div>
                </div>

                {/* Booking calendar — the two pre-screening meetings. */}
                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <Calendar size={16} className="text-[#009688]" />
                        <h2 className="text-[15px] font-bold text-gray-900">Booking calendar</h2>
                        <span className="text-[12px] text-gray-400">· two pre-screening meetings</span>
                    </div>
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <BookingCard
                            title="Meeting 1 · Immigration consultation"
                            subtitle="Initial consultation with the adviser."
                            booking={bookings.consult}
                            applicationLink={intake.application_link}
                        />
                        <BookingCard
                            title="Meeting 2 · Consent"
                            subtitle="Consent + key-matters audio, if proceeding."
                            booking={bookings.consent}
                            applicationLink={intake.application_link}
                        />
                    </div>
                </section>

                {/* Visa assessment — the full form workspace. */}
                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <ClipboardList size={16} className="text-[#009688]" />
                        <h2 className="text-[15px] font-bold text-gray-900">Visa assessment</h2>
                        <span className="text-[12px] text-gray-400">· the submitted form</span>
                    </div>
                    <IntakeViewModal intake={intake} data={data} loading={loading} asPage chromeless onClose={backToList} />
                </section>

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
        </>
    );
}

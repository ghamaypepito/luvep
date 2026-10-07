import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import {
    ShieldCheck, Calendar, CalendarCheck, Clock, Video, FileText, CheckCircle2,
    Circle, Lock, ClipboardCheck, Loader2, ArrowRight,
} from "lucide-react";

const fmtDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString("en-NZ", { weekday: "short", day: "numeric", month: "long", year: "numeric" }) : "";

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

function MeetingCard({ title, subtitle, booking, bookingUrl }) {
    const booked = !!booking;
    return (
        <div className="rounded-2xl border border-gray-100 bg-white shadow-sm p-5">
            <div className="flex items-center gap-2 flex-wrap">
                <Calendar size={16} className="text-[#009688]" />
                <h3 className="text-[14px] font-bold text-gray-900">{title}</h3>
                {booked ? (
                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border bg-emerald-50 text-emerald-700 border-emerald-200">
                        <CalendarCheck size={11} /> {booking.status || "Booked"}
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
                    {booking.consultant && <p className="text-[12px] text-gray-500">With {booking.consultant}</p>}
                    {booking.meet_link && (
                        <a href={booking.meet_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-[#009688] hover:underline">
                            <Video size={14} /> Join meeting
                        </a>
                    )}
                </div>
            ) : (
                <a href={bookingUrl} className="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-[#009688] text-white text-[13px] font-semibold hover:bg-[#00796b]">
                    Book this meeting <ArrowRight size={14} />
                </a>
            )}
        </div>
    );
}

/**
 * Private client pre-screening page (/application/{token}). The pre-screening
 * token is the credential. Shows status + process only — status of the visa
 * assessment, consultations and consent — never immigration advice.
 */
export default function ApplicationPage({ applicant, visa_label, submitted_at, pre, bookings = {}, booking_url = "/booking" }) {
    const [acking, setAcking] = useState(false);

    const ackConsent = () => {
        setAcking(true);
        router.post(`/application/${pre.token}/consent-ack`, {}, {
            preserveScroll: true,
            onFinish: () => setAcking(false),
        });
    };

    const terminal = pre.status === "converted" || pre.status === "declined";

    return (
        <div className="min-h-screen bg-gray-50 flex flex-col">
            <Head title="Your application — EP" />

            {/* Slim private header */}
            <header className="bg-white border-b border-gray-100">
                <div className="max-w-3xl mx-auto px-4 py-3.5 flex items-center justify-between">
                    <span className="text-[17px] font-extrabold tracking-tight text-gray-900">EP<span className="text-[#009688]">.</span></span>
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-400">
                        <Lock size={12} /> Private link
                    </span>
                </div>
            </header>

            <main className="flex-1 max-w-3xl w-full mx-auto px-4 py-6 space-y-5">
                {/* Status card */}
                <section className="rounded-2xl bg-white border border-gray-100 shadow-sm p-6">
                    <p className="text-[10px] font-bold uppercase tracking-[0.22em] text-[#009688] mb-1">Visa assessment · {visa_label}</p>
                    <h1 className="text-2xl font-extrabold text-gray-900">Hi {applicant.first_name || applicant.name} 👋</h1>
                    <p className="text-[13.5px] text-gray-500 mt-1">
                        This is your private application page. Track your progress and book your consultations here.
                        {submitted_at && <> You submitted your assessment on {fmtDate(submitted_at)}.</>}
                    </p>
                    <div className="mt-5">
                        <Stepper pre={pre} />
                    </div>
                    {pre.status === "declined" && (
                        <p className="mt-4 text-[13px] text-gray-500 bg-gray-50 border border-gray-100 rounded-xl p-3">
                            Our team has reviewed your assessment. {pre.decline_reason ? `Note: ${pre.decline_reason}` : "Please contact us if you'd like to discuss your options."}
                        </p>
                    )}
                    {pre.status === "eligible" && (
                        <p className="mt-4 inline-flex items-center gap-1.5 text-[13px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2">
                            <CheckCircle2 size={15} /> Great news — you're a strong candidate to proceed.
                        </p>
                    )}
                </section>

                {/* Consultations */}
                {!terminal && (
                    <section className="space-y-3">
                        <div className="flex items-center gap-2">
                            <Calendar size={16} className="text-[#009688]" />
                            <h2 className="text-[15px] font-bold text-gray-900">Your consultations</h2>
                        </div>
                        <p className="text-[12.5px] text-gray-500 -mt-1">You can book these at any time — no need to finish everything else first.</p>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <MeetingCard
                                title="Immigration consultation"
                                subtitle="Meet an adviser to talk through your situation."
                                booking={bookings.consult}
                                bookingUrl={booking_url}
                            />
                            <MeetingCard
                                title="Consent meeting"
                                subtitle="If you decide to proceed, we confirm consent together."
                                booking={bookings.consent}
                                bookingUrl={booking_url}
                            />
                        </div>
                    </section>
                )}

                {/* Consent */}
                {!terminal && (
                    <section className="rounded-2xl bg-white border border-gray-100 shadow-sm p-6">
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

                {/* Documents (view/upload coming next) */}
                <section className="rounded-2xl bg-white border border-dashed border-gray-200 p-6 text-center">
                    <FileText size={22} className="mx-auto text-gray-300" />
                    <p className="mt-2 text-[13px] font-semibold text-gray-600">Documents</p>
                    <p className="text-[12px] text-gray-400 mt-0.5">You'll be able to upload and view your documents here soon.</p>
                </section>
            </main>

            <footer className="border-t border-gray-100 bg-white">
                <div className="max-w-3xl mx-auto px-4 py-5 text-center">
                    <p className="text-[11.5px] text-gray-400">
                        This link is private to you — please don't share it. Information here describes your status and process only and is not immigration advice.
                    </p>
                </div>
            </footer>
        </div>
    );
}

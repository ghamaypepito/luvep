import { useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { ArrowLeft, Trash2, UserCog, Home, StickyNote, History, UserCheck, X, Mail, ClipboardList, Copy, Check, Download, FileSignature, Eye } from "lucide-react";
import TransitionModal from "@/components/onboarding/TransitionModal";
import ConvertTenantModal from "@/components/onboarding/ConvertTenantModal";
import StageEmailModal from "@/components/onboarding/StageEmailModal";
import PdfPreviewModal from "@/components/ui/PdfPreviewModal";
import { STATUS_STYLES, STATUS_DOT, statusLabel, tempBadge, daysStyle, STAGE_INPUTS } from "@/lib/onboardingMeta";
import { hasStageEmail } from "@/lib/stageEmails";

const fmtDate = (v) => { if (!v) return "—"; const d = new Date(v); return isNaN(d.getTime()) ? "—" : d.toLocaleDateString(); };
const fmtDateTime = (v) => { if (!v) return "—"; const d = new Date(v); return isNaN(d.getTime()) ? "—" : d.toLocaleString(); };
const displayBool = (v) => (v === true || v === 1 || v === "1" ? "Yes" : v === false || v === 0 || v === "0" ? "No" : "—");
const displayValue = (v) => (v === null || v === undefined || v === "" ? "—" : v);

function Row({ label, value }) {
    return (
        <div className="flex gap-4 py-2.5 border-b border-gray-50 last:border-0">
            <dt className="w-48 shrink-0 text-xs font-medium text-gray-400 uppercase tracking-wide pt-0.5">{label}</dt>
            <dd className="flex-1 text-sm text-gray-800 break-words">{value}</dd>
        </div>
    );
}
function Section({ title, children }) {
    return (
        <div className="rounded-3xl border border-gray-50 bg-white p-6 shadow-sm space-y-1">
            <h2 className="text-sm font-semibold text-[#1F5A8B] uppercase tracking-wide mb-3">{title}</h2>
            <dl className="divide-y divide-gray-50">{children}</dl>
        </div>
    );
}
function Panel({ title, icon, action, children }) {
    return (
        <div className="rounded-3xl border border-gray-50 bg-white p-6 shadow-sm">
            <div className="mb-3 flex items-center justify-between">
                <h2 className="inline-flex items-center gap-2 text-sm font-semibold text-gray-900">{icon}{title}</h2>
                {action}
            </div>
            {children}
        </div>
    );
}

// Staff view of the native Pre-Tenancy form: the link to send (with copy), and
// the tenant's submitted responses + document downloads once completed.
function PreTenancyPanel({ submission, onPreview }) {
    const [copied, setCopied] = useState(false);
    const d = submission.pre_tenancy_form_data;
    const url = submission.pre_tenancy_form_url;
    const copy = () => {
        if (!url) return;
        navigator.clipboard?.writeText(url).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); });
    };
    const dlBase = `/portal/accommodation/applications/${submission.id}/pre-tenancy`;

    const RowKV = ({ label, value }) => (
        <div className="flex gap-4 py-1.5">
            <dt className="w-44 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{label}</dt>
            <dd className="flex-1 text-sm text-gray-800 break-words">{value || "—"}</dd>
        </div>
    );

    return (
        <Panel title="Pre-Tenancy form" icon={<ClipboardList size={16} className="text-[#1F5A8B]" />}>
            {/* Shareable link */}
            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-gray-50/70 p-2.5">
                <input readOnly value={url || ""} className="min-w-0 flex-1 bg-transparent px-2 text-xs text-gray-600 outline-none" />
                <button onClick={copy} className="inline-flex items-center gap-1.5 rounded-lg bg-[#1F5A8B] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#184A73]">
                    {copied ? <Check size={13} /> : <Copy size={13} />} {copied ? "Copied" : "Copy link"}
                </button>
            </div>

            {!d ? (
                <p className="text-sm text-gray-400">Not submitted yet. Send the tenant the link above (it&rsquo;s also included in the pre-tenancy stage email).</p>
            ) : (
                <div className="space-y-5">
                    <div>
                        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-gray-400">Applicant</p>
                        <dl className="divide-y divide-gray-50">
                            <RowKV label="Full legal name" value={d.main?.full_legal_name} />
                            <RowKV label="ID / Licence" value={d.main?.id_number} />
                            <RowKV label="Age" value={d.main?.age} />
                            <RowKV label="Email" value={d.main?.email} />
                            <RowKV label="Mobile" value={d.main?.mobile} />
                            <RowKV label="Current address" value={d.main?.current_address} />
                        </dl>
                    </div>

                    {d.has_additional_occupants && (d.occupants || []).length > 0 && (
                        <div>
                            <p className="mb-1 text-xs font-bold uppercase tracking-wider text-gray-400">Additional subtenants</p>
                            {d.occupants.map((o, i) => (
                                <dl key={i} className="mb-2 divide-y divide-gray-50 rounded-xl border border-gray-100 p-2">
                                    <RowKV label={`Subtenant ${i + 1}`} value={o.full_name} />
                                    <RowKV label="ID / Licence" value={o.id_number} />
                                    <RowKV label="Date of birth" value={o.dob} />
                                    <RowKV label="Age" value={o.age} />
                                    <RowKV label="Relationship" value={o.relationship} />
                                    <RowKV label="Email" value={o.email} />
                                    <RowKV label="Mobile" value={o.mobile} />
                                </dl>
                            ))}
                        </div>
                    )}

                    <div>
                        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-gray-400">Bank details</p>
                        <dl className="divide-y divide-gray-50">
                            <RowKV label="Account name" value={d.bank?.account_name} />
                            <RowKV label="Account number" value={d.bank?.account_number} />
                        </dl>
                    </div>

                    <div>
                        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-gray-400">Tenancy</p>
                        <dl className="divide-y divide-gray-50">
                            <RowKV label="Property address" value={d.tenancy?.property_address} />
                            <RowKV label="Move-in date" value={d.tenancy?.move_in_date} />
                            <RowKV label="Length of stay" value={d.tenancy?.length_of_stay} />
                            <RowKV label="Room type" value={d.tenancy?.room_type} />
                            <RowKV label="Rent funding" value={d.tenancy?.rent_funding} />
                        </dl>
                    </div>

                    <div>
                        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-gray-400">Reference</p>
                        <dl className="divide-y divide-gray-50">
                            <RowKV label="Referee name" value={d.reference?.referee_name} />
                            <RowKV label="Referee phone" value={d.reference?.referee_phone} />
                            <RowKV label="Referee email" value={d.reference?.referee_email} />
                        </dl>
                    </div>

                    <div>
                        <p className="mb-1.5 text-xs font-bold uppercase tracking-wider text-gray-400">Documents</p>
                        <div className="flex flex-wrap gap-2">
                            {[["valid_id", "Valid ID"], ["visa", "Visa"]].map(([k, label]) => d.documents?.[k] && (
                                <span key={k} className="inline-flex overflow-hidden rounded-lg border border-gray-200">
                                    <button type="button" onClick={() => onPreview(`${dlBase}/${k}/download?inline=1`, label, `${dlBase}/${k}/download`)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                        <Eye size={13} /> {label}
                                    </button>
                                    <a href={`${dlBase}/${k}/download`} title="Download" className="inline-flex items-center border-l border-gray-200 px-2 py-1.5 text-gray-500 hover:bg-gray-50">
                                        <Download size={13} />
                                    </a>
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </Panel>
    );
}

// Shows the flat/house-sharing agreement generated for this client in the
// Forms → Agreements module, so the Agreement stage can source it here.
function AgreementPanel({ agreement, onPreview }) {
    const [copied, setCopied] = useState(false);
    const statusStyle = {
        sent: "bg-blue-100 text-blue-700", viewed: "bg-amber-100 text-amber-700",
        signed: "bg-emerald-100 text-emerald-700", draft: "bg-gray-100 text-gray-500", generated: "bg-gray-100 text-gray-500",
    };
    const statusText = { sent: "Sent · awaiting signature", viewed: "Viewed by client", signed: "Signed", draft: "Draft", generated: "Generated" };
    const copy = () => {
        if (!agreement?.signing_url) return;
        navigator.clipboard?.writeText(agreement.signing_url).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); });
    };
    return (
        <Panel title="Tenancy agreement" icon={<FileSignature size={16} className="text-[#1F5A8B]" />}>
            {!agreement ? (
                <p className="text-sm text-gray-400">
                    No agreement generated yet. Build one in{" "}
                    <Link href="/portal/accommodation/forms/agreements" className="font-semibold text-[#1F5A8B] hover:underline">Forms → Agreements</Link>{" "}
                    — this client is in the list — and it will be emailed for signing and appear here.
                </p>
            ) : (
                <div className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <p className="text-sm font-semibold text-gray-900">{agreement.type_label}</p>
                            <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${statusStyle[agreement.status] || "bg-gray-100 text-gray-500"}`}>{statusText[agreement.status] || agreement.status}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <button type="button" onClick={() => onPreview(`${agreement.download_url}?inline=1`, agreement.type_label, agreement.download_url)} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                <Eye size={14} /> Preview
                            </button>
                            <a href={agreement.download_url} className="inline-flex items-center gap-1.5 rounded-lg bg-[#1F5A8B] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#184A73]">
                                <Download size={14} /> {agreement.signed ? "Signed PDF" : "Download PDF"}
                            </a>
                        </div>
                    </div>
                    {!agreement.signed && agreement.signing_url && (
                        <div className="flex flex-wrap items-center gap-2">
                            <button onClick={copy} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                {copied ? <Check size={14} /> : <Copy size={14} />} {copied ? "Copied" : "Copy signing link"}
                            </button>
                            <button onClick={() => router.post(agreement.resend_url, {}, { preserveScroll: true })} className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                <Mail size={14} /> Resend email
                            </button>
                        </div>
                    )}
                </div>
            )}
        </Panel>
    );
}

export default function ApplicationDetails({ submission, options = {}, allowedTransitions = [], agreement = null,
    stageLabel, history = [], onboardingComposeUrl = null }) {
    const [modal, setModal] = useState(null);          // 'assign' | 'link' | 'note' | 'convert'
    const [transitionTo, setTransitionTo] = useState(null);
    const [emailTarget, setEmailTarget] = useState(null);  // stage with a static email preview
    const [stagePreview, setStagePreview] = useState(null); // reached stage being previewed (read-only)
    const [preview, setPreview] = useState(null);           // { url, title, downloadUrl } for the PDF/doc modal
    const openPreview = (url, title, downloadUrl) => setPreview({ url, title, downloadUrl });
    const stages = options.stages ?? [];
    const currentIdx = stages.indexOf(submission.status);
    const isTerminal = (options.terminals ?? []).includes(submission.status);
    const canConvert = submission.status === "payment_confirmed" || (submission.status === "moved_in" && !submission.converted_to_tenant_id);
    const registration = ["new", "reviewed", "declined"].includes(submission.status);
    const transitions = allowedTransitions.filter((target) =>
        !((registration && ["reviewed", "shortlisted", "declined"].includes(target)) ||
        (onboardingComposeUrl && ["viewing_email_sent", "post_viewing_followup", "pre_tenancy_form_sent"].includes(target))));
    const composeLabel = {
        shortlisted: "Approve and send viewing link",
        viewing_email_sent: "Send viewing booking link",
        viewing_completed: "Send proposal and Pre-Tenancy Form",
        post_viewing_followup: "Send proposal and Pre-Tenancy Form",
        pre_tenancy_form_sent: "Send proposal again",
        agreement_signed: "Email issued move-in invoice",
        invoice_sent: "Send move-in invoice again",
        payment_confirmed: "Compose welcome email",
        moved_in: "Compose welcome email",
    }[submission.status];
    const registrationReview = useForm({ decision: "" });
    const review = (decision) => {
        registrationReview.transform(() => ({ decision }));
        registrationReview.patch(`/portal/accommodation/applications/${submission.id}/registration-review`, { preserveScroll: true });
    };

    // When each stage was reached (for the read-only stage preview).
    const stageDates = {
        new: submission.created_at,
        viewing_email_sent: submission.viewing_email_sent_at,
        viewing_booked: submission.viewing_scheduled_at,
        viewing_completed: submission.viewing_completed_at,
        post_viewing_followup: submission.post_viewing_followup_at,
        pre_tenancy_form_sent: submission.pre_tenancy_form_sent_at,
        pre_tenancy_form_completed: submission.pre_tenancy_form_completed_at,
        agreement_sent: submission.tenancy_agreement_sent_at,
        agreement_signed: submission.tenancy_agreement_signed_at,
        invoice_sent: submission.invoice_sent_at,
        payment_confirmed: submission.payment_confirmed_at,
        moved_in: submission.move_in_date,
    };
    // Any extra captured detail worth showing for a given stage: [label, value].
    const stageDetail = (s) => {
        switch (s) {
            case "viewing_booked": return submission.viewing_scheduled_at ? ["Viewing scheduled for", fmtDateTime(submission.viewing_scheduled_at)] : null;
            case "viewing_completed": return submission.viewing_outcome ? ["Outcome", submission.viewing_outcome] : null;
            case "invoice_sent": return submission.invoice_amount_nzd ? ["Invoice amount", `NZD ${submission.invoice_amount_nzd}`] : null;
            case "declined": return submission.declined_reason ? ["Reason", submission.declined_reason] : null;
            case "not_proceeding": return submission.not_proceeding_reason ? ["Reason", submission.not_proceeding_reason] : null;
            default: return null;
        }
    };

    // The pre-tenancy stage can only be completed by the tenant actually
    // submitting the form (which auto-advances the stage). Staff can't mark it
    // done manually — the button below is locked until the submission exists.
    const preTenancyPending = (t) => t === "pre_tenancy_form_completed" && !submission.pre_tenancy_form_data;

    const go = (target) => {
        if (preTenancyPending(target)) {
            alert("Waiting for the tenant to submit their pre-tenancy form. This stage completes automatically once they submit it.");
            return;
        }
        if (target === "moved_in") { setModal("convert"); return; }
        if (hasStageEmail(target)) { setEmailTarget(target); return; }
        if (STAGE_INPUTS[target]) { setTransitionTo(target); return; }
        router.patch(`/portal/accommodation/applications/${submission.id}/status`, { status: target }, { preserveScroll: true });
    };

    const handleDelete = () => {
        if (confirm(`Delete application from "${submission.full_legal_name}"?`)) {
            router.delete(`/portal/accommodation/applications/${submission.id}`);
        }
    };

    const timeline = [
        ["Submitted", submission.created_at],
        ["Registration reviewed", submission.registration_reviewed_at],
        ["Viewing booking email sent", submission.viewing_email_sent_at],
        ["Viewing scheduled", submission.viewing_scheduled_at],
        ["Viewing completed", submission.viewing_completed_at],
        ["Post-viewing follow-up", submission.post_viewing_followup_at],
        ["Pre-tenancy form email sent", submission.pre_tenancy_form_sent_at],
        ["Pre-tenancy form completed", submission.pre_tenancy_form_completed_at],
        ["Agreement sent", submission.tenancy_agreement_sent_at],
        ["Agreement signed", submission.tenancy_agreement_signed_at],
        ["Invoice sent", submission.invoice_sent_at],
        ["Payment confirmed", submission.payment_confirmed_at],
        ["Moved in", submission.move_in_date],
        ["Welcome Email Sent", submission.welcome_email_sent_at],
    ].filter(([, v]) => v);

    const visa = submission.visa_status === "Other" && submission.visa_status_other ? `Other — ${submission.visa_status_other}` : displayValue(submission.visa_status);
    const nat = submission.nationality === "Other" && submission.nationality_other ? `Other — ${submission.nationality_other}` : displayValue(submission.nationality);

    return (
        <div className="space-y-6 max-w-5xl mx-auto">
            <Head title={`Onboarding — ${submission.full_legal_name}`} />

            <Link href="/portal/accommodation/onboarding" className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-[#1F5A8B]">
                <ArrowLeft size={15} /> Back to onboarding
            </Link>

            {/* Header */}
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Onboarding — {submission.full_legal_name}</h1>
                    <p className="mt-1 flex items-center gap-2 text-sm text-gray-500">
                        {tempBadge(submission.lead_temperature || submission.form_type) && <span>{tempBadge(submission.lead_temperature || submission.form_type)}</span>}
                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATUS_STYLES[submission.status]}`}>{stageLabel || statusLabel(submission.status)}</span>
                        <span className={daysStyle(submission.days_at_current_stage)}>{submission.days_at_current_stage ?? 0}d at stage</span>
                    </p>
                </div>
                <button onClick={handleDelete} className="inline-flex items-center gap-1.5 rounded-xl border border-rose-100 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-600 hover:bg-rose-100">
                    <Trash2 size={14} /> Delete
                </button>
            </div>

            {/* Stage stepper */}
            <div className="rounded-2xl border border-gray-100 bg-white px-4 py-3.5 shadow-sm overflow-x-auto">
                <div className="flex items-start gap-0 min-w-max">
                    {stages.map((s, i) => {
                        const done = currentIdx >= 0 && i < currentIdx;
                        const current = i === currentIdx;
                        // Reached stages (completed or current) are previewable;
                        // future stages haven't happened yet, so they stay inert.
                        const clickable = done || current;
                        return (
                            <div key={s} className="flex items-start">
                                <button
                                    type="button"
                                    disabled={!clickable}
                                    onClick={() => setStagePreview(s)}
                                    title={clickable ? "Preview this stage" : "Not reached yet"}
                                    className={`group flex w-[88px] flex-col items-center text-center ${clickable ? "cursor-pointer" : "cursor-default"}`}
                                >
                                    <span
                                        className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border transition-transform ${clickable ? "group-hover:scale-110" : ""} ${
                                            done
                                                ? "border-emerald-500 bg-emerald-500 text-white"
                                                : current
                                                    ? `border-transparent text-white ring-2 ring-gray-100 ${STATUS_DOT[s]}`
                                                    : "border-gray-200 bg-white"
                                        }`}
                                    >
                                        {done ? <Check size={12} strokeWidth={3} /> : current ? <span className="h-1.5 w-1.5 rounded-full bg-white/90" /> : <span className="h-1.5 w-1.5 rounded-full bg-gray-300" />}
                                    </span>
                                    <span className={`mt-1.5 text-[10px] leading-tight ${current ? "font-bold text-gray-900" : done ? "text-gray-500 group-hover:text-[#1F5A8B]" : "text-gray-400"}`}>{statusLabel(s)}</span>
                                </button>
                                {i < stages.length - 1 && <span className={`mt-[9px] h-0.5 w-5 rounded-full transition-colors ${done ? "bg-emerald-400" : "bg-gray-200"}`} />}
                            </div>
                        );
                    })}
                </div>
                {isTerminal && (
                    <p className="mt-3 text-sm font-semibold text-rose-600">{submission.status === "declined" ? "Not Approved" : "Not Proceeding"}</p>
                )}
            </div>

            {/* Action buttons (allowed transitions) + quick stage dropdown */}
            <div className="flex flex-wrap items-center gap-2">
                {registration && <div className="flex flex-wrap items-center gap-2">
                    {submission.status === "new" && <button type="button" disabled={registrationReview.processing} onClick={() => review("reviewed")}
                        className="rounded-full border border-gray-200 bg-white px-4 py-2 text-sm">Mark Reviewed</button>}
                    {submission.status !== "new" && <span className="text-sm font-medium text-gray-600">Reviewed</span>}
                    <button type="button" disabled={registrationReview.processing} onClick={() => review("approved")}
                        className="rounded-full bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white">Approve</button>
                    {submission.status !== "declined" && <button type="button" disabled={registrationReview.processing}
                        onClick={() => review("not_approved")} className="rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-700">Not Approved</button>}
                    {registrationReview.errors.decision && <p role="alert" className="text-xs text-rose-700">{registrationReview.errors.decision}</p>}
                </div>}
                {onboardingComposeUrl && <Link href={onboardingComposeUrl}
                    className="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700">
                    <Mail size={14} /> {composeLabel}
                </Link>}
                {transitions.length > 0 && (
                    <select
                        value=""
                        onChange={(e) => { if (e.target.value) go(e.target.value); }}
                        className="rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-[#1F5A8B]"
                    >
                        <option value="">Move to stage…</option>
                        {transitions.map((t) => (
                            <option key={t} value={t} disabled={preTenancyPending(t)}>
                                {t === "moved_in" ? "Convert to Tenant" : statusLabel(t)}{preTenancyPending(t) ? " (awaiting tenant)" : ""}
                            </option>
                        ))}
                    </select>
                )}
                {transitions.map((t) => {
                    const danger = t === "declined" || t === "not_proceeding";
                    const locked = preTenancyPending(t);
                    const label = t === "moved_in" ? "Convert to Tenant" : `→ ${statusLabel(t)}`;
                    return (
                        <button
                            key={t}
                            onClick={() => go(t)}
                            disabled={locked}
                            title={locked ? "Waiting for the tenant to submit their pre-tenancy form" : undefined}
                            className={`rounded-full px-4 py-2 text-sm font-semibold ${
                                locked
                                    ? "cursor-not-allowed border border-gray-200 bg-gray-100 text-gray-400"
                                    : danger
                                        ? "border border-rose-200 text-rose-600 hover:bg-rose-50"
                                        : "bg-[#1F5A8B] text-white hover:bg-[#184A73]"
                            }`}
                        >
                            {label}{locked ? " · awaiting tenant" : ""}
                        </button>
                    );
                })}
                {canConvert && !allowedTransitions.includes("moved_in") && (
                    <button onClick={() => setModal("convert")} className="rounded-full bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white hover:bg-[#184A73]">Convert to Tenant</button>
                )}
            </div>

            {/* Panels */}
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                <Panel title="Assigned to" icon={<UserCog size={16} className="text-[#1F5A8B]" />} action={<button onClick={() => setModal("assign")} className="text-xs font-semibold text-[#1F5A8B] hover:underline">{submission.assigned_to ? "Reassign" : "Assign"}</button>}>
                    <p className="text-sm text-gray-700">{submission.assigned_to?.name ?? "Unassigned"}</p>
                </Panel>

                <Panel title="Property" icon={<Home size={16} className="text-[#1F5A8B]" />} action={<button onClick={() => setModal("link")} className="text-xs font-semibold text-[#1F5A8B] hover:underline">{submission.property_id ? "Change" : "Link"}</button>}>
                    {submission.property_id ? (
                        <Link href={`/portal/accommodation/properties/${submission.property_id}`} className="text-sm font-medium text-[#1F5A8B] hover:underline">
                            {submission.property?.code ? `#${submission.property.code} · ` : ""}{submission.property?.address}
                        </Link>
                    ) : (
                        <p className="text-sm text-gray-500">{submission.property_interested || "Not linked"}</p>
                    )}
                </Panel>

                {submission.converted_to_tenant_id && (
                    <Panel title="Linked tenant" icon={<UserCheck size={16} className="text-[#1F5A8B]" />}>
                        <Link href={`/portal/accommodation/tenants/${submission.converted_to_tenant_id}`} className="text-sm font-medium text-emerald-700 hover:underline">
                            View tenant record →
                        </Link>
                    </Panel>
                )}

                <Panel title="Internal notes" icon={<StickyNote size={16} className="text-[#1F5A8B]" />} action={<button onClick={() => setModal("note")} className="text-xs font-semibold text-[#1F5A8B] hover:underline">Add note</button>}>
                    {submission.internal_notes ? (
                        <p className="whitespace-pre-wrap text-sm text-gray-700">{submission.internal_notes}</p>
                    ) : <p className="text-sm text-gray-400">No notes yet.</p>}
                </Panel>

                <div className="md:col-span-2">
                    <Panel title="Stage history" icon={<History size={16} className="text-[#1F5A8B]" />}>
                        {timeline.length === 0 ? <p className="text-sm text-gray-400">No stage events recorded yet.</p> : (
                            <ol className="space-y-2">
                                {timeline.map(([label, val]) => (
                                    <li key={label} className="flex items-center justify-between text-sm">
                                        <span className="text-gray-700">{label}</span>
                                        <span className="text-gray-500">{label === "Submitted" ? fmtDateTime(val) : (label === "Moved in" ? fmtDate(val) : fmtDateTime(val))}</span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Panel>
                </div>

                <div className="md:col-span-2">
                    <Panel title="Assignment and stage activity" icon={<History size={16} className="text-[#1F5A8B]" />}>
                        {history.length === 0 ? <p className="text-sm text-gray-400">No changes recorded yet.</p> : <ul className="space-y-2">
                            {history.map((event) => <li key={event.id} className="text-sm text-gray-700">
                                <strong>{event.actor || "Portal"}</strong> · {fmtDateTime(event.at)}
                                {event.assignment && <p>Assigned from {event.assignment.old} to {event.assignment.new}</p>}
                                {event.stage && <p>{statusLabel(event.stage.old)} → {statusLabel(event.stage.new)}</p>}
                            </li>)}
                        </ul>}
                    </Panel>
                </div>

                <div className="md:col-span-2">
                    <PreTenancyPanel submission={submission} onPreview={openPreview} />
                </div>

                <div className="md:col-span-2">
                    <AgreementPanel agreement={agreement} onPreview={openPreview} />
                </div>
            </div>

            {/* Read-only application sections */}
            <Section title="Personal Details">
                <Row label="Full legal name" value={displayValue(submission.full_legal_name)} />
                <Row label="ID / Licence number" value={displayValue(submission.id_number)} />
                <Row label="Visa status" value={visa} />
                <Row label="Nationality" value={nat} />
                <Row label="Preferred name" value={displayValue(submission.preferred_name)} />
                <Row label="Email" value={displayValue(submission.email)} />
                <Row label="Mobile" value={displayValue(submission.mobile)} />
                <Row label="Age" value={displayValue(submission.age)} />
            </Section>
            <Section title="Property & Room Interest">
                {submission.property_interested && <Row label="Property of interest" value={submission.property_interested} />}
                <Row label="Room type" value={displayValue(submission.room_type_interest)} />
                <Row label="Preferred start date" value={fmtDate(submission.tenancy_start_date)} />
                <Row label="Stay duration" value={displayValue(submission.stay_duration)} />
            </Section>
            <Section title="Occupancy">
                <Row label="Occupants" value={displayValue(submission.occupants)} />
                <Row label="Occupant ages" value={displayValue(submission.occupant_ages)} />
                <Row label="Children" value={displayBool(submission.has_children)} />
                <Row label="Children ages" value={displayValue(submission.children_ages)} />
                <Row label="Pets" value={displayBool(submission.has_pets)} />
                <Row label="Pet details" value={displayValue(submission.pet_details)} />
            </Section>
            <Section title="Employment / Study">
                <Row label="Rent funding" value={displayValue(submission.rent_funding)} />
                <Row label="Current status" value={displayValue(submission.employment_status)} />
            </Section>
            <Section title="Rental Background">
                <Row label="Current address" value={displayValue(submission.current_address)} />
                <Row label="Rented before" value={displayBool(submission.has_rented_before)} />
                <Row label="Time at current address" value={displayValue(submission.current_address_duration)} />
                <Row label="Living situation" value={displayValue(submission.living_situation)} />
                <Row label="Reason for moving" value={displayValue(submission.reason_for_moving)} />
            </Section>
            <Section title="Lifestyle & Compatibility">
                <Row label="Smokes / vapes" value={displayBool(submission.smokes_or_vapes)} />
                <Row label="Drinks alcohol" value={displayValue(submission.drinks_alcohol)} />
                <Row label="Work hours" value={displayValue(submission.work_hours)} />
                <Row label="Flatmate description" value={displayValue(submission.flatmate_description)} />
            </Section>
            <Section title="Viewing Availability">
                <Row label="Available within 7 days" value={displayBool(submission.viewing_available_7days)} />
                <Row label="Preferred viewing time" value={displayValue(submission.preferred_viewing_time)} />
            </Section>
            <Section title="Declaration & Consent">
                <Row label="Confirmed accurate" value={displayBool(submission.confirm_accurate)} />
                <Row label="Consent to collection" value={displayBool(submission.consent_collection)} />
            </Section>

            {/* Modals */}
            {stagePreview && (() => {
                const idx = stages.indexOf(stagePreview);
                const current = idx === currentIdx;
                const when = stageDates[stagePreview];
                const detail = stageDetail(stagePreview);
                const emailStage = hasStageEmail(stagePreview);
                return (
                    <Shell title={statusLabel(stagePreview)} onClose={() => setStagePreview(null)}>
                        <div className="space-y-4">
                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${current ? "bg-amber-100 text-amber-800" : "bg-emerald-100 text-emerald-800"}`}>
                                {current ? "Current stage" : "Completed"}
                            </span>
                            <dl className="divide-y divide-gray-50">
                                <div className="flex gap-4 py-2">
                                    <dt className="w-36 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400 pt-0.5">Reached on</dt>
                                    <dd className="flex-1 text-sm text-gray-800">{fmtDateTime(when)}</dd>
                                </div>
                                {detail && (
                                    <div className="flex gap-4 py-2">
                                        <dt className="w-36 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400 pt-0.5">{detail[0]}</dt>
                                        <dd className="flex-1 whitespace-pre-wrap text-sm text-gray-800">{detail[1]}</dd>
                                    </div>
                                )}
                            </dl>
                            {emailStage && (
                                <button
                                    onClick={() => { setStagePreview(null); setEmailTarget(stagePreview); }}
                                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#1F5A8B] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#184A73]"
                                >
                                    <Mail size={15} /> Preview the email for this stage
                                </button>
                            )}
                            <p className="text-center text-[11px] text-gray-400">Read-only preview — this doesn&rsquo;t change the application&rsquo;s stage.</p>
                        </div>
                    </Shell>
                );
            })()}
            {transitionTo && <TransitionModal submission={submission} target={transitionTo} onClose={() => setTransitionTo(null)} />}
            {modal === "convert" && <ConvertTenantModal submission={submission} properties={options.properties ?? []} contractTypes={options.contract_types ?? []} onClose={() => setModal(null)} />}
            {emailTarget && <StageEmailModal submission={submission} target={emailTarget} onClose={() => setEmailTarget(null)} />}
            <PdfPreviewModal open={!!preview} onClose={() => setPreview(null)} url={preview?.url} title={preview?.title} downloadUrl={preview?.downloadUrl} />
            {modal === "assign" && <AssignModal submission={submission} team={options.team ?? []} onClose={() => setModal(null)} />}
            {modal === "link" && <LinkModal submission={submission} properties={options.properties ?? []} onClose={() => setModal(null)} />}
            {modal === "note" && <NoteModal submission={submission} onClose={() => setModal(null)} />}
        </div>
    );
}

function Shell({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-lg font-bold text-gray-900">{title}</h3>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                </div>
                {children}
            </div>
        </div>
    );
}
const FIELD = "w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm focus:border-[#1F5A8B] focus:ring-[#1F5A8B]";

function AssignModal({ submission, team, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ user_id: submission.assigned_to_user_id ?? "" });
    const submit = (e) => { e.preventDefault(); post(`/portal/accommodation/applications/${submission.id}/assign`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Shell title="Assign application" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <select className={FIELD} value={data.user_id} onChange={(e) => setData("user_id", e.target.value)}>
                    <option value="">Unassigned</option>
                    {submission.assigned_to && !team.some((user) => user.id === submission.assigned_to.id)
                        && <option value={submission.assigned_to.id} disabled>{submission.assigned_to.name}</option>}
                    {team.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
                {errors.user_id && <p className="text-xs text-rose-600">{errors.user_id}</p>}
                <button type="submit" disabled={processing} className="w-full rounded-full bg-[#1F5A8B] px-5 py-2 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50">Assign</button>
            </form>
        </Shell>
    );
}
function LinkModal({ submission, properties, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ property_id: submission.property_id ?? "" });
    const submit = (e) => { e.preventDefault(); post(`/portal/accommodation/applications/${submission.id}/link-property`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Shell title="Link to property" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <select className={FIELD} value={data.property_id} onChange={(e) => setData("property_id", e.target.value)}>
                    <option value="">Select a property</option>
                    {properties.map((p) => <option key={p.id} value={p.id}>{p.code ? `#${p.code} · ` : ""}{p.address}</option>)}
                </select>
                {errors.property_id && <p className="text-xs text-rose-600">{errors.property_id}</p>}
                <button type="submit" disabled={processing} className="w-full rounded-full bg-[#1F5A8B] px-5 py-2 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50">Link</button>
            </form>
        </Shell>
    );
}
function NoteModal({ submission, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ note: "" });
    const submit = (e) => { e.preventDefault(); post(`/portal/accommodation/applications/${submission.id}/note`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Shell title="Add internal note" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <textarea rows={4} className={FIELD} value={data.note} onChange={(e) => setData("note", e.target.value)} placeholder="Internal note…" />
                {errors.note && <p className="text-xs text-rose-600">{errors.note}</p>}
                <button type="submit" disabled={processing} className="w-full rounded-full bg-[#1F5A8B] px-5 py-2 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50">Add note</button>
            </form>
        </Shell>
    );
}

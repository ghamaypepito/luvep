import React, { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import { ArrowLeft, Download, FileText, Eye } from "lucide-react";
import PdfPreviewModal from "@/components/ui/PdfPreviewModal";

// Staff view of one standalone/general pre-tenancy submission.
export default function PreTenancyFormDetail({ form }) {
    const d = form.data || {};
    const [docPreview, setDocPreview] = useState(null);
    const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : "—");

    const RowKV = ({ label, value }) => (
        <div className="flex gap-4 py-1.5">
            <dt className="w-44 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{label}</dt>
            <dd className="flex-1 text-sm text-gray-800 break-words">{value || "—"}</dd>
        </div>
    );

    const Block = ({ title, children }) => (
        <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">{title}</p>
            <dl className="divide-y divide-gray-50">{children}</dl>
        </div>
    );

    const dlBase = `/portal/accommodation/forms/pre-tenancy/${form.id}`;

    return (
        <div className="space-y-5 max-w-3xl mx-auto">
            <Head title={`Pre-Tenancy — ${form.name || form.reference}`} />

            <Link href="/portal/accommodation/forms/pre-tenancy" className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-[#1F5A8B]">
                <ArrowLeft size={15} /> Back to Pre-Tenancy forms
            </Link>

            <div>
                <h1 className="text-2xl font-bold text-gray-900">{form.name || "Unnamed applicant"}</h1>
                <p className="mt-1 text-sm text-gray-500">
                    <span className="font-mono text-xs">{form.reference}</span> · Submitted {fmt(form.submitted_at)}
                </p>
            </div>

            <Block title="Applicant">
                <RowKV label="Full legal name" value={d.main?.full_legal_name} />
                <RowKV label="ID / Licence" value={d.main?.id_number} />
                <RowKV label="Age" value={d.main?.age} />
                <RowKV label="Email" value={d.main?.email} />
                <RowKV label="Mobile" value={d.main?.mobile} />
                <RowKV label="Current address" value={d.main?.current_address} />
                <RowKV label="Visa type" value={d.main?.visa_type} />
            </Block>

            {d.has_additional_occupants && (d.occupants || []).length > 0 && (
                <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Additional subtenants</p>
                    {d.occupants.map((o, i) => (
                        <dl key={i} className="mb-2 divide-y divide-gray-50 rounded-xl border border-gray-100 p-2">
                            <RowKV label={`Subtenant ${i + 1}`} value={o.full_name} />
                            <RowKV label="ID / Licence" value={o.id_number} />
                            <RowKV label="Date of birth" value={o.dob} />
                            <RowKV label="Relationship" value={o.relationship} />
                            <RowKV label="Email" value={o.email} />
                            <RowKV label="Mobile" value={o.mobile} />
                            <RowKV label="Visa type" value={o.visa_type} />
                        </dl>
                    ))}
                </div>
            )}

            <Block title="Bank details">
                <RowKV label="Account name" value={d.bank?.account_name} />
                <RowKV label="Account number" value={d.bank?.account_number} />
            </Block>

            <Block title="Tenancy">
                <RowKV label="Property address" value={d.tenancy?.property_address} />
                <RowKV label="Move-in date" value={d.tenancy?.move_in_date} />
                <RowKV label="Length of stay" value={d.tenancy?.length_of_stay} />
                <RowKV label="Room type" value={d.tenancy?.room_type} />
                <RowKV label="Rent funding" value={d.tenancy?.rent_funding} />
            </Block>

            <Block title="Reference">
                <RowKV label="Referee name" value={d.reference?.referee_name} />
                <RowKV label="Referee phone" value={d.reference?.referee_phone} />
                <RowKV label="Referee email" value={d.reference?.referee_email} />
            </Block>

            {d.documents && (
                <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <p className="mb-2 text-xs font-bold uppercase tracking-wider text-gray-400">Documents</p>
                    <div className="flex flex-wrap gap-2">
                        {["valid_id", "visa"].map((k) => d.documents[k] ? (
                            <span key={k} className="inline-flex overflow-hidden rounded-lg border border-gray-200 bg-white">
                                <button type="button" onClick={() => setDocPreview({ url: `${dlBase}/${k}/download?inline=1`, title: k === "valid_id" ? "Valid ID" : "Visa", downloadUrl: `${dlBase}/${k}/download` })} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                    <Eye size={14} /> {k === "valid_id" ? "Valid ID" : "Visa"}
                                </button>
                                <a href={`${dlBase}/${k}/download`} title="Download" className="inline-flex items-center border-l border-gray-200 px-2.5 py-1.5 text-gray-500 hover:bg-gray-50">
                                    <Download size={14} />
                                </a>
                            </span>
                        ) : null)}
                    </div>
                </div>
            )}

            <PdfPreviewModal open={!!docPreview} onClose={() => setDocPreview(null)} url={docPreview?.url} title={docPreview?.title} downloadUrl={docPreview?.downloadUrl} />
        </div>
    );
}

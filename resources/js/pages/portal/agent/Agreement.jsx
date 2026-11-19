import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { FileSignature, Download, Eye, FileText, Clock, PenTool, CheckCircle2, Landmark, Save, Loader2 } from "lucide-react";
import AgreementSignModal from "@/components/AgreementSignModal";
import PdfViewerModal from "@/components/PdfViewerModal";

// Agent-facing view of their own Affiliate Partner Agreement. After staff
// generate it, the agent fills their own Schedule B (bank + contact details)
// and then e-signs it.
export default function AgentAgreement({ agreement = null, agreements = [], affiliateFields = [], affiliateValues = {} }) {
    const [signOpen, setSignOpen] = useState(false);
    const [viewOpen, setViewOpen] = useState(false);

    const fmtDate = (iso) => iso
        ? new Date(iso).toLocaleString("en-NZ", { day: "2-digit", month: "short", year: "numeric" })
        : "—";
    const fmtSize = (b) => (! b ? "—" : b < 1024 * 1024 ? `${(b / 1024).toFixed(1)} KB` : `${(b / 1024 / 1024).toFixed(2)} MB`);

    return (
        <div className="space-y-6 max-w-3xl">
            <Head title="My Affiliate Agreement" />

            <div>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">My Affiliate Agreement</h1>
                <p className="text-sm text-gray-500 mt-1">Your Affiliate Partner Agreement with EP.</p>
            </div>

            {agreement ? (
                <>
                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                        <div className="flex items-center gap-4 flex-wrap">
                            <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
                                <FileSignature size={22} />
                            </div>
                            <div className="min-w-0 flex-1">
                                <h2 className="text-sm font-bold text-gray-900">Affiliate Partner Agreement</h2>
                                <p className="text-[12px] text-gray-500 mt-0.5">Prepared {fmtDate(agreement.created_at)} · {fmtSize(agreement.size)}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <button type="button" onClick={() => setViewOpen(true)} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                                    <Eye size={14} /> View
                                </button>
                                <a href={agreement.download_url} className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-200 bg-white text-gray-700 text-[12px] font-semibold hover:bg-gray-50">
                                    <Download size={14} /> Download
                                </a>
                                {! agreement.signed && agreement.can_sign && (
                                    <button type="button" onClick={() => setSignOpen(true)} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold hover:bg-black transition-colors">
                                        <PenTool size={14} /> Sign agreement
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="mt-5 pt-4 border-t border-gray-100">
                            {agreement.signed ? (
                                <div className="flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
                                    <CheckCircle2 size={18} className="text-emerald-600 mt-0.5 shrink-0" />
                                    <div className="text-[13px] text-emerald-900">
                                        <p className="font-bold">Signed by {agreement.signer_name}</p>
                                        <p className="text-[12px] text-emerald-700 mt-0.5">on {fmtDate(agreement.signed_at)} · your signature is embedded in the document above.</p>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex items-start gap-2 text-[12px] text-gray-500">
                                    <FileText size={14} className="mt-0.5 shrink-0 text-gray-400" />
                                    <span>Your turn: fill in your bank &amp; contact details below, then sign to complete your partnership with EP.</span>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Schedule B — the agent fills their own bank + contact details. */}
                    {agreement.can_edit && <DetailsCard key={agreement.id} agreementId={agreement.id} fields={affiliateFields} values={affiliateValues} />}
                    {!agreement.can_edit && <p className="text-sm text-gray-600">{agreement.legacy ? "This existing agreement is preserved as issued. Ask EP to create a new version for further signing." : "This agreement’s details are locked. Corrections require a new version."}</p>}
                </>
            ) : (
                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-10 text-center">
                    <div className="w-14 h-14 rounded-2xl bg-gray-50 border border-gray-100 flex items-center justify-center mx-auto mb-3">
                        <Clock size={24} className="text-gray-400" />
                    </div>
                    <p className="text-sm font-bold text-gray-800">No agreement yet</p>
                    <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                        Your Affiliate Partner Agreement hasn&rsquo;t been prepared yet. The EP team will generate it and it will appear here for you to complete and sign.
                    </p>
                </div>
            )}

            {agreements.length > 0 && (
                <section className="rounded-2xl border border-gray-100 bg-white p-6">
                    <h2 className="text-lg font-semibold mb-4">Agreement history</h2>
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm">
                        <thead><tr className="text-gray-500"><th className="py-2">Version</th><th>Status</th><th>Prepared</th><th>Signed</th><th>Expires</th><th>Documents</th></tr></thead>
                        <tbody>{agreements.map(version => <tr key={version.id} className="border-t border-gray-100">
                            <td className="py-3">{version.version}</td><td>{version.status}</td><td>{fmtDate(version.created_at)}</td><td>{fmtDate(version.agent_signed_at)}</td><td>{version.expires_on || "—"}</td>
                            <td><a className="text-emerald-800 underline mr-3" href={version.view_url} target="_blank" rel="noreferrer">View</a><a className="text-emerald-800 underline" href={version.download_url}>Download</a></td>
                        </tr>)}</tbody>
                    </table></div>
                </section>
            )}

            {signOpen && agreement && (
                <AgreementSignModal
                    postUrl={agreement.sign_url}
                    title="Sign your agreement"
                    requireTerms
                    reviewUrl={agreement.view_url}
                    extraFields={agreement.can_edit ? affiliateFields : []}
                    extraInitial={affiliateValues}
                    extraTitle="Your bank account & contact details"
                    previewUrl={agreement.preview_url}
                    onClose={() => setSignOpen(false)}
                />
            )}

            {viewOpen && agreement && (
                <PdfViewerModal
                    url={agreement.view_url}
                    title="Affiliate Partner Agreement"
                    downloadUrl={agreement.download_url}
                    onClose={() => setViewOpen(false)}
                />
            )}
        </div>
    );
}

// ── Schedule B — bank account & contact details, filled by the agent. ──
function DetailsCard({ agreementId, fields, values }) {
    const [form, setForm] = useState(() => {
        const seed = {};
        fields.forEach((f) => { seed[f.key] = values[f.key] ?? ""; });
        return seed;
    });
    const [saving, setSaving] = useState(false);

    const set = (key, val) => setForm((f) => ({ ...f, [key]: val }));
    const save = () => {
        setSaving(true);
        router.post(`/portal/agent/agreement/details?agreement=${agreementId}`, form, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <div className="flex items-center gap-3 mb-1">
                <div className="w-10 h-10 rounded-lg bg-indigo-50 text-indigo-700 flex items-center justify-center">
                    <Landmark size={18} />
                </div>
                <div>
                    <h2 className="text-sm font-bold text-gray-900">Your bank &amp; contact details</h2>
                    <p className="text-[12px] text-gray-500 mt-0.5">Schedule B — where your commission is paid. Saved into your agreement.</p>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                {fields.map((f) => (
                    <label key={f.key} className={`block ${f.type === "textarea" ? "sm:col-span-2" : ""}`}>
                        <span className="block text-[11px] font-semibold text-gray-600 mb-1">{f.label}</span>
                        {f.type === "textarea" ? (
                            <textarea
                                rows={2}
                                value={form[f.key] ?? ""}
                                onChange={(e) => set(f.key, e.target.value)}
                                placeholder={f.placeholder}
                                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                            />
                        ) : (
                            <input
                                type="text"
                                value={form[f.key] ?? ""}
                                onChange={(e) => set(f.key, e.target.value)}
                                placeholder={f.placeholder}
                                maxLength={500}
                                className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:border-gray-400"
                            />
                        )}
                    </label>
                ))}
            </div>

            <div className="flex justify-end mt-4">
                <button
                    type="button"
                    onClick={save}
                    disabled={saving}
                    className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-900 text-white text-[12px] font-bold hover:bg-black disabled:opacity-50 transition-colors"
                >
                    {saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} Save details
                </button>
            </div>
        </div>
    );
}

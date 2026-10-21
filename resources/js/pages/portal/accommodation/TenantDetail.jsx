import { useState } from "react";
import { Head, Link, useForm, router } from "@inertiajs/react";
import {
    ArrowLeft, Pencil, Users, FileText, Wallet, IdCard, ClipboardCheck,
    MessageSquare, Activity, Receipt, X, LogOut, RefreshCw, MoveRight, BellRing,
} from "lucide-react";

const STATUS_STYLES = {
    active: "bg-emerald-50 text-emerald-700", notice_given: "bg-amber-50 text-amber-700",
    vacating: "bg-orange-50 text-orange-700", vacated: "bg-gray-100 text-gray-500",
    breached: "bg-rose-50 text-rose-600",
};
const STATUS_LABEL = {
    active: "Active", notice_given: "Notice given", vacating: "Vacating", vacated: "Vacated", breached: "Breached",
};
const CONTRACT_STATUS_LABEL = {
    open: "Open-ended", no_dates: "No dates on file", ended: "Ended", ending_soon: "Ending soon", active: "Active",
};
const fmtDate = (v) => (v ? String(v).slice(0, 10) : "—");
const money = (v) => (v == null || v === "" ? "—" : `$${Number(v).toLocaleString("en-NZ", { minimumFractionDigits: 2 })}`);
const show = (v) => (v == null || v === "" ? "—" : v);

function Row({ label, value }) {
    return (
        <div className="flex justify-between gap-4 py-2 border-b border-gray-50 last:border-0">
            <span className="text-sm text-gray-500">{label}</span>
            <span className="text-sm font-medium text-gray-900 text-right">{value}</span>
        </div>
    );
}
function Card({ title, icon, children }) {
    return (
        <div className="rounded-3xl border border-gray-50 bg-white p-6 shadow-sm">
            <div className="mb-3 flex items-center gap-2">{icon}<h2 className="font-semibold text-gray-900">{title}</h2></div>
            {children}
        </div>
    );
}

function Modal({ title, onClose, children }) {
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
const LABEL = "block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5";

export default function TenantDetail({ tenant, historical = [], properties = [], documents = [] }) {
    const [tab, setTab] = useState("overview");
    const [modal, setModal] = useState(null); // notice | vacate | renew | move
    const isVacated = tenant.current_status === "vacated";

    const tabs = [
        { key: "overview", label: "Overview", icon: <Users size={16} /> },
        { key: "rent", label: "Rent & Payments", icon: <Receipt size={16} /> },
        { key: "comms", label: "Communication", icon: <MessageSquare size={16} /> },
        { key: "docs", label: "Documents", icon: <ClipboardCheck size={16} /> },
        { key: "activity", label: "Activity", icon: <Activity size={16} /> },
    ];

    // Inline document toggles — PUT the minimal required fields plus all three docs.
    const toggleDoc = (field, value) => {
        router.put(`/portal/accommodation/tenants/${tenant.id}`, {
            property_id: tenant.property_id,
            first_name: tenant.first_name,
            family_name: tenant.family_name,
            contract_type: tenant.contract_type,
            has_passport_in_drive: tenant.has_passport_in_drive,
            has_tenancy_agreement_in_drive: tenant.has_tenancy_agreement_in_drive,
            has_inspection_report_in_drive: tenant.has_inspection_report_in_drive,
            [field]: value,
        }, { preserveScroll: true });
    };

    return (
        <div className="max-w-5xl mx-auto space-y-6">
            <Head title={tenant.display_name} />

            <Link href="/portal/accommodation/tenants" className="inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-900">
                <ArrowLeft size={16} /> Back to tenants
            </Link>

            {/* Header */}
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-2xl font-bold text-gray-900">{tenant.display_name}</h1>
                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATUS_STYLES[tenant.current_status]}`}>{STATUS_LABEL[tenant.current_status]}</span>
                    </div>
                    {tenant.property && (
                        <Link href={`/portal/accommodation/properties/${tenant.property_id}`} className="mt-1 text-sm text-gray-500 hover:text-[#1F5A8B]">
                            {tenant.property.code ? `#${tenant.property.code} · ` : ""}{tenant.property.address ?? tenant.property.name}{tenant.unit ? ` · Unit ${tenant.unit}` : ""}
                        </Link>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={`/portal/accommodation/tenants/${tenant.id}/edit`} className="inline-flex items-center gap-2 rounded-full bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white hover:bg-[#184A73]"><Pencil size={15} /> Edit</Link>
                    {!isVacated && <>
                        <button onClick={() => setModal("notice")} className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"><BellRing size={15} /> Notice</button>
                        <button onClick={() => setModal("renew")} className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"><RefreshCw size={15} /> Renew</button>
                        <button onClick={() => setModal("move")} className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"><MoveRight size={15} /> Move</button>
                        <button onClick={() => setModal("vacate")} className="inline-flex items-center gap-2 rounded-full border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50"><LogOut size={15} /> Vacate</button>
                    </>}
                </div>
            </div>

            {/* Tabs */}
            <div className="flex gap-1 border-b border-gray-100 overflow-x-auto">
                {tabs.map((t) => (
                    <button key={t.key} onClick={() => setTab(t.key)} className={`flex items-center gap-2 whitespace-nowrap px-4 py-3 text-sm font-semibold border-b-2 -mb-px ${tab === t.key ? "border-[#1F5A8B] text-[#1F5A8B]" : "border-transparent text-gray-500 hover:text-gray-900"}`}>
                        {t.icon} {t.label}
                    </button>
                ))}
            </div>

            {tab === "overview" && (
                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Card title="Personal info" icon={<Users size={18} className="text-[#1F5A8B]" />}>
                        <Row label="Name" value={show(tenant.display_name)} />
                        <Row label="Email" value={show(tenant.email)} />
                        <Row label="Phone" value={show(tenant.phone)} />
                        <Row label="WhatsApp" value={show(tenant.whatsapp)} />
                        <Row label="Date of birth" value={fmtDate(tenant.date_of_birth)} />
                        <Row label="Nationality" value={show(tenant.nationality)} />
                    </Card>

                    <Card title="Contract" icon={<FileText size={18} className="text-[#1F5A8B]" />}>
                        <Row label="Type" value={show(tenant.contract_type?.replace(/_/g, " "))} />
                        <Row label="Start" value={fmtDate(tenant.contract_start)} />
                        <Row label="End" value={fmtDate(tenant.contract_end)} />
                        <Row label="Days to end" value={tenant.days_to_end ?? "—"} />
                        <Row label="Status" value={CONTRACT_STATUS_LABEL[tenant.contract_status] ?? tenant.contract_status} />
                    </Card>

                    <Card title="Financial" icon={<Wallet size={18} className="text-[#1F5A8B]" />}>
                        <Row label="Weekly rent" value={money(tenant.weekly_rent_nzd)} />
                        <Row label="Weekly utilities" value={money(tenant.weekly_utilities_nzd)} />
                        <Row label="Total weekly due" value={money(tenant.weekly_total_due)} />
                        <Row label="Bond paid" value={money(tenant.bond_paid_nzd)} />
                        <Row label="Advance paid" value={money(tenant.advance_paid_nzd)} />
                    </Card>

                    <Card title="Documents" icon={<IdCard size={18} className="text-[#1F5A8B]" />}>
                        {[
                            ["has_passport_in_drive", "Passport in Drive"],
                            ["has_tenancy_agreement_in_drive", "Tenancy Agreement in Drive"],
                            ["has_inspection_report_in_drive", "Inspection Report in Drive"],
                        ].map(([field, label]) => (
                            <label key={field} className="flex items-center justify-between gap-4 py-2 border-b border-gray-50 last:border-0">
                                <span className="text-sm text-gray-600">{label}</span>
                                <input type="checkbox" checked={tenant[field]} onChange={(e) => toggleDoc(field, e.target.checked)} className="h-4 w-4 rounded border-gray-300 text-[#1F5A8B] focus:ring-[#1F5A8B]" />
                            </label>
                        ))}
                    </Card>

                    {tenant.notes && (
                        <div className="md:col-span-2">
                            <Card title="Notes" icon={<FileText size={18} className="text-[#1F5A8B]" />}>
                                <p className="whitespace-pre-wrap text-sm text-gray-700">{tenant.notes}</p>
                            </Card>
                        </div>
                    )}

                    {historical.length > 0 && (
                        <div className="md:col-span-2">
                            <Card title={`Other (past) tenants at this property — ${historical.length}`} icon={<Users size={18} className="text-[#1F5A8B]" />}>
                                <div className="divide-y divide-gray-50">
                                    {historical.map((h) => (
                                        <Link key={h.id} href={`/portal/accommodation/tenants/${h.id}`} className="flex items-center justify-between py-2 text-sm hover:text-[#1F5A8B]">
                                            <span className="font-medium text-gray-800">{h.display_name}</span>
                                            <span className="text-xs text-gray-500">ended {fmtDate(h.ended_at)}</span>
                                        </Link>
                                    ))}
                                </div>
                            </Card>
                        </div>
                    )}
                </div>
            )}

            {tab === "rent" && <Placeholder icon={<Receipt size={40} />} title="Rent payments coming soon" text="Rent payment history will appear here once the Rent & Utilities module is built." />}
            {tab === "comms" && <Placeholder icon={<MessageSquare size={40} />} title="Communication log coming soon" text="Email and message log will appear here once the Communication module is built." />}
            {tab === "docs" && <TenantDocuments tenant={tenant} documents={documents} />}
            {tab === "activity" && <Placeholder icon={<Activity size={40} />} title="Activity log coming soon" text="Status changes, contract updates and notes will be shown here once the audit presentation layer is built." />}

            {modal === "notice" && <NoticeModal tenant={tenant} onClose={() => setModal(null)} />}
            {modal === "vacate" && <VacateModal tenant={tenant} onClose={() => setModal(null)} />}
            {modal === "renew" && <RenewModal tenant={tenant} onClose={() => setModal(null)} />}
            {modal === "move" && <MoveModal tenant={tenant} properties={properties} onClose={() => setModal(null)} />}
        </div>
    );
}

function Placeholder({ icon, title, text }) {
    return (
        <div className="rounded-3xl border border-dashed border-gray-200 bg-white p-16 text-center">
            <div className="mx-auto mb-3 flex justify-center text-gray-300">{icon}</div>
            <p className="font-semibold text-gray-900">{title}</p>
            <p className="mx-auto mt-1 max-w-md text-sm text-gray-500">{text}</p>
        </div>
    );
}

function NoticeModal({ tenant, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ reason: "" });
    const submit = (e) => { e.preventDefault(); post(`/portal/accommodation/tenants/${tenant.id}/notice`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal title="Mark notice given" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className={LABEL}>Reason</label>
                    <textarea rows={3} className={FIELD} value={data.reason} onChange={(e) => setData("reason", e.target.value)} placeholder="Reason for notice…" />
                    {errors.reason && <p className="mt-1 text-xs text-rose-600">{errors.reason}</p>}
                </div>
                <ModalActions processing={processing} onClose={onClose} label="Mark notice given" />
            </form>
        </Modal>
    );
}

function VacateModal({ tenant, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ vacate_date: "", reason: "" });
    const submit = (e) => { e.preventDefault(); post(`/portal/accommodation/tenants/${tenant.id}/vacate`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal title="Mark vacated" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className={LABEL}>Vacate date</label>
                    <input type="date" className={FIELD} value={data.vacate_date} onChange={(e) => setData("vacate_date", e.target.value)} />
                    {errors.vacate_date && <p className="mt-1 text-xs text-rose-600">{errors.vacate_date}</p>}
                </div>
                <div>
                    <label className={LABEL}>Reason (optional)</label>
                    <textarea rows={2} className={FIELD} value={data.reason} onChange={(e) => setData("reason", e.target.value)} />
                </div>
                <ModalActions processing={processing} onClose={onClose} label="Mark vacated" danger />
            </form>
        </Modal>
    );
}

function RenewModal({ tenant, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ new_contract_start: "", new_contract_end: "" });
    const submit = (e) => { e.preventDefault(); post(`/portal/accommodation/tenants/${tenant.id}/renew`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal title="Renew tenancy" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className={LABEL}>New contract start</label>
                    <input type="date" className={FIELD} value={data.new_contract_start} onChange={(e) => setData("new_contract_start", e.target.value)} />
                    {errors.new_contract_start && <p className="mt-1 text-xs text-rose-600">{errors.new_contract_start}</p>}
                </div>
                <div>
                    <label className={LABEL}>New contract end</label>
                    <input type="date" className={FIELD} value={data.new_contract_end} onChange={(e) => setData("new_contract_end", e.target.value)} />
                    {errors.new_contract_end && <p className="mt-1 text-xs text-rose-600">{errors.new_contract_end}</p>}
                </div>
                <ModalActions processing={processing} onClose={onClose} label="Renew" />
            </form>
        </Modal>
    );
}

function MoveModal({ tenant, properties, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ new_property_id: "", move_date: "" });
    const submit = (e) => { e.preventDefault(); post(`/portal/accommodation/tenants/${tenant.id}/move`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal title="Move to another property" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className={LABEL}>Destination property</label>
                    <select className={FIELD} value={data.new_property_id} onChange={(e) => setData("new_property_id", e.target.value)}>
                        <option value="">Select a property</option>
                        {properties.filter((p) => p.id !== tenant.property_id).map((p) => (
                            <option key={p.id} value={p.id}>{p.code ? `#${p.code} · ` : ""}{p.address}</option>
                        ))}
                    </select>
                    {errors.new_property_id && <p className="mt-1 text-xs text-rose-600">{errors.new_property_id}</p>}
                </div>
                <div>
                    <label className={LABEL}>Move date</label>
                    <input type="date" className={FIELD} value={data.move_date} onChange={(e) => setData("move_date", e.target.value)} />
                    {errors.move_date && <p className="mt-1 text-xs text-rose-600">{errors.move_date}</p>}
                </div>
                <p className="text-xs text-gray-500">This closes the current tenancy (vacated) and opens a fresh one at the destination with blank contract dates.</p>
                <ModalActions processing={processing} onClose={onClose} label="Move tenant" />
            </form>
        </Modal>
    );
}

function ModalActions({ processing, onClose, label, danger = false }) {
    return (
        <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="rounded-full px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100">Cancel</button>
            <button type="submit" disabled={processing} className={`rounded-full px-5 py-2 text-sm font-semibold text-white disabled:opacity-50 ${danger ? "bg-rose-600 hover:bg-rose-700" : "bg-[#1F5A8B] hover:bg-[#184A73]"}`}>
                {processing ? "Saving…" : label}
            </button>
        </div>
    );
}


function TenantDocuments({ tenant, documents }) {
    const form = useForm({ category: "passport", file: null });
    const [fileKey, setFileKey] = useState(0);
    const categories = { passport: "Passport / ID", tenancy_agreement: "Tenancy agreement", inspection_report: "Inspection report", other: "Other" };
    const upload = (event) => {
        event.preventDefault();
        form.post(`/portal/accommodation/tenants/${tenant.id}/documents`, {
            forceFormData: true, preserveScroll: true,
            onSuccess: () => { form.reset("file"); setFileKey((key) => key + 1); },
        });
    };
    return (
        <Card title="Tenant documents" icon={<ClipboardCheck size={18} />}>
            <p className="mb-4 text-sm text-gray-500">Upload files for this tenancy. Existing Google Drive status toggles remain separate from these uploads.</p>
            <form onSubmit={upload} className="mb-6 space-y-3">
                <label className={LABEL} htmlFor="tenant-doc-category">Document type</label>
                <select id="tenant-doc-category" className={FIELD} value={form.data.category} onChange={(event) => form.setData("category", event.target.value)}>
                    {Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                </select>
                {form.errors.category && <p role="alert" className="text-sm text-red-600">{form.errors.category}</p>}
                <label className={LABEL} htmlFor="tenant-doc-file">File (PDF, JPG, PNG, Word; up to 10 MB)</label>
                <input key={fileKey} id="tenant-doc-file" type="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" className={FIELD} onChange={(event) => form.setData("file", event.target.files?.[0] ?? null)} />
                {form.errors.file && <p role="alert" className="text-sm text-red-600">{form.errors.file}</p>}
                {form.progress && <p className="text-sm text-gray-500">Uploading {form.progress.percentage}%</p>}
                <button type="submit" disabled={form.processing || !form.data.file} className="rounded-xl bg-[#1F5A8B] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? "Uploading…" : "Upload document"}</button>
            </form>
            {documents.length === 0 ? <p className="text-sm text-gray-500">No documents uploaded for this tenancy yet.</p> : (
                <ul className="divide-y divide-gray-100">
                    {documents.map((document) => (
                        <li key={document.id} className="flex items-center justify-between gap-4 py-3">
                            <div className="min-w-0"><p className="break-words text-sm font-medium text-gray-900">{document.name}</p><p className="text-xs text-gray-500">{categories[document.category]} · {Math.ceil(document.size / 1024)} KB · {fmtDate(document.created_at)}</p></div>
                            <a href={`/portal/accommodation/tenants/${tenant.id}/documents/${document.id}`} className="text-sm font-semibold text-[#1F5A8B]">Download</a>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

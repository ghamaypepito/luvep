import { useState } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import { ArrowLeft, ChevronDown, Lock, AlertTriangle } from "lucide-react";
import TenancyRooms from "@/components/accommodation/TenancyRooms";

const dateValue = (v) => (v ? String(v).slice(0, 10) : "");
const FIELD_CLS = "w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm focus:border-[#1F5A8B] focus:ring-[#1F5A8B]";
const LABEL_CLS = "block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1.5";

const CONTRACT_TYPE_LABEL = {
    fixed_term: "Fixed term", periodic: "Periodic", open: "Open", not_yet_defined: "Not yet defined",
};

function Field({ name, label, type = "text", placeholder, span2 = false, step, value, error, onChange, hint }) {
    return (
        <div className={span2 ? "md:col-span-2" : ""}>
            <label className={LABEL_CLS}>{label}</label>
            <input type={type} step={step} className={FIELD_CLS} value={value ?? ""} placeholder={placeholder} onChange={(e) => onChange(name, e.target.value)} />
            {hint && <p className="mt-1 text-xs text-gray-400">{hint}</p>}
            {error && <p className="mt-1 text-xs text-rose-600">{error}</p>}
        </div>
    );
}

function Section({ title, hint, internal = false, defaultOpen = false, children }) {
    const [open, setOpen] = useState(defaultOpen);
    return (
        <div className="rounded-3xl border border-gray-50 bg-white shadow-sm">
            <button type="button" onClick={() => setOpen((o) => !o)} className="flex w-full items-center justify-between px-8 py-5 text-left">
                <span className="flex items-center gap-2">
                    <span className="font-semibold text-gray-900">{title}</span>
                    {internal && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">
                            <Lock size={10} /> Encrypted
                        </span>
                    )}
                    {hint && <span className="text-xs text-gray-400">{hint}</span>}
                </span>
                <ChevronDown size={18} className={`text-gray-400 transition-transform ${open ? "rotate-180" : ""}`} />
            </button>
            {open && <div className="border-t border-gray-50 px-8 py-6">{children}</div>}
        </div>
    );
}

export default function TenantForm({ tenant = null, property_id = null, properties = [], options = {} }) {
    const isEdit = Boolean(tenant);
    const contractTypes = options.contract_types ?? ["fixed_term", "periodic", "open", "not_yet_defined"];

    const { data, setData, post, transform, processing, errors } = useForm({
        property_id: tenant?.property_id ?? property_id ?? "",
        unit: tenant?.unit ?? "",
        assigned_room_ids: tenant?.assigned_room_ids ?? [],
        room_assignment_version: tenant?.move_in_version ?? null,
        first_name: tenant?.first_name ?? "",
        family_name: tenant?.family_name ?? "",
        display_name_override: tenant?.display_name_override ?? "",
        email: tenant?.email ?? "",
        phone: tenant?.phone ?? "",
        whatsapp: tenant?.whatsapp ?? "",
        contract_type: tenant?.contract_type ?? "not_yet_defined",
        contract_start: dateValue(tenant?.contract_start),
        contract_end: dateValue(tenant?.contract_end),
        open_contract_notice_weeks: tenant?.open_contract_notice_weeks ?? "",
        weekly_rent_nzd: tenant?.weekly_rent_nzd ?? "",
        weekly_utilities_nzd: tenant?.weekly_utilities_nzd ?? "",
        bond_paid_nzd: tenant?.bond_paid_nzd ?? "",
        advance_paid_nzd: tenant?.advance_paid_nzd ?? "",
        date_of_birth: dateValue(tenant?.date_of_birth),
        nationality: tenant?.nationality ?? "",
        passport_number: tenant?.passport_number ?? "",
        has_passport_in_drive: tenant?.has_passport_in_drive ?? false,
        has_tenancy_agreement_in_drive: tenant?.has_tenancy_agreement_in_drive ?? false,
        has_inspection_report_in_drive: tenant?.has_inspection_report_in_drive ?? false,
        notes: tenant?.notes ?? "",
    });

    const submit = (e) => {
        e.preventDefault();
        const url = isEdit ? `/portal/accommodation/tenants/${tenant.id}` : "/portal/accommodation/tenants";
        if (isEdit) transform((d) => ({ ...d, _method: "put" }));
        post(url);
    };

    const f = (name, label, extra = {}) => (
        <Field name={name} label={label} value={data[name]} error={errors[name]} onChange={setData} {...extra} />
    );

    const Toggle = ({ name, label }) => (
        <label className="flex items-center gap-3">
            <input type="checkbox" checked={data[name]} onChange={(e) => setData(name, e.target.checked)} className="h-4 w-4 rounded border-gray-300 text-[#1F5A8B] focus:ring-[#1F5A8B]" />
            <span className="text-sm font-medium text-gray-700">{label}</span>
        </label>
    );

    const total = (Number(data.weekly_rent_nzd) || 0) + (Number(data.weekly_utilities_nzd) || 0);
    const noDates = !data.contract_start && !data.contract_end;
    const showNotice = data.contract_type === "periodic" || data.contract_type === "open";

    return (
        <div className="max-w-7xl mx-auto space-y-6">
            <Head title={isEdit ? "Edit tenant" : "New tenant"} />

            <Link href={isEdit ? `/portal/accommodation/tenants/${tenant.id}` : "/portal/accommodation/tenants"} className="inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-900">
                <ArrowLeft size={16} /> Back
            </Link>

            <form onSubmit={submit} className="space-y-5">
                <div className="rounded-3xl border border-gray-50 bg-white p-8 shadow-sm">
                    <h1 className="text-2xl font-bold text-gray-900">{isEdit ? "Edit tenant" : "New tenant"}</h1>
                </div>

                {/* Property assignment */}
                <Section title="Property assignment" defaultOpen>
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label className={LABEL_CLS}>Property</label>
                            <select className={FIELD_CLS} value={data.property_id} onChange={(e) => setData(previous => ({ ...previous, property_id: e.target.value, assigned_room_ids: [] }))}>
                                <option value="">Select a property</option>
                                {properties.map((p) => (
                                    <option key={p.id} value={p.id}>{p.code ? `#${p.code} · ` : ""}{p.address}</option>
                                ))}
                            </select>
                            {errors.property_id && <p className="mt-1 text-xs text-rose-600">{errors.property_id}</p>}
                        </div>
                        {f("unit", "Unit (optional)", { placeholder: "e.g. 29A" })}
                        <div className="md:col-span-2">
                            <TenancyRooms rooms={properties.find(p => String(p.id) === String(data.property_id))?.rooms}
                                selected={data.assigned_room_ids} errors={errors} onChange={ids => setData("assigned_room_ids", ids)} />
                        </div>
                    </div>
                </Section>

                {/* Personal info */}
                <Section title="Personal info" defaultOpen>
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        {f("first_name", "First name")}
                        {f("family_name", "Family name")}
                        {f("display_name_override", "Display name override", { span2: true, hint: "Use when multiple people share one tenancy, e.g. \"Angela & Novie\"" })}
                        {f("email", "Email", { type: "email" })}
                        {f("phone", "Phone")}
                        {f("whatsapp", "WhatsApp")}
                    </div>
                </Section>

                {/* Contract details */}
                <Section title="Contract details" defaultOpen>
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label className={LABEL_CLS}>Contract type</label>
                            <select className={FIELD_CLS} value={data.contract_type} onChange={(e) => setData("contract_type", e.target.value)}>
                                {contractTypes.map((t) => <option key={t} value={t}>{CONTRACT_TYPE_LABEL[t] ?? t}</option>)}
                            </select>
                            {errors.contract_type && <p className="mt-1 text-xs text-rose-600">{errors.contract_type}</p>}
                        </div>
                        {showNotice ? f("open_contract_notice_weeks", "Notice period (weeks)", { type: "number" }) : <div />}
                        {f("contract_start", "Contract start", { type: "date" })}
                        {f("contract_end", "Contract end", { type: "date" })}
                    </div>
                    {noDates && (
                        <div className="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <AlertTriangle size={16} className="mt-0.5 shrink-0" />
                            <span>This tenant will appear in “Contract dates not on file” reports. Add dates as soon as available.</span>
                        </div>
                    )}
                </Section>

                {/* Financial */}
                <Section title="Financial">
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        {f("weekly_rent_nzd", "Weekly rent (NZD)", { type: "number", step: "0.01" })}
                        {f("weekly_utilities_nzd", "Weekly utilities (NZD)", { type: "number", step: "0.01" })}
                        <div>
                            <label className={LABEL_CLS}>Total weekly due</label>
                            <input className={`${FIELD_CLS} bg-gray-50`} value={`$${total.toFixed(2)}`} readOnly />
                        </div>
                        <div />
                        {f("bond_paid_nzd", "Bond paid (NZD)", { type: "number", step: "0.01" })}
                        {f("advance_paid_nzd", "Advance paid (NZD)", { type: "number", step: "0.01" })}
                    </div>
                </Section>

                {/* Personal details */}
                <Section title="Personal details" internal>
                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        {f("date_of_birth", "Date of birth", { type: "date" })}
                        {f("nationality", "Nationality")}
                        {f("passport_number", "Passport number", { span2: true, hint: "Encrypted at rest." })}
                    </div>
                </Section>

                {/* Documents status */}
                <Section title="Documents status" defaultOpen>
                    <div className="flex flex-col gap-4">
                        <Toggle name="has_passport_in_drive" label="Passport in Drive" />
                        <Toggle name="has_tenancy_agreement_in_drive" label="Tenancy Agreement in Drive" />
                        <Toggle name="has_inspection_report_in_drive" label="Inspection Report in Drive" />
                    </div>
                </Section>

                {/* Notes */}
                <Section title="Notes" defaultOpen>
                    <textarea rows={4} className={FIELD_CLS} value={data.notes} onChange={(e) => setData("notes", e.target.value)} placeholder="Internal notes about this tenant…" />
                    {errors.notes && <p className="mt-1 text-xs text-rose-600">{errors.notes}</p>}
                </Section>

                <div className="flex justify-end gap-3 pt-2">
                    <Link href="/portal/accommodation/tenants" className="rounded-full px-5 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-100">Cancel</Link>
                    <button type="submit" disabled={processing} className="rounded-full bg-[#1F5A8B] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50">
                        {processing ? "Saving…" : isEdit ? "Save changes" : "Create tenant"}
                    </button>
                </div>
            </form>
        </div>
    );
}

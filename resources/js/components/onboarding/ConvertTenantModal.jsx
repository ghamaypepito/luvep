import { useForm } from "@inertiajs/react";
import { X } from "lucide-react";
import TenancyRooms from "../accommodation/TenancyRooms";

const FIELD = "w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-[#1F5A8B] focus:ring-[#1F5A8B]";
const LABEL = "block text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-1";

const splitName = (full) => {
    const parts = (full || "").trim().split(/\s+/).filter(Boolean);
    return { first: parts[0] ?? "", family: parts.slice(1).join(" ") ?? "" };
};
const addYear = (dateStr) => {
    if (!dateStr) return "";
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return "";
    d.setFullYear(d.getFullYear() + 1);
    return d.toISOString().slice(0, 10);
};
const today = () => new Date().toISOString().slice(0, 10);

function SubHeading({ children }) {
    return <p className="sm:col-span-2 text-xs font-bold uppercase tracking-wider text-gray-400">{children}</p>;
}

/**
 * Convert an EOI submission into a Tenant. Pre-fills personal + financial data
 * from the submission; POSTs to applications.convert.
 */
export default function ConvertTenantModal({ submission, properties = [], contractTypes = [], onClose }) {
    const { first, family } = splitName(submission.full_legal_name);
    const existing = submission.converted_tenant?.current_status === 'pending_payment' ? submission.converted_tenant : null;
    const start = (existing?.contract_start ? String(existing.contract_start).slice(0, 10) : "") || (submission.move_in_date ? String(submission.move_in_date).slice(0, 10) : "") || today();

    const { data, setData, post, transform, processing, errors } = useForm({
        expected_tenant_version: existing?.move_in_version ?? null,
        property_id: existing?.property_id ?? submission.property_id ?? "",
        unit: existing?.unit ?? "",
        assigned_room_ids: existing?.assigned_room_ids ?? [],
        first_name: existing?.first_name ?? first,
        family_name: existing?.family_name ?? family,
        display_name_override: existing?.display_name_override ?? "",
        email: existing?.email ?? submission.email ?? "",
        phone: existing?.phone ?? submission.mobile ?? "",
        contract_type: existing?.contract_type ?? "fixed_term",
        contract_start: start,
        contract_end: existing?.contract_end ? String(existing.contract_end).slice(0, 10) : addYear(start),
        weekly_rent_nzd: existing?.weekly_rent_nzd ?? "",
        weekly_utilities_nzd: existing?.weekly_utilities_nzd ?? "",
        bond_paid_nzd: existing?.bond_paid_nzd ?? "",
        advance_paid_nzd: existing?.advance_paid_nzd ?? "",
        notes: existing?.notes ?? `Converted from EOI submission #${submission.id}`,
    });

    const submit = (e) => {
        e.preventDefault();
        transform(({ assigned_room_ids, ...values }) => ({
            ...values,
            ...(assigned_room_ids.length || existing?.assigned_room_ids?.length ? { assigned_room_ids } : {}),
        }));
        post(`/portal/accommodation/applications/${submission.id}/convert`, { preserveScroll: true });
    };

    const f = (name, label, type = "text", extra = {}) => (
        <div className={extra.span2 ? "sm:col-span-2" : ""}>
            <label className={LABEL}>{label}</label>
            <input type={type} step={extra.step} className={FIELD} value={data[name] ?? ""} onChange={(e) => setData(name, e.target.value)} />
            {errors[name] && <p className="mt-1 text-xs text-rose-600">{errors[name]}</p>}
        </div>
    );

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <form
                onSubmit={submit}
                onClick={(e) => e.stopPropagation()}
                className="flex max-h-[88vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-xl"
            >
                {/* Sticky header */}
                <div className="flex shrink-0 items-center justify-between border-b border-gray-100 px-6 py-4">
                    <div>
                        <h3 className="text-lg font-bold text-gray-900">{existing ? 'Complete move-in for existing tenant' : 'Convert to tenant'}</h3>
                        <p className="text-xs text-gray-500">{submission.full_legal_name}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"><X size={18} /></button>
                </div>

                {/* Scrollable body */}
                <div className="grid flex-1 grid-cols-1 gap-x-4 gap-y-3 overflow-y-auto px-6 py-5 sm:grid-cols-2">
                    {errors.expected_tenant_version && <p role="alert" className="sm:col-span-2 text-sm text-rose-700">{errors.expected_tenant_version}</p>}
                    <SubHeading>Property</SubHeading>
                    <div className="sm:col-span-2">
                        <label className={LABEL}>Property</label>
                        <select className={FIELD} value={data.property_id} disabled={Boolean(existing)} onChange={(e) => setData(previous => ({ ...previous, property_id: e.target.value, assigned_room_ids: [] }))}>
                            <option value="">Select a property</option>
                            {properties.map((p) => (
                                <option key={p.id} value={p.id}>{p.code ? `#${p.code} · ` : ""}{p.address}</option>
                            ))}
                        </select>
                        {errors.property_id && <p className="mt-1 text-xs text-rose-600">{errors.property_id}</p>}
                    </div>
                    {f("unit", "Unit (optional)")}
                    <div className="sm:col-span-2">
                        <TenancyRooms rooms={properties.find(p => String(p.id) === String(data.property_id))?.rooms}
                            selected={data.assigned_room_ids} errors={errors} onChange={ids => setData("assigned_room_ids", ids)} />
                    </div>

                    <SubHeading>Personal</SubHeading>
                    {f("first_name", "First name")}
                    {f("family_name", "Family name")}
                    {f("display_name_override", "Display name override", "text", { span2: true })}
                    {f("email", "Email", "email")}
                    {f("phone", "Phone")}

                    <SubHeading>Contract</SubHeading>
                    <div>
                        <label className={LABEL}>Contract type</label>
                        <select className={FIELD} value={data.contract_type} onChange={(e) => setData("contract_type", e.target.value)}>
                            {contractTypes.map((t) => <option key={t} value={t}>{t.replace(/_/g, " ")}</option>)}
                        </select>
                    </div>
                    <div className="hidden sm:block" />
                    {f("contract_start", "Contract start", "date")}
                    {f("contract_end", "Contract end", "date")}

                    <SubHeading>Financial</SubHeading>
                    {f("weekly_rent_nzd", "Weekly rent (NZD)", "number", { step: "0.01" })}
                    {f("weekly_utilities_nzd", "Weekly utilities (NZD)", "number", { step: "0.01" })}
                    {f("bond_paid_nzd", "Bond paid (NZD)", "number", { step: "0.01" })}
                    {f("advance_paid_nzd", "Advance paid (NZD)", "number", { step: "0.01" })}

                    <div className="sm:col-span-2">
                        <label className={LABEL}>Notes</label>
                        <textarea rows={2} className={FIELD} value={data.notes} onChange={(e) => setData("notes", e.target.value)} />
                    </div>
                </div>

                {/* Sticky footer */}
                <div className="flex shrink-0 items-center justify-end gap-2 border-t border-gray-100 px-6 py-4">
                    <button type="button" onClick={onClose} className="rounded-full px-5 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button type="submit" disabled={processing} className="rounded-full bg-[#1F5A8B] px-6 py-2 text-sm font-semibold text-white hover:bg-[#184A73] disabled:opacity-50">
                        {processing ? "Saving…" : existing ? "Complete move-in" : "Convert to tenant"}
                    </button>
                </div>
            </form>
        </div>
    );
}

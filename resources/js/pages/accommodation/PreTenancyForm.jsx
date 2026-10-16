import React, { useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import { CheckCircle2, Upload, FileText, Plus, Trash2 } from "lucide-react";

const PRIMARY = "#1F5A8B";

const emptyOccupant = () => ({ full_name: "", id_number: "", dob: "", age: "", relationship: "", email: "", mobile: "", visa_type: "" });

function Field({ label, hint, required, error, children }) {
    return (
        <div className="space-y-1.5">
            <label className="block text-sm font-semibold text-gray-800">
                {label} {required && <span className="text-rose-500">*</span>}
            </label>
            {hint && <p className="text-xs italic text-gray-500">{hint}</p>}
            {children}
            {error && <p className="text-xs font-medium text-rose-600">{error}</p>}
        </div>
    );
}

function SectionCard({ n, title, description, children }) {
    return (
        <section className="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm sm:p-7">
            <div className="mb-5 border-b border-gray-100 pb-4">
                <p className="text-[11px] font-bold uppercase tracking-[0.18em]" style={{ color: PRIMARY }}>Section {n}</p>
                <h2 className="mt-0.5 text-lg font-bold text-gray-900">{title}</h2>
                {description && <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-gray-600">{description}</p>}
            </div>
            <div className="space-y-5">{children}</div>
        </section>
    );
}

const inputClass = "w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-[#1F5A8B] focus:ring-2 focus:ring-[#1F5A8B]/20";

function FileInput({ id, value, onChange, error }) {
    return (
        <div>
            <label htmlFor={id} className={`flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-dashed px-4 py-3 text-sm transition-colors ${value ? "border-emerald-300 bg-emerald-50/50" : "border-gray-300 hover:border-[#1F5A8B]"}`}>
                <span className="flex items-center gap-2 text-gray-600">
                    {value ? <FileText size={16} className="text-emerald-600" /> : <Upload size={16} className="text-gray-400" />}
                    <span className="truncate">{value ? value.name : "Choose a file (PDF or image, up to 20MB)"}</span>
                </span>
                <span className="shrink-0 rounded-lg px-3 py-1 text-xs font-semibold text-white" style={{ backgroundColor: PRIMARY }}>{value ? "Change" : "Upload"}</span>
            </label>
            <input id={id} type="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp" className="hidden" onChange={(e) => onChange(e.target.files?.[0] ?? null)} />
            {error && <p className="mt-1 text-xs font-medium text-rose-600">{error}</p>}
        </div>
    );
}

function RadioGroup({ options, value, onChange, name, error }) {
    return (
        <div className="space-y-2">
            {options.map((opt) => (
                <label key={opt} className={`flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-2.5 text-sm transition-colors ${value === opt ? "border-[#1F5A8B] bg-[#1F5A8B]/5 font-medium text-gray-900" : "border-gray-200 text-gray-700 hover:border-gray-300"}`}>
                    <input type="radio" name={name} checked={value === opt} onChange={() => onChange(opt)} className="h-4 w-4 accent-[#1F5A8B]" />
                    {opt}
                </label>
            ))}
            {error && <p className="text-xs font-medium text-rose-600">{error}</p>}
        </div>
    );
}

export default function PreTenancyForm({ token, completed = false, prefill = {}, options = {}, general = false }) {
    const { data, setData, post, processing, errors } = useForm({
        full_legal_name: prefill.full_legal_name || "",
        id_number: prefill.id_number || "",
        age: prefill.age || "",
        email: prefill.email || "",
        mobile: prefill.mobile || "",
        current_address: prefill.current_address || "",
        visa_type: prefill.visa_type || "",
        has_additional_occupants: "no",
        occupants: [emptyOccupant()],
        valid_id: null,
        visa: null,
        account_name: "",
        account_number: "",
        property_address: prefill.property_address || "",
        move_in_date: prefill.move_in_date || "",
        length_of_stay: prefill.length_of_stay || "",
        room_type: prefill.room_type || "",
        rent_funding: prefill.rent_funding || "",
        rent_funding_other: "",
        referee_name: "",
        referee_phone: "",
        referee_email: "",
    });

    const setOccupant = (i, key, val) => setData("occupants", data.occupants.map((o, idx) => (idx === i ? { ...o, [key]: val } : o)));
    const addOccupant = () => setData("occupants", [...data.occupants, emptyOccupant()]);
    const removeOccupant = (i) => setData("occupants", data.occupants.filter((_, idx) => idx !== i));
    const setOccupantsYesNo = (yes) => {
        setData((prev) => ({
            ...prev,
            has_additional_occupants: yes ? "yes" : "no",
            // Guarantee at least one row to fill when they switch to "Yes".
            occupants: yes && prev.occupants.length === 0 ? [emptyOccupant()] : prev.occupants,
        }));
    };

    // The local /dev/pre-tenancy preview uses a fake token — never post it (that
    // 404s); just show the thank-you so the whole flow can be reviewed.
    const [previewDone, setPreviewDone] = useState(false);
    const submit = (e) => {
        e.preventDefault();
        if (token === "preview") {
            setPreviewDone(true);
            window.scrollTo({ top: 0, behavior: "smooth" });
            return;
        }
        // The standalone/general form posts to its own endpoint (no token);
        // the onboarding form posts to its tokenised endpoint. The general form
        // redirects back to a blank form, so keep component state (preserveState)
        // and flip to the thank-you ourselves — otherwise Inertia remounts the
        // page and the thank-you never shows.
        post(general ? "/apply/pre-tenancy" : `/pre-tenancy/${token}`, {
            forceFormData: true,
            preserveScroll: false,
            preserveState: general ? true : undefined,
            onSuccess: () => { if (general) { setPreviewDone(true); window.scrollTo({ top: 0, behavior: "smooth" }); } },
        });
    };

    if (completed || previewDone) {
        return (
            <div className="min-h-screen bg-[#eef0f4]">
                <Head title="Pre-Tenancy Form — Submitted" />
                <div className="mx-auto flex min-h-screen max-w-lg items-center justify-center px-4">
                    <div className="rounded-3xl border border-gray-100 bg-white p-10 text-center shadow-sm">
                        <CheckCircle2 size={48} className="mx-auto text-emerald-500" />
                        <h1 className="mt-4 text-2xl font-bold text-gray-900">Thank you!</h1>
                        <p className="mt-3 text-sm leading-relaxed text-gray-600">
                            We&rsquo;ve received your Pre-Tenancy form. Our team will now prepare your tenancy
                            agreement and send it through for signing within 24 hours.
                        </p>
                        <p className="mt-4 text-sm text-gray-500">We&rsquo;re looking forward to having you with us 😊</p>
                    </div>
                </div>
            </div>
        );
    }

    const occupantError = (i, key) => errors[`occupants.${i}.${key}`];

    return (
        <div className="min-h-screen bg-[#eef0f4]">
            <Head title="Pre-Tenancy Form" />

            {/* Local dev preview only — this token never posts to a real record.
                Makes the preview impossible to mistake for the emailed form. */}
            {token === "preview" && (
                <div className="bg-amber-500 px-4 py-2 text-center text-sm font-semibold text-white">
                    PREVIEW — submitting here does nothing. Use the real emailed link (Copy link on the onboarding record) to submit for real.
                </div>
            )}

            {/* Header */}
            <header className="px-4 pt-8 pb-6 text-center">
                <div className="mx-auto max-w-3xl">
                    <h1 className="text-2xl font-bold text-gray-900 sm:text-3xl">Pre-Tenancy Form</h1>
                    <p className="mt-2 text-sm text-gray-600">Please complete this form so we can prepare your tenancy documents. Fields marked <span className="text-rose-500">*</span> are required.</p>
                    <img src="/images/pretenacybanner.png" alt="Pre-Tenancy" className="mx-auto mt-6 w-full max-w-2xl rounded-2xl shadow-sm" />
                </div>
            </header>

            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-5 px-4 pb-20">
                {/* Section 1 — Applicant */}
                <SectionCard n="1" title="Your details">
                    <Field label="Full Legal Name (First, Middle, Surname)" hint="As per your valid ID" required error={errors.full_legal_name}>
                        <input className={inputClass} value={data.full_legal_name} onChange={(e) => setData("full_legal_name", e.target.value)} />
                    </Field>
                    <Field label="Passport / Driver Licence Number" hint="As per Passport or Driver Licence" required error={errors.id_number}>
                        <input className={inputClass} value={data.id_number} onChange={(e) => setData("id_number", e.target.value)} />
                    </Field>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field label="Age" required error={errors.age}>
                            <input className={inputClass} value={data.age} onChange={(e) => setData("age", e.target.value)} />
                        </Field>
                        <Field label="Mobile Number" required error={errors.mobile}>
                            <input className={inputClass} value={data.mobile} onChange={(e) => setData("mobile", e.target.value)} />
                        </Field>
                    </div>
                    <Field label="Email Address" required error={errors.email}>
                        <input type="email" className={inputClass} value={data.email} onChange={(e) => setData("email", e.target.value)} />
                    </Field>
                    <Field label="Current Address" required error={errors.current_address}>
                        <input className={inputClass} value={data.current_address} onChange={(e) => setData("current_address", e.target.value)} />
                    </Field>
                    <Field label="Visa Type" error={errors.visa_type}>
                        <select className={inputClass} value={data.visa_type} onChange={(e) => setData("visa_type", e.target.value)}>
                            <option value="">Select a visa type…</option>
                            {(options.visa_types || []).map((v) => (
                                <option key={v} value={v}>{v}</option>
                            ))}
                            {data.visa_type && !(options.visa_types || []).includes(data.visa_type) && (
                                <option value={data.visa_type}>{data.visa_type}</option>
                            )}
                        </select>
                    </Field>
                </SectionCard>

                {/* Section 2 — Other Occupants (with a dynamic occupant list) */}
                <SectionCard n="2" title="Other Subtenants">
                    <Field label="Are there any additional subtenants staying with you?" hint="If yes, add each subtenant's details below — you can add as many as you need." required error={errors.has_additional_occupants}>
                        <RadioGroup name="has_occ" options={["No (Just me)", "Yes"]} value={data.has_additional_occupants === "yes" ? "Yes" : "No (Just me)"} onChange={(v) => setOccupantsYesNo(v === "Yes")} />
                    </Field>

                    {data.has_additional_occupants === "yes" && (
                        <div className="space-y-4">
                            {data.occupants.map((occ, i) => (
                                <div key={i} className="rounded-2xl border border-gray-200 p-4 sm:p-5">
                                    <div className="mb-4 flex items-center justify-between">
                                        <h3 className="text-sm font-bold text-gray-900">Subtenant {i + 1}</h3>
                                        {data.occupants.length > 1 && (
                                            <button type="button" onClick={() => removeOccupant(i)} className="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:text-rose-700">
                                                <Trash2 size={13} /> Remove
                                            </button>
                                        )}
                                    </div>
                                    <div className="space-y-4">
                                        <Field label="Full Name" hint="First, Middle, Last Name as per ID" required={i === 0} error={occupantError(i, "full_name")}>
                                            <input className={inputClass} value={occ.full_name} onChange={(e) => setOccupant(i, "full_name", e.target.value)} />
                                        </Field>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Field label="Passport / Driver's Licence" error={occupantError(i, "id_number")}>
                                                <input className={inputClass} value={occ.id_number} onChange={(e) => setOccupant(i, "id_number", e.target.value)} />
                                            </Field>
                                            <Field label="Date of Birth" error={occupantError(i, "dob")}>
                                                <input type="date" className={inputClass} value={occ.dob} onChange={(e) => setOccupant(i, "dob", e.target.value)} />
                                            </Field>
                                            <Field label="Age" error={occupantError(i, "age")}>
                                                <input className={inputClass} value={occ.age} onChange={(e) => setOccupant(i, "age", e.target.value)} />
                                            </Field>
                                            <Field label="Relationship to Main Tenant" error={occupantError(i, "relationship")}>
                                                <input className={inputClass} value={occ.relationship} onChange={(e) => setOccupant(i, "relationship", e.target.value)} />
                                            </Field>
                                        </div>
                                        <Field label="Email Address" hint="If the additional subtenant is under 18 years old, this is optional." error={occupantError(i, "email")}>
                                            <input type="email" className={inputClass} value={occ.email} onChange={(e) => setOccupant(i, "email", e.target.value)} />
                                        </Field>
                                        <Field label="Mobile Number" hint="If the additional subtenant is under 18 years old, this is optional." error={occupantError(i, "mobile")}>
                                            <input className={inputClass} value={occ.mobile} onChange={(e) => setOccupant(i, "mobile", e.target.value)} />
                                        </Field>
                                        <Field label="Visa Type" error={occupantError(i, "visa_type")}>
                                            <select className={inputClass} value={occ.visa_type || ""} onChange={(e) => setOccupant(i, "visa_type", e.target.value)}>
                                                <option value="">Select a visa type…</option>
                                                {(options.visa_types || []).map((v) => (
                                                    <option key={v} value={v}>{v}</option>
                                                ))}
                                                {occ.visa_type && !(options.visa_types || []).includes(occ.visa_type) && (
                                                    <option value={occ.visa_type}>{occ.visa_type}</option>
                                                )}
                                            </select>
                                        </Field>
                                    </div>
                                </div>
                            ))}
                            <button type="button" onClick={addOccupant} className="inline-flex items-center gap-2 rounded-xl border border-dashed border-[#1F5A8B] px-4 py-2.5 text-sm font-semibold text-[#1F5A8B] transition-colors hover:bg-[#1F5A8B]/5">
                                <Plus size={15} /> Add another subtenant
                            </button>
                        </div>
                    )}
                </SectionCard>

                {/* Section 3 — Upload Documents */}
                <SectionCard n="3" title="Upload Documents">
                    <Field label="Upload Valid ID (Passport or Driver's Licence)" required error={errors.valid_id}>
                        <FileInput id="valid_id" value={data.valid_id} onChange={(f) => setData("valid_id", f)} error={errors.valid_id} />
                    </Field>
                    <Field label="Upload Visa" required error={errors.visa}>
                        <FileInput id="visa" value={data.visa} onChange={(f) => setData("visa", f)} error={errors.visa} />
                    </Field>
                </SectionCard>

                {/* Section 7 — Bank Details */}
                <SectionCard n="4" title="Bank Details" description={"These bank details will be used for your bond refund at the end of your tenancy. By providing these details, you confirm that this bank account belongs to you.\nWe expect weekly rent and utility payments to be made from this account. This information is kept confidential and used for tenancy administration purposes only."}>
                    <Field label="Account name" required error={errors.account_name}>
                        <input className={inputClass} value={data.account_name} onChange={(e) => setData("account_name", e.target.value)} />
                    </Field>
                    <Field label="Account number" required error={errors.account_number}>
                        <input className={inputClass} value={data.account_number} onChange={(e) => setData("account_number", e.target.value)} />
                    </Field>
                </SectionCard>

                {/* Section 8 — Tenancy Details */}
                <SectionCard n="5" title="Tenancy Details">
                    <Field label="Property Address" required error={errors.property_address}>
                        <select className={inputClass} value={data.property_address} onChange={(e) => setData("property_address", e.target.value)}>
                            <option value="">Select a property…</option>
                            {(options.properties || []).map((p) => (
                                <option key={p} value={p}>{p}</option>
                            ))}
                            {/* Preserve a prefilled address that isn't in the current property list. */}
                            {data.property_address && !(options.properties || []).includes(data.property_address) && (
                                <option value={data.property_address}>{data.property_address}</option>
                            )}
                        </select>
                    </Field>
                    <Field label="Move-in Date" required error={errors.move_in_date}>
                        <input type="date" className={inputClass} value={data.move_in_date} onChange={(e) => setData("move_in_date", e.target.value)} />
                    </Field>
                    <Field label="Intended Length of Stay" required error={errors.length_of_stay}>
                        <RadioGroup name="stay" options={options.lengths || []} value={data.length_of_stay} onChange={(v) => setData("length_of_stay", v)} error={errors.length_of_stay} />
                    </Field>
                    <Field label="Room Type" required error={errors.room_type}>
                        <RadioGroup name="room" options={options.room_types || []} value={data.room_type} onChange={(v) => setData("room_type", v)} error={errors.room_type} />
                    </Field>
                    <Field label="How will you primarily fund your rent payments?" required error={errors.rent_funding}>
                        <RadioGroup name="fund" options={options.funding || []} value={data.rent_funding} onChange={(v) => setData("rent_funding", v)} error={errors.rent_funding} />
                        {data.rent_funding === "Other" && (
                            <input className={`${inputClass} mt-2`} placeholder="Please specify" value={data.rent_funding_other} onChange={(e) => setData("rent_funding_other", e.target.value)} />
                        )}
                        {errors.rent_funding_other && <p className="mt-1 text-xs font-medium text-rose-600">{errors.rent_funding_other}</p>}
                    </Field>
                </SectionCard>

                {/* Section 9 — Reference */}
                <SectionCard n="6" title="Reference" description={"Please provide the contact details of your current landlord or most recent landlord. This must be from the person or property manager responsible for the property you currently live in.\nWe do not accept references from friends, family members, co-workers, or personal acquaintances."}>
                    <Field label="Full name of referee" required error={errors.referee_name}>
                        <input className={inputClass} value={data.referee_name} onChange={(e) => setData("referee_name", e.target.value)} />
                    </Field>
                    <Field label="Referee's phone number" required error={errors.referee_phone}>
                        <input className={inputClass} value={data.referee_phone} onChange={(e) => setData("referee_phone", e.target.value)} />
                    </Field>
                    <Field label="Referee's email address" required error={errors.referee_email}>
                        <input type="email" className={inputClass} value={data.referee_email} onChange={(e) => setData("referee_email", e.target.value)} />
                    </Field>
                </SectionCard>

                <div className="flex items-center justify-end gap-3 pt-2">
                    <button type="submit" disabled={processing} className="inline-flex items-center gap-2 rounded-full px-8 py-3 text-sm font-bold text-white shadow-sm transition-opacity disabled:opacity-50" style={{ backgroundColor: PRIMARY }}>
                        {processing ? "Submitting…" : "Submit form"}
                    </button>
                </div>
            </form>
        </div>
    );
}

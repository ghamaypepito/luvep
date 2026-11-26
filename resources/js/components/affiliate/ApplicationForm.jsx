import { useState } from "react";
import { useForm } from "@inertiajs/react";

const fields = [
    ["name", "Full name", "text", "name", 255],
    ["email", "Email address", "email", "email", 255],
    ["phone", "Phone number", "tel", "tel", 60],
    ["location", "Country or location", "text", "country-name", 255],
    ["company", "Company (optional)", "text", "organization", 255],
];

export default function ApplicationForm({ draft = null, onSuccess }) {
    const form = useForm(Object.fromEntries(fields.map(([key]) => [key, draft?.[key] || ""]).concat([["consent", false]])));
    const [saved, setSaved] = useState(false);
    const submit = event => {
        event.preventDefault();
        if (form.processing) return;
        const intent = event.nativeEvent.submitter?.value || "submit";
        form.transform(data => ({ ...data, intent, ...(draft ? { draft_version: draft.draft_version } : {}) }));
        form.post(draft?.save_url || "/affiliate/apply", { onSuccess: () => { setSaved(intent === "save"); onSuccess?.(form.data.email); } });
    };
    return <form onSubmit={submit} className="mt-7 space-y-5">
        <div className="grid gap-4 sm:grid-cols-2">
            {fields.map(([key, label, type, autoComplete, max]) => <label key={key} className={`grid gap-1.5 text-sm font-medium ${key === "name" ? "sm:col-span-2" : ""}`}>
                {label}
                <input name={key} type={type} required={key !== "company"} readOnly={key === "email" && !!draft} maxLength={max} autoComplete={autoComplete}
                    value={form.data[key]} onChange={e => { setSaved(false); form.setData(key, e.target.value); }} aria-invalid={!!form.errors[key]}
                    aria-describedby={form.errors[key] ? key + "-error" : undefined}
                    className="rounded-lg border border-[#b9c8ba] bg-white px-3 py-2.5 text-base focus:outline-emerald-800 read-only:bg-[#f4f6ef]" />
                {form.errors[key] && <span id={key + "-error"} role="alert" className="text-xs text-red-700">{form.errors[key]}</span>}
            </label>)}
        </div>
        {!draft && <div>
            <label className="flex items-start gap-2 text-sm"><input type="checkbox" required name="consent" className="mt-1 accent-emerald-900"
                checked={form.data.consent} onChange={e => form.setData("consent", e.target.checked)} />I agree that EP may contact me about this affiliate application.</label>
            {form.errors.consent && <p role="alert" className="text-sm text-red-700">{form.errors.consent}</p>}
        </div>}
        {['application', 'draft_version'].map(key => form.errors[key] && <p key={key} role="alert" className="text-sm text-red-700">{form.errors[key]}</p>)}
        <p className="text-sm text-[#53655a]">{draft ? "Your verified email stays linked to this application. Save unfinished details, or submit them for EP review. Submitted details cannot be edited here."
            : "Not ready? Save with your name, email and contact consent. We’ll email a private link so you can finish later."}</p>
        <div className="flex flex-wrap gap-2">
            <button type="submit" name="intent" value="save" formNoValidate className="affiliate-button" disabled={form.processing}>Save application</button>
            <button type="submit" name="intent" value="submit" className="affiliate-button affiliate-button-primary" disabled={form.processing}>Submit application</button>
        </div>
        {saved && <p role="status" className="text-sm font-medium text-[#285d46]">Application saved. You can return using your private email link. Links last two days; request a fresh link if yours expires.</p>}
    </form>;
}

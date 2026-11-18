import { useState } from "react";
import { useForm } from "@inertiajs/react";
import ApplicationLayout from "../../components/affiliate/ApplicationLayout";

const fields = [
    ["name", "Full name", "text", "name", 255],
    ["email", "Email address", "email", "email", 255],
    ["phone", "Phone number", "tel", "tel", 60],
    ["location", "Country or location", "text", "country-name", 255],
    ["company", "Company (optional)", "text", "organization", 255],
];

export default function Apply({ submitted = false }) {
    const [renewal, setRenewal] = useState(false);
    const form = useForm({ name: "", email: "", phone: "", location: "", company: "", consent: false });
    const [complete, setComplete] = useState(submitted);
    const submit = event => {
        event.preventDefault();
        if (form.processing) return;
        form.transform(data => renewal ? { email: data.email } : data);
        form.post(renewal ? "/affiliate/apply/resend" : "/affiliate/apply", {
            onSuccess: () => setComplete(true),
        });
    };
    const changeMode = () => {
        form.clearErrors();
        setRenewal(complete || !renewal);
        setComplete(false);
    };
    const title = complete ? "Check your email" : renewal ? "Request a private link" : "Become an Affiliate Partner";
    const description = complete ? "Use the latest private link from EP to confirm your email or check your application. If you can’t find it, check your spam folder or request a fresh link."
        : renewal ? "Enter the email address on your application. We’ll send a fresh link to that inbox."
        : "Apply to refer clients to EP. We’ll verify your email, review your application and prepare an agreement for you and EP to sign.";

    return <ApplicationLayout title={title} description={description}>
        {!complete && <form onSubmit={submit} className="mt-7 space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
                {fields.filter(([key]) => !renewal || key === "email").map(([key, label, type, autoComplete, max]) => (
                    <label key={key} className={`grid gap-1.5 text-sm font-medium ${key === "name" || renewal ? "sm:col-span-2" : ""}`}>
                        {label}
                        <input name={key} type={type} required={key !== "company"} maxLength={max} autoComplete={autoComplete}
                            value={form.data[key]} onChange={e => form.setData(key, e.target.value)} aria-invalid={!!form.errors[key]}
                            aria-describedby={form.errors[key] ? key + "-error" : undefined}
                            className="rounded-lg border border-[#b9c8ba] bg-white px-3 py-2.5 text-base focus:outline-emerald-800" />
                        {form.errors[key] && <span id={key + "-error"} role="alert" className="text-xs text-red-700">{form.errors[key]}</span>}
                    </label>
                ))}
            </div>
            {!renewal && <div>
                <label className="flex items-start gap-2 text-sm"><input type="checkbox" required name="consent" className="mt-1 accent-emerald-900"
                    checked={form.data.consent} onChange={e => form.setData("consent", e.target.checked)} />I agree that EP may contact me about this affiliate application.</label>
                {form.errors.consent && <p role="alert" className="text-sm text-red-700">{form.errors.consent}</p>}
            </div>}
            <button className="affiliate-button affiliate-button-primary" disabled={form.processing}>{renewal ? "Email my private link" : "Submit application"}</button>
        </form>}
        <div className="mt-7 border-t border-[#e5ebe1] pt-4 text-sm">
            <button type="button" onClick={changeMode} className="font-semibold text-[#285d46] underline underline-offset-2">
                {complete || !renewal ? "Already applied? Request a fresh link" : "Start an application"}
            </button>
        </div>
    </ApplicationLayout>;
}

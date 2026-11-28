import { useState } from "react";
import { useForm } from "@inertiajs/react";
import ApplicationLayout from "../../components/affiliate/ApplicationLayout";
import ApplicationForm from "../../components/affiliate/ApplicationForm";

export default function Apply({ submitted = false }) {
    const [renewal, setRenewal] = useState(false);
    const form = useForm({ email: "" });
    const [complete, setComplete] = useState(submitted);
    const submit = event => {
        event.preventDefault();
        if (form.processing) return;
        form.post("/affiliate/apply/resend", {
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
        : "Save your details, submit for EP review, then read and sign your agreement right here. Your portal opens after approval and both signatures.";

    return <ApplicationLayout title={title} description={description}>
        {!complete && !renewal && <ApplicationForm onSuccess={email => { form.setData("email", email); setComplete(true); }} />}
        {!complete && renewal && <form onSubmit={submit} className="mt-7 space-y-5">
            <label className="grid gap-1.5 text-sm font-medium">Email address
                <input name="email" type="email" required maxLength={255} autoComplete="email" value={form.data.email} onChange={e => form.setData("email", e.target.value)}
                    className="rounded-lg border border-[#b9c8ba] bg-white px-3 py-2.5 text-base focus:outline-emerald-800" />
            </label>
            {form.errors.email && <p role="alert" className="text-sm text-red-700">{form.errors.email}</p>}
            <button className="affiliate-button affiliate-button-primary" disabled={form.processing}>Email my private link</button>
        </form>}
        <div className="mt-7 border-t border-[#e5ebe1] pt-4 text-sm">
            <button type="button" onClick={changeMode} className="font-semibold text-[#285d46] underline underline-offset-2">
                {complete || !renewal ? "Already applied? Request a fresh link" : "Start an application"}
            </button>
        </div>
    </ApplicationLayout>;
}

import { useEffect, useState } from "react";
import { router } from "@inertiajs/react";

const actionLabels = {
    viewing: "Property",
    attendance: "Viewing booking",
    pre_tenancy: "Pre-Tenancy Form",
    agreement: "Agreement to sign",
    signed_agreement: "Signed agreement",
    invoice: "Issued move-in invoice",
    welcome: "Active tenant",
};

const input = "w-full rounded-lg border border-gray-200 px-3 py-2 text-sm read-only:bg-gray-50";

export default function ExaltTemplateDraft({ template, email, attachments, basePath, onSent, onClear, onboarding = null }) {
    const path = `${basePath}/exalt-template`;
    const { fields, action } = template.exalt;
    const [values, setValues] = useState({});
    const [options, setOptions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [actionId, setActionId] = useState(() => String(onboarding?.action_id ?? ""));
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const record = options.find((option) => String(option.id) === actionId);
    const recordFields = record?.fields ?? {};

    useEffect(() => {
        if (!action || !email) return;
        const controller = new AbortController();
        setLoading(true);
        window.axios.get(`${path}/options`, {
            params: { template_id: template.id, email },
            signal: controller.signal,
        }).then(({ data }) => {
            if (!controller.signal.aborted) setOptions(data.options);
        }).catch(() => {
            if (!controller.signal.aborted) setError("Could not load portal records. Reopen the template to retry.");
        }).finally(() => {
            if (!controller.signal.aborted) setLoading(false);
        });
        return () => controller.abort();
    }, [action, email, path, template.id]);

    const complete = email && (!action || record)
        && fields.every(({ key, optional }) => optional || (recordFields[key] ?? values[key] ?? "").trim());
    const payload = { template_id: template.id, action_id: actionId || null, email, fields: values, onboarding_id: onboarding?.id ?? null };
    const showError = (errors) => setError(Object.values(errors || {}).flat().join(" ") || "Could not prepare the email.");
    const previewEmail = async () => {
        setError("");
        setBusy(true);
        try {
            const { data } = await window.axios.post(`${path}/preview`, payload);
            setPreview(data);
        } catch (failure) {
            showError(failure.response?.data?.errors);
        } finally {
            setBusy(false);
        }
    };

    const send = () => {
        setError("");
        setBusy(true);
        router.post(`${path}/send`, { ...payload, attachments }, {
            forceFormData: true,
            preserveScroll: true,
            onError: showError,
            onSuccess: onSent,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <section className="space-y-4 rounded-xl border border-emerald-100 bg-emerald-50/30 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-semibold text-gray-900">{template.name}</h3>
                <button type="button" onClick={onClear} disabled={busy} className="text-sm text-gray-600">Clear template</button>
            </div>
            {onboarding && <p className="text-sm text-gray-600">Onboarding application · {onboarding.name}</p>}
            {!email && <p className="text-sm text-gray-600">Select one recipient in the To field.</p>}
            {email && action && (
                <label className="block space-y-1 text-sm font-medium">
                    <span>{actionLabels[action]}</span>
                    <select className={input} value={actionId} disabled={loading || busy}
                        onChange={(event) => { setActionId(event.target.value); setPreview(null); setError(""); }}>
                        <option value="">{loading ? "Loading records…" : options.length ? "Select a portal record" : "No matching portal records"}</option>
                        {options.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
                    </select>
                </label>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
                {fields.map(({ key, optional }) => (
                    <label key={key} className="block space-y-1 text-sm font-medium">
                        <span>{key}</span>
                        <input className={input} value={recordFields[key] ?? values[key] ?? ""}
                            readOnly={Object.hasOwn(recordFields, key)} disabled={busy} required={!optional} maxLength={3000}
                            onChange={(event) => {
                                setValues({ ...values, [key]: event.target.value });
                                setPreview(null);
                                setError("");
                            }} />
                    </label>
                ))}
            </div>
            {action === "welcome" && <p className="text-sm text-gray-600">Attach the allocated storage photos and room condition report below.</p>}
            {error && <p className="text-sm text-rose-700" role="alert">{error}</p>}
            <div className="flex flex-wrap gap-2">
                <button type="button" onClick={previewEmail} disabled={!complete || busy}
                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-40">Preview</button>
                <button type="button" onClick={send} disabled={!complete || busy || (action === "welcome" && !attachments.length)}
                    className="rounded-lg bg-[#436235] px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Send email</button>
            </div>
            {preview && <div className="rounded-lg border border-gray-200 bg-white">
                <p className="px-3 py-2 text-sm font-semibold">{preview.subject}</p>
                <iframe title="Approved email preview" srcDoc={preview.body} sandbox="" className="h-72 w-full border-t border-gray-200" />
            </div>}
        </section>
    );
}

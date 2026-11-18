import { useEffect, useId, useRef, useState } from "react";
import { useForm } from "@inertiajs/react";
import { ArrowLeft, ArrowRight, Send, ImagePlus } from "lucide-react";
import TicketAttachmentPicker from "@/components/TicketAttachmentPicker";
import { briefSections } from "./briefSections";

const designExamples = {
    "Digital media": "Social media / slides / graphic design",
    "Print media": "Poster / flyer / standee / banners / flags / letterheads",
};

export default function CreativeBriefForm({ owner, designTypes, onCancel }) {
    const [step, setStep] = useState(0);
    const lastStep = briefSections.length - 1;
    const dialog = useRef(null);
    const titleId = useId();
    useEffect(() => {
        const modal = dialog.current;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        modal.showModal();
        modal.querySelector('input[name="project"]').focus();
        return () => { modal.close(); document.body.style.overflow = overflow; };
    }, []);
    const submitting = useRef(false);
    const form = useForm({ project: "", types: [], other: "", attachments: [], ...Object.fromEntries(briefSections.flatMap(section => section.fields.map(([key]) => [key, ""]))) });
    const dismiss = () => {
        if (form.processing) return;
        dialog.current.close();
        onCancel();
    };
    const field = ([key, label, placeholder, required, type]) => <label key={key} className={key === "copy" ? "cw-copy-field" : ""}>{label}{required ? " *" : ""}
        {type ? <input type={type} name={key} required={!!required} value={form.data[key]} onChange={e => form.setData(key, e.target.value)} placeholder={placeholder} maxLength={200} /> : <textarea name={key} required={!!required} rows={key === "copy" ? 4 : 3} value={form.data[key]} onChange={e => form.setData(key, e.target.value)} placeholder={placeholder} maxLength={key === "overview" ? 5000 : 10000} />}
        {form.errors[key] && <small className="cw-error">{form.errors[key]}</small>}
    </label>;
    const submit = e => {
        e.preventDefault();
        if (step < lastStep) { setStep(step + 1); return; }
        if (submitting.current) return;
        submitting.current = true;
        form.post("/creative-requests", { forceFormData: true, onFinish: () => { submitting.current = false; }, onError: errors => {
            const index = briefSections.findIndex(section => section.fields.some(([key]) => errors[key]));
            setStep(errors.project ? 0 : errors.types || errors.other ? 2 : Object.keys(errors).some(key => key.startsWith("attachments")) ? 3 : Math.max(0, index));
        } });
    };
    return <dialog ref={dialog} className="cw-brief-dialog" aria-labelledby={titleId} onCancel={event => { event.preventDefault(); dismiss(); }}><form className="cw-brief cw-panel" onSubmit={submit} noValidate>
        <div className="cw-section-heading"><div><p className="cw-eyebrow">Creative request</p><h2 id={titleId}>Creative brief ticket</h2></div><button type="button" className="cw-text-button" onClick={dismiss} disabled={form.processing}>Close</button></div>
        <div className="cw-project-fields"><label>Project name *<input name="project" value={form.data.project} onChange={e => form.setData("project", e.target.value)} maxLength={160} placeholder="e.g. Study in New Zealand campaign" /></label></div>
        <nav className="cw-steps" aria-label="Creative brief sections">{briefSections.map((section, i) => <button type="button" key={section.short} onClick={() => setStep(i)} aria-current={step === i ? "step" : undefined}><span>{String(i + 1).padStart(2, "0")}</span>{section.short}</button>)}</nav>
        {!!Object.keys(form.errors).length && <div className="cw-error-summary" role="alert">{Object.entries(form.errors).map(([key, value]) => <p key={key}>{value}</p>)}</div>}
        {!owner && <p className="cw-error-summary" role="alert">Sharry’s active account is unavailable. An administrator must check the assignment before you submit.</p>}
        {briefSections.map((section, i) => <section key={section.title} hidden={step !== i} aria-label={section.title}>
            <h3 className="cw-form-title">{section.title}</h3>
            <div className="cw-fields">{i === 2 ? <><fieldset><legend>Design type * <span className="cw-muted">Select all that apply</span></legend><div className="cw-design-types">{designTypes.map(type => <label key={type}><input type="checkbox" checked={form.data.types.includes(type)} onChange={e => form.setData("types", e.target.checked ? [...form.data.types, type] : form.data.types.filter(value => value !== type))} /><span>{type}{designExamples[type] && <small>{designExamples[type]}</small>}</span></label>)}</div></fieldset>{form.data.types.includes("Other") && field(["other", "Other design type", "Describe the format", true, "text"])}</> : section.fields.map(field)}
            {i === 3 && <div className="cw-reference-upload"><h3><ImagePlus size={18} /> Sample designs & reference attachments</h3><p className="cw-muted">Attach examples that show the direction you like.</p><TicketAttachmentPicker label="Sample designs / references" files={form.data.attachments} onChange={files => form.setData("attachments", files)} errors={form.errors} disabled={form.processing} /></div>}
            </div>
        </section>)}
        <footer className="cw-form-footer"><p className="cw-muted">Section {step + 1} of {briefSections.length} · * Required<br /><small>Assigned to {owner?.name || "Sharry"} after submission.</small></p><div className="cw-actions">{step > 0 && <button type="button" className="cw-button cw-secondary" onClick={() => setStep(step - 1)}><ArrowLeft size={16} /> Back</button>}<button type="submit" className="cw-button" disabled={form.processing || !owner}>{step === lastStep ? <><Send size={16} /> {form.processing ? "Submitting…" : "Submit creative request"}</> : <>Next: {briefSections[step + 1].short}<ArrowRight size={16} /></>}</button></div></footer>
        {form.progress && <p role="status">Uploading {form.progress.percentage}%</p>}
    </form></dialog>;
}

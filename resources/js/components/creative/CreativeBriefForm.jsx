import { useEffect, useId, useRef, useState } from "react";
import { useForm } from "@inertiajs/react";
import { ArrowLeft, ArrowRight, Send, ImagePlus } from "lucide-react";
import TicketAttachmentPicker from "@/components/TicketAttachmentPicker";

export const briefSections = [
    { title: "Project overview", short: "Overview", fields: [["overview", "What do you need designed?", "A short description of the request.", true], ["goal", "Goal", "What should this design or video achieve?"], ["audience", "Target audience", "Who is it for?"]] },
    { title: "Key message & content", short: "Content", fields: [["message", "Main message", "The most important thing the audience should understand."], ["copy", "Required copy", "Headlines, on-design text, contact details, or a link to approved text."], ["caption", "Caption", "The caption accompanying the post or video, including hashtags and mentions."], ["cta", "Call to action", "What should the audience do next?"]] },
    { title: "Deliverables", short: "Deliverables", fields: [] },
    { title: "Visual direction", short: "References", fields: [["style", "Preferred style or mood", "Clean, bold, warm, professional, playful…"], ["references", "Design inspiration or reference links", "Paste reference links, one per line."], ["include", "Must include", "Required logos, colours, imagery, or details."], ["avoid", "Avoid", "Styles, imagery, wording, or colours to avoid."]] },
    { title: "Assets", short: "Assets", fields: [["assets", "Link to logos, images, footage, copy & other files", "Use a shared folder or file link the creative team can access."], ["missing", "Anything still missing, and when will it be available?", "List missing assets and expected availability."]] },
    { title: "Timeline & approval", short: "Approval", fields: [["draft_due", "First draft due", "", false, "date"], ["feedback_due", "Feedback due", "", false, "date"], ["final_due", "Final delivery due", "Defaults to your project deadline.", false, "date"], ["approver", "Final approver", "Name or role of the person signing off.", true, "text"], ["budget", "Budget, if applicable", "Amount and currency, or not applicable.", false, "text"], ["notes", "Additional notes", "Anything else the creative team should know."]] },
];

export default function CreativeBriefForm({ owner, designTypes, onCancel }) {
    const [step, setStep] = useState(0);
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
    const form = useForm({ project: "", deadline: "", types: [], other: "", attachments: [], ...Object.fromEntries(briefSections.flatMap(section => section.fields.map(([key]) => [key, ""]))) });
    const dismiss = () => {
        if (form.processing) return;
        dialog.current.close();
        onCancel();
    };
    const field = ([key, label, placeholder, required, type]) => <label key={key} className={key === "caption" ? "cw-caption-field" : ""}>{label}{required ? " *" : ""}
        {type ? <input type={type} name={key} required={!!required} value={form.data[key]} onChange={e => form.setData(key, e.target.value)} placeholder={placeholder} maxLength={key === "approver" ? 160 : undefined} /> : <textarea name={key} required={!!required} rows={key === "caption" ? 4 : 3} value={form.data[key]} onChange={e => form.setData(key, e.target.value)} placeholder={placeholder} maxLength={key === "overview" ? 5000 : 10000} />}
        {key === "caption" && <small>This accompanies the post. Keep text placed on the design in Required copy.</small>}
        {form.errors[key] && <small className="cw-error">{form.errors[key]}</small>}
    </label>;
    const submit = e => {
        e.preventDefault();
        if (step < 5) { setStep(step + 1); return; }
        if (submitting.current) return;
        submitting.current = true;
        form.post("/creative-requests", { forceFormData: true, onFinish: () => { submitting.current = false; }, onError: errors => {
            const index = briefSections.findIndex(section => section.fields.some(([key]) => errors[key]));
            setStep(errors.project || errors.deadline ? 0 : errors.types || errors.other ? 2 : Object.keys(errors).some(key => key.startsWith("attachments")) ? 3 : Math.max(0, index));
        } });
    };
    return <dialog ref={dialog} className="cw-brief-dialog" aria-labelledby={titleId} onCancel={event => { event.preventDefault(); dismiss(); }}><form className="cw-brief cw-panel" onSubmit={submit} noValidate>
        <div className="cw-section-heading"><div><p className="cw-eyebrow">Creative request</p><h2 id={titleId}>New creative request</h2></div><button type="button" className="cw-text-button" onClick={dismiss} disabled={form.processing}>Close</button></div>
        <div className="cw-project-fields"><label>Project name *<input name="project" value={form.data.project} onChange={e => form.setData("project", e.target.value)} maxLength={160} placeholder="e.g. Study in New Zealand campaign" /></label><label>Deadline *<input type="date" name="deadline" value={form.data.deadline} onChange={e => form.setData("deadline", e.target.value)} /></label></div>
        <nav className="cw-steps" aria-label="Creative brief sections">{briefSections.map((section, i) => <button type="button" key={section.short} onClick={() => setStep(i)} aria-current={step === i ? "step" : undefined}><span>{String(i + 1).padStart(2, "0")}</span>{section.short}</button>)}</nav>
        {!!Object.keys(form.errors).length && <div className="cw-error-summary" role="alert">{Object.entries(form.errors).map(([key, value]) => <p key={key}>{value}</p>)}</div>}
        {!owner && <p className="cw-error-summary" role="alert">Sharry’s active account is unavailable. An administrator must check the assignment before you submit.</p>}
        {briefSections.map((section, i) => <section key={section.title} hidden={step !== i} aria-label={section.title}>
            <h3 className="cw-form-title">{section.title}</h3>
            <div className="cw-fields">{i === 2 ? <><fieldset><legend>Design type * <span className="cw-muted">Select all that apply</span></legend><div className="cw-design-types">{designTypes.map(type => <label key={type}><input type="checkbox" checked={form.data.types.includes(type)} onChange={e => form.setData("types", e.target.checked ? [...form.data.types, type] : form.data.types.filter(value => value !== type))} />{type}</label>)}</div></fieldset>{form.data.types.includes("Other") && field(["other", "Other design type", "Describe the format", true, "text"])}</> : section.fields.map(field)}
            {i === 3 && <div className="cw-reference-upload"><h3><ImagePlus size={18} /> Sample designs & reference attachments</h3><p className="cw-muted">Attach examples that show the direction you like.</p><TicketAttachmentPicker label="Sample designs / references" files={form.data.attachments} onChange={files => form.setData("attachments", files)} errors={form.errors} disabled={form.processing} /></div>}
            </div>
        </section>)}
        <footer className="cw-form-footer"><p className="cw-muted">Section {step + 1} of 6 · * Required<br /><small>Assigned to {owner?.name || "Sharry"} after submission.</small></p><div className="cw-actions">{step > 0 && <button type="button" className="cw-button cw-secondary" onClick={() => setStep(step - 1)}><ArrowLeft size={16} /> Back</button>}<button type="submit" className="cw-button" disabled={form.processing || !owner}>{step === 5 ? <><Send size={16} /> {form.processing ? "Submitting…" : "Submit creative request"}</> : <>Next: {briefSections[step + 1].short}<ArrowRight size={16} /></>}</button></div></footer>
        {form.progress && <p role="status">Uploading {form.progress.percentage}%</p>}
    </form></dialog>;
}

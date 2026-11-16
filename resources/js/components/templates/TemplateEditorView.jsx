import { useState, useEffect } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { ArrowLeft, Save, Trash2, Send, Mail, Smartphone, ImagePlus, X, AlertTriangle, Loader2, Copy, Eye, Wand2 } from "lucide-react";
import RichTextEditor from "@/components/templates/RichTextEditor";
// Statically imported (not lazy) so Vite pre-bundles react-email-editor at
// startup — an on-demand import of it fails the dev server's dep optimizer.
// The package is tiny (the editor itself loads at runtime), so no bundle cost.
import EmailBuilder from "@/components/templates/EmailBuilder";

const inp = "w-full px-3 py-2 rounded-lg border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-gray-300 focus:border-gray-300";

// Keys the app sends automatically by resolving the SHARED copy (department = '').
// Moving one of these off Shared silently stops that email — so we warn + confirm.
// (Stage-transition keys are intentionally excluded — their send job resolves by
// key across any department, so they're safe to move.)
const SYSTEM_TEMPLATE_KEYS = [
    "event_registration",
    "tracker_welcome",
    "doc_request",
    "doc_approved",
    "doc_rejected",
    "program_proposal",
    "consultancy_agreement",
];

// Live preview of a branding preset's banner/CTA, with a loading spinner while
// the (possibly newly-selected) image fetches — so switching branding gives
// clear feedback. Clicking it uploads a custom override.
function PresetPreview({ url, label, onPick }) {
    const [loading, setLoading] = useState(true);
    useEffect(() => { setLoading(true); }, [url]);
    return (
        <label className="relative block border border-gray-200 rounded-lg overflow-hidden bg-gray-50 cursor-pointer group">
            {loading && <span className="absolute inset-0 z-10 flex items-center justify-center bg-gray-50"><Loader2 size={18} className="animate-spin text-gray-400" /></span>}
            <img src={url} alt={label} onLoad={() => setLoading(false)} onError={() => setLoading(false)} className="w-full h-24 object-cover" />
            <span className="absolute bottom-1 left-1 bg-black/60 text-white text-[9px] font-bold px-1.5 py-0.5 rounded">{label}</span>
            <span className="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/40 text-white opacity-0 group-hover:opacity-100 text-[11px] font-bold transition-all">Upload to override</span>
            <input type="file" accept="image/*" onChange={onPick} className="hidden" />
        </label>
    );
}

/**
 * Shared template editor, reused by the admin area and every department
 * portal. `basePath` drives every link/submit so the same form works under
 * /admin/message-templates and /portal/<role>/email-templates.
 *
 * The department selector uses the current library tab or folder as a default.
 */
export default function TemplateEditorView({
    template = null,
    standardVariables = [],
    basePath = "/admin/message-templates",
    departmentOptions = null,
    brandingOptions = [],
    fixedDepartment = null,
    defaultChannel = null,
    defaultFolderId = null,
    duplicatedFrom = null,
}) {
    // A duplicate arrives as a prefilled template with no id + a blank key, so
    // it must still create (POST), not update. Key on id, not on presence.
    const editing = !!template?.id;
    // A saved-but-keyless template is a draft from a folder duplicate: its key is
    // still editable (set once), even though it's an existing row.
    const isDraft = editing && !template?.key;
    const keyLocked = editing && !isDraft;
    const initialChannels = template?.channels ?? (defaultChannel ? [defaultChannel] : ["email"]);
    const form = useForm({
        key: template?.key ?? "",
        department: template?.department ?? fixedDepartment ?? "",
        // Only meaningful on create (store validates it); the update endpoint
        // ignores it — folder moves go through the list's Move action instead.
        folder_id: template?.folder_id ?? defaultFolderId ?? null,
        name: template?.name ?? "",
        description: template?.description ?? "",
        channels: initialChannels,
        email_subject: template?.email_subject ?? "",
        from_email: template?.from_email ?? "",
        from_name: template?.from_name ?? "",
        reply_to_email: template?.reply_to_email ?? "",
        reply_to_name: template?.reply_to_name ?? "",
        email_body: template?.email_body ?? "",
        design_json: template?.design_json ?? null,
        sms_body: template?.sms_body ?? "",
        // Branding preset defaults to the template's own Department (a Sales
        // template → Sales branding), falling back to the portal you're in for a
        // brand-new template. Shared/global → default ePathways artwork.
        branding: template?.branding ?? ((template?.department ?? fixedDepartment ?? "") || "default"),
        to_extra: template?.to_extra ?? "",
        cc: template?.cc ?? "",
        bcc: template?.bcc ?? "",
        banner_image: null,
        footer_image: null,
        remove_banner: false,
        remove_footer: false,
        is_active: template?.is_active ?? true,
    });
    const { data, setData, errors, processing } = form;

    const [testEmail, setTestEmail] = useState("");
    const [previewOpen, setPreviewOpen] = useState(false);
    const [builderOpen, setBuilderOpen] = useState(false);
    // Object-URL previews for freshly-picked (not-yet-saved) images.
    const [previews, setPreviews] = useState({ banner_image: null, footer_image: null });

    // Pick a new branding image → stage the File + a local preview, and
    // clear any pending "remove" flag for that slot.
    const pickImage = (field) => (e) => {
        const file = e.target.files?.[0] || null;
        setData(field, file);
        setData(field === "banner_image" ? "remove_banner" : "remove_footer", false);
        setPreviews((p) => ({ ...p, [field]: file ? URL.createObjectURL(file) : null }));
    };

    // Clear a branding image: drop any staged File + preview, and mark the
    // saved one for removal so the server deletes it on save.
    const clearImage = (field) => () => {
        setData(field, null);
        setData(field === "banner_image" ? "remove_banner" : "remove_footer", true);
        setPreviews((p) => ({ ...p, [field]: null }));
    };

    // What to show in a slot: the new preview, else the saved image (unless
    // it's been marked for removal).
    const shownImage = (field) => {
        if (previews[field]) return previews[field];
        const removed = field === "banner_image" ? data.remove_banner : data.remove_footer;
        if (removed) return null;
        return field === "banner_image" ? template?.banner_image_url : template?.footer_image_url;
    };
    // The selected branding preset's live preview — shown in a slot when no
    // custom image overrides it, so picking Education shows Education's art.
    const brandingOpt = brandingOptions.find((o) => o.value === data.branding) || {};
    const presetPreview = (field) => (field === "banner_image" ? brandingOpt.banner_url : brandingOpt.footer_url);

    // Build an approximate rendered email for the Preview modal: the selected
    // branding's banner on top, the body (with sample variables filled) in the
    // middle, and the footer below — mirroring the branded shell used on send.
    const buildPreviewHtml = () => {
        const sample = {
            first_name: "Angi", last_name: "Libanan", full_name: "Angi Libanan",
            email: "client@example.com", phone: "+64 21 000 0000",
            tracker_url: "#", engagement_url: "#", client_portal_url: "#",
            visa_type: "Skilled Migrant Category Resident Visa", password: "Xy7$kP2m", invoice_number: "INV-0117",
            assigned_staff_name: "the EP team", status: "In progress", status_detail: "a quick update",
            event_name: "Sample Event", event_date: "1 Jan 2026", event_time: "10:00 AM", event_location: "Auckland",
            document_name: "Sample Document.pdf", reason: "sample reason",
        };
        const fill = (t) => String(t || "").replace(/\{\{\s*(\w+)\s*\}\}/g, (_, k) => (k in sample ? sample[k] : ""));
        // A visual-builder email is already a complete document — preview it as-is,
        // exactly as it sends (no branded shell).
        if (data.design_json) {
            return fill(data.email_body);
        }
        const shownBanner = previews.banner_image || (!data.remove_banner && (template?.banner_image_url || brandingOpt.banner_url));
        const shownFooter = previews.footer_image || (!data.remove_footer && (template?.footer_image_url || brandingOpt.footer_url));
        const banner = shownBanner ? `<img src="${shownBanner}" style="display:block;width:100%;max-width:600px;height:auto;border:0;">` : "";
        const footer = shownFooter ? `<img src="${shownFooter}" style="display:block;width:100%;max-width:600px;height:auto;border:0;">` : "";
        return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#eef0f4;font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:#333;} .wrap{max-width:600px;margin:0 auto;background:#fff;} .pad{padding:28px 36px;font-size:14px;line-height:1.6;} img{max-width:100%;} table{max-width:100%;}</style></head><body><div class="wrap">${banner}<div class="pad">${fill(data.email_body)}</div>${footer}</div></body></html>`;
    };
    const showDeptSelector = !!departmentOptions;
    // Warn when a system-triggered template is being scoped off Shared.
    const systemKeyOffShared = SYSTEM_TEMPLATE_KEYS.includes(data.key) && data.department !== "";

    const toggleChannel = (ch) =>
        setData("channels", data.channels.includes(ch) ? data.channels.filter((c) => c !== ch) : [...data.channels, ch]);

    const submit = (e) => {
        e.preventDefault();
        // Guardrail: confirm before scoping a system-triggered template off Shared.
        if (systemKeyOffShared && !window.confirm(
            `"${data.key}" is sent automatically by the system, which looks up the Shared copy.\n\n` +
            `Moving it to a department will silently stop these emails from sending.\n\n` +
            `Continue anyway?`
        )) {
            return;
        }
        // Only send multipart when an image is actually staged — a plain JSON
        // request keeps booleans/arrays native (FormData stringifies `true`,
        // which fails Laravel's boolean rule). When multipart IS needed, PUT is
        // method-spoofed (PHP won't parse a real multipart PUT body) and the
        // booleans are coerced to 1/0 so validation still passes.
        // NOTE: form.transform() returns undefined in the React adapter, so it
        // must be called as its own statement — never chained before post/put.
        const hasFile = data.banner_image instanceof File || data.footer_image instanceof File;

        if (hasFile) {
            form.transform((d) => ({
                ...d,
                is_active: d.is_active ? 1 : 0,
                remove_banner: d.remove_banner ? 1 : 0,
                remove_footer: d.remove_footer ? 1 : 0,
                ...(editing ? { _method: "put" } : {}),
            }));
            form.post(editing ? `${basePath}/${template.id}` : basePath, {
                preserveScroll: true,
                forceFormData: true,
            });
        } else {
            form.transform((d) => d); // reset any prior transform
            if (editing) form.put(`${basePath}/${template.id}`, { preserveScroll: true });
            else form.post(basePath, { preserveScroll: true });
        }
    };

    const remove = () => {
        if (!window.confirm("Delete this template?")) return;
        router.delete(`${basePath}/${template.id}`);
    };

    const sendTest = () => {
        // Save the template first so the test reflects unsaved edits (Cc/Bcc,
        // body, branding…) — the test endpoint sends the SAVED template.
        const fire = () => router.post(`${basePath}/${template.id}/test`, { email: testEmail || null }, { preserveScroll: true });
        const hasFile = data.banner_image instanceof File || data.footer_image instanceof File;

        if (hasFile) {
            form.transform((d) => ({
                ...d,
                is_active: d.is_active ? 1 : 0,
                remove_banner: d.remove_banner ? 1 : 0,
                remove_footer: d.remove_footer ? 1 : 0,
                _method: "put",
            }));
            form.post(`${basePath}/${template.id}`, { preserveScroll: true, forceFormData: true, onSuccess: fire });
        } else {
            form.transform((d) => d);
            form.put(`${basePath}/${template.id}`, { preserveScroll: true, onSuccess: fire });
        }
    };

    const smsLen = data.sms_body.length;
    const segments = Math.ceil(smsLen / 160) || 0;

    return (
        <div className="space-y-6 max-w-4xl mx-auto">
            <Head title={editing ? `Edit ${template.name}` : (defaultChannel === "sms" ? "New SMS Template" : "New Template")} />

            <Link href={basePath} className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800">
                <ArrowLeft size={15} /> Back to templates
            </Link>

            {duplicatedFrom && (
                <div className="flex items-start gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    <Copy size={15} className="mt-0.5 shrink-0" />
                    <span>
                        Duplicating <strong>{duplicatedFrom}</strong>. Give it a new <strong>key</strong> (left blank on purpose) before saving. Uploaded banner/footer images aren&rsquo;t carried over — re-upload them here if you need them.
                    </span>
                </div>
            )}

            {isDraft && (
                <div className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <AlertTriangle size={15} className="mt-0.5 shrink-0" />
                    <span>
                        This is a <strong>draft copy</strong> from a duplicated folder. Give it a <strong>key</strong> and save — it stays inactive and can&rsquo;t be sent until it has one. Uploaded banner/footer images aren&rsquo;t carried over.
                    </span>
                </div>
            )}

            <form onSubmit={submit} className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-5">
                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Key {keyLocked && <span className="text-gray-400">(immutable)</span>}{isDraft && <span className="text-indigo-500">(set a key)</span>}</span>
                                <input value={data.key} onChange={(e) => setData("key", e.target.value)} disabled={keyLocked} placeholder="welcome_follow_up" className={`${inp} ${keyLocked ? "bg-gray-50 text-gray-500" : ""}`} />
                                {!keyLocked && <span className="text-[11px] text-gray-500 block mt-1">Required. Use lowercase letters, numbers and underscores.</span>}
                                {errors.key && <span className="text-xs text-rose-600">{errors.key}</span>}
                            </label>
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Name</span>
                                <input value={data.name} onChange={(e) => setData("name", e.target.value)} className={inp} />
                                {errors.name && <span className="text-xs text-rose-600">{errors.name}</span>}
                            </label>
                        </div>
                        {showDeptSelector && (
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Department</span>
                                <select value={data.department} onChange={(e) => { const v = e.target.value; setData("department", v); setData("branding", v || "default"); }} className={inp}>
                                    {departmentOptions.map((o) => (
                                        <option key={o.value} value={o.value}>{o.label}</option>
                                    ))}
                                </select>
                                {errors.department && <span className="text-xs text-rose-600">{errors.department}</span>}
                                {systemKeyOffShared && (
                                    <span className="mt-2 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                                        <span>
                                            <strong>"{data.key}"</strong> is a system-triggered template. The app sends the <strong>Shared</strong> copy — moving it to a department will silently stop these emails. Keep it on <strong>Shared (all departments)</strong> unless you intend to disable it.
                                        </span>
                                    </span>
                                )}
                            </label>
                        )}
                        <label className="block">
                            <span className="block text-xs font-semibold text-gray-600 mb-1">Description</span>
                            <input value={data.description} onChange={(e) => setData("description", e.target.value)} className={inp} />
                        </label>
                        <div className="flex items-center gap-4">
                            <span className="text-xs font-semibold text-gray-600">Channels:</span>
                            <label className="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" checked={data.channels.includes("email")} onChange={() => toggleChannel("email")} /> <Mail size={14} /> Email</label>
                            <label className="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" checked={data.channels.includes("sms")} onChange={() => toggleChannel("sms")} /> <Smartphone size={14} /> SMS</label>
                            <label className="inline-flex items-center gap-1.5 text-sm ml-auto"><input type="checkbox" checked={data.is_active} onChange={(e) => setData("is_active", e.target.checked)} /> Active</label>
                        </div>
                    </div>

                    {data.channels.includes("email") && (
                        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-4">
                            <h3 className="text-sm font-bold text-gray-700 flex items-center gap-1.5"><Mail size={15} /> Email</h3>
                            <label className="block">
                                <span className="block text-xs font-semibold text-gray-600 mb-1">Subject</span>
                                <input value={data.email_subject} onChange={(e) => setData("email_subject", e.target.value)} className={inp} />
                            </label>
                            <div className="grid grid-cols-2 gap-4">
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">From address <span className="text-gray-400 font-normal">(optional)</span></span>
                                    <input value={data.from_email} onChange={(e) => setData("from_email", e.target.value)} placeholder="hello@epathways.ph" className={inp} />
                                    {errors.from_email && <span className="text-xs text-rose-600">{errors.from_email}</span>}
                                </label>
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">From name <span className="text-gray-400 font-normal">(optional)</span></span>
                                    <input value={data.from_name} onChange={(e) => setData("from_name", e.target.value)} placeholder="EP" className={inp} />
                                </label>
                                <p className="col-span-2 text-[11px] text-gray-400 -mt-2">Blank = default sender. The address must be verified in your mail provider (Brevo).</p>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Reply-to address <span className="text-gray-400 font-normal">(optional)</span></span>
                                    <input value={data.reply_to_email} onChange={(e) => setData("reply_to_email", e.target.value)} placeholder="you@luvep.com" className={inp} />
                                    {errors.reply_to_email && <span className="text-xs text-rose-600">{errors.reply_to_email}</span>}
                                </label>
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Reply-to name <span className="text-gray-400 font-normal">(optional)</span></span>
                                    <input value={data.reply_to_name} onChange={(e) => setData("reply_to_name", e.target.value)} placeholder="Your Name - EP" className={inp} />
                                    {errors.reply_to_name && <span className="text-xs text-rose-600">{errors.reply_to_name}</span>}
                                </label>
                                <p className="col-span-2 text-[11px] text-gray-400 -mt-2">Where client replies land. Blank = the shared reply inbox, then the From address.</p>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <label className="block col-span-2">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">To — also send to <span className="text-gray-400 font-normal">(optional)</span></span>
                                    <input value={data.to_extra} onChange={(e) => setData("to_extra", e.target.value)} placeholder="team@luvep.com, another@…" className={inp} />
                                    {errors.to_extra && <span className="text-xs text-rose-600">{errors.to_extra}</span>}
                                    <span className="block text-[11px] text-gray-400 mt-1">The client is always the main recipient. These extra addresses are added to the To line on every send (e.g. so your internal team gets the same email).</span>
                                </label>
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Cc <span className="text-gray-400 font-normal">(optional)</span></span>
                                    <input value={data.cc} onChange={(e) => setData("cc", e.target.value)} placeholder="team@luvep.com, other@…" className={inp} />
                                    {errors.cc && <span className="text-xs text-rose-600">{errors.cc}</span>}
                                </label>
                                <label className="block">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Bcc <span className="text-gray-400 font-normal">(optional)</span></span>
                                    <input value={data.bcc} onChange={(e) => setData("bcc", e.target.value)} placeholder="records@luvep.com" className={inp} />
                                    {errors.bcc && <span className="text-xs text-rose-600">{errors.bcc}</span>}
                                </label>
                                <p className="col-span-2 text-[11px] text-gray-400 -mt-2">Comma-separated. These addresses are copied on every send of this template.</p>
                            </div>
                            {/* Plain div, NOT a <label>: a label forwards clicks to its first
                                control (the Customize button), which would open the builder
                                whenever the editor canvas is clicked. */}
                            <div className="block">
                                <div className="flex items-center justify-between mb-1">
                                    <span className="block text-xs font-semibold text-gray-600">Body</span>
                                    <div className="flex items-center gap-3">
                                        <button type="button" onClick={() => setBuilderOpen(true)} className="text-[11px] font-bold text-white bg-[#436235] hover:bg-[#375029] inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg"><Wand2 size={12} /> Customize email body</button>
                                        <button type="button" onClick={() => setPreviewOpen(true)} className="text-[11px] font-semibold text-[#436235] hover:text-[#375029] inline-flex items-center gap-1"><Eye size={12} /> Preview</button>
                                    </div>
                                </div>
                                {data.design_json ? (
                                    <div className="rounded-lg border border-gray-200 overflow-hidden bg-gray-100">
                                        <div className="flex items-center gap-2 px-3 py-2 border-b border-gray-200 bg-white text-[11px] text-gray-600">
                                            <Wand2 size={13} className="text-[#436235]" />
                                            <span>Built with the visual editor — click <strong>Customize email body</strong> above to edit it.</span>
                                        </div>
                                        <iframe title="Email body" srcDoc={data.email_body} sandbox="" className="w-full bg-gray-100" style={{ height: 480 }} />
                                    </div>
                                ) : (
                                    <>
                                        <RichTextEditor value={data.email_body} onChange={(html) => setData("email_body", html)} />
                                        <span className="block text-[11px] text-gray-400 mt-1">Format with the toolbar, or use <strong>Customize email body</strong> for a drag-and-drop design.</span>
                                    </>
                                )}
                            </div>

                            {/* Optional branding — banner header + footer CTA image.
                                Left blank, the email uses the default ePathways
                                banner and consultation footer. */}
                            <div className="pt-3 border-t border-gray-100">
                                <label className="block mb-3">
                                    <span className="block text-xs font-semibold text-gray-600 mb-1">Branding</span>
                                    <select value={data.branding} onChange={(e) => setData("branding", e.target.value)} className={inp}>
                                        {brandingOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                    </select>
                                    <span className="block text-[11px] text-gray-400 mt-1">Which portal's banner &amp; CTA image to use. Defaults to your current portal.</span>
                                </label>
                                <p className="text-xs font-semibold text-gray-600 mb-1">Custom images <span className="text-gray-400 font-normal">(optional — override the branding above)</span></p>
                                <p className="text-[11px] text-gray-400 mb-3">Leave blank to use the selected branding's banner &amp; footer. The body sits between them.</p>
                                <div className="grid grid-cols-2 gap-4">
                                    {[
                                        { field: "banner_image", label: "Banner (top)", hint: "Wide header, ~600px" },
                                        { field: "footer_image", label: "CTA image", hint: "Above the contact block" },
                                    ].map(({ field, label, hint }) => {
                                        const src = shownImage(field);
                                        const err = errors[field];
                                        return (
                                            <div key={field}>
                                                <span className="block text-[11px] font-semibold text-gray-500 mb-1">{label}</span>
                                                {src ? (
                                                    <div className="relative border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                                                        <img src={src} alt={label} className="w-full h-24 object-cover" />
                                                        <button type="button" onClick={clearImage(field)}
                                                            className="absolute top-1 right-1 bg-white/90 hover:bg-white text-rose-600 rounded-full p-1 shadow-sm"
                                                            aria-label={`Remove ${label}`}>
                                                            <X size={13} />
                                                        </button>
                                                    </div>
                                                ) : presetPreview(field) ? (
                                                    <PresetPreview url={presetPreview(field)} label={brandingOpt.label} onPick={pickImage(field)} />
                                                ) : (
                                                    <label className="flex flex-col items-center justify-center gap-1 h-24 border border-dashed border-gray-300 rounded-lg cursor-pointer text-gray-400 hover:border-gray-400 hover:text-gray-500 transition-colors">
                                                        <ImagePlus size={18} />
                                                        <span className="text-[11px]">Upload image</span>
                                                        <input type="file" accept="image/*" onChange={pickImage(field)} className="hidden" />
                                                    </label>
                                                )}
                                                <p className="text-[10px] text-gray-400 mt-1">{hint}</p>
                                                {err && <span className="text-[11px] text-rose-600">{err}</span>}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    )}

                    {data.channels.includes("sms") && (
                        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-2">
                            <h3 className="text-sm font-bold text-gray-700 flex items-center gap-1.5"><Smartphone size={15} /> SMS</h3>
                            <textarea value={data.sms_body} onChange={(e) => setData("sms_body", e.target.value)} rows={3} className={`${inp}`} />
                            <p className={`text-xs ${segments > 1 ? "text-amber-600" : "text-gray-400"}`}>{smsLen} chars · {segments} segment{segments === 1 ? "" : "s"}{segments > 1 ? " (multi-segment costs more)" : ""}</p>
                        </div>
                    )}

                    {Object.keys(errors).length > 0 && (
                        <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            <ul className="list-disc pl-4">
                                {Object.entries(errors).map(([field, message]) => <li key={field}>{message}</li>)}
                            </ul>
                        </div>
                    )}

                    <div className="flex items-center gap-3">
                        <button type="submit" disabled={processing} className="px-4 py-2 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-black flex items-center gap-2 disabled:opacity-50">
                            <Save size={15} /> {editing ? "Save changes" : "Create template"}
                        </button>
                        {editing && (
                            <button type="button" onClick={remove} className="px-4 py-2 text-rose-600 text-sm font-medium hover:bg-rose-50 rounded-xl flex items-center gap-2">
                                <Trash2 size={15} /> Delete
                            </button>
                        )}
                    </div>
                </div>

                {/* Sidebar: variables + test send */}
                <div className="space-y-5">
                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 mb-3">Variables</h3>
                        <p className="text-[11px] text-gray-400 mb-2">Use <code>{"{{name}}"}</code> in any field.</p>
                        <ul className="space-y-1.5">
                            {standardVariables.map((v) => (
                                <li key={v.name} className="text-xs">
                                    <code className="bg-gray-100 rounded px-1 py-0.5 text-gray-700">{`{{${v.name}}}`}</code>
                                    <span className="text-gray-400 block mt-0.5">{v.description}</span>
                                </li>
                            ))}
                            {(template?.variables_documented ?? []).filter((v) => !standardVariables.some((s) => s.name === v.name)).map((v) => (
                                <li key={v.name} className="text-xs">
                                    <code className="bg-amber-50 rounded px-1 py-0.5 text-amber-700">{`{{${v.name}}}`}</code>
                                    <span className="text-gray-400 block mt-0.5">{v.description}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {editing && (
                        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                            <h3 className="text-xs font-bold uppercase tracking-wider text-gray-500 mb-3">Send test</h3>
                            <input value={testEmail} onChange={(e) => setTestEmail(e.target.value)} placeholder="you@example.com (or blank = your email)" className={`${inp} mb-2`} />
                            <button type="button" onClick={sendTest} className="w-full px-3 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-xl hover:bg-emerald-700 flex items-center justify-center gap-2">
                                <Send size={14} /> Send test
                            </button>
                        </div>
                    )}
                </div>
            </form>

            {builderOpen && (
                <EmailBuilder
                    initialDesign={data.design_json}
                    initialHtml={data.email_body}
                    uploadUrl={`${basePath}/upload-image`}
                    onSave={(html, design) => { setData("email_body", html); setData("design_json", design); setBuilderOpen(false); }}
                    onClose={() => setBuilderOpen(false)}
                />
            )}

            {previewOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4" onClick={() => setPreviewOpen(false)}>
                    <div className="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" />
                    <div className="relative bg-white rounded-2xl shadow-xl border border-gray-100 w-full max-w-2xl h-[82vh] flex flex-col overflow-hidden" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                            <h3 className="text-sm font-bold text-gray-900 flex items-center gap-2"><Eye size={16} /> Email preview</h3>
                            <button type="button" onClick={() => setPreviewOpen(false)} className="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"><X size={18} /></button>
                        </div>
                        {data.email_subject && <div className="px-5 py-2 border-b border-gray-100 text-sm"><span className="text-gray-400">Subject:</span> <span className="font-semibold text-gray-800">{data.email_subject.replace(/\{\{\s*first_name\s*\}\}/g, "Angi")}</span></div>}
                        <iframe title="Email preview" srcDoc={buildPreviewHtml()} className="flex-1 w-full bg-[#eef0f4]" sandbox="" />
                        <p className="px-5 py-2 text-[11px] text-gray-400 border-t border-gray-100">Approximate preview with sample values. The banner &amp; footer come from the selected branding; the live email may vary slightly.</p>
                    </div>
                </div>
            )}
        </div>
    );
}

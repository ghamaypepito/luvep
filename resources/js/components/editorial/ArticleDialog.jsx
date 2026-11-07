import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { ExternalLink, X } from 'lucide-react';
import ArticleBodyEditor from './ArticleBodyEditor';

const articlesPath = '/admin/content-calendar/articles';
const requestHeaders = { Accept: 'application/json' };
const statuses = {
    draft: ['Draft', 'bg-gray-100 text-gray-700'],
    in_review: ['In review', 'bg-amber-100 text-amber-900'],
    approved: ['Approved', 'bg-blue-100 text-blue-900'],
    published: ['Published', 'bg-green-100 text-green-900'],
};
const actionLabels = { submit: 'Submit for review', approve: 'Approve', publish: 'Publish' };
const successMessages = {
    save: 'Draft saved.',
    submit: 'Submitted for review.',
    approve: 'Revision approved.',
    publish: 'Article published.',
};
const draftFields = [
    'title', 'slug', 'planned_date', 'pillar', 'summary', 'body_html', 'primary_keyword',
    'meta_title', 'meta_description', 'author', 'image_url', 'image_alt',
];
const pillars = ['Study', 'Immigration', 'Employment', 'Settlement'];
const briefFields = {
    prompt: 'Writing prompt',
    leadSubject: 'Lead email subject',
    clientSubject: 'Client email subject',
    social: 'Social direction',
    feature: 'Featured image direction',
    assetId: 'Asset reference',
};
const controlClass = 'block min-h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus-visible:outline-2 focus-visible:outline-green-700 aria-invalid:border-red-700 read-only:bg-gray-50 disabled:opacity-60';
const focusClass = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700';
const secondaryButton = `inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-3.5 text-sm font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50 ${focusClass}`;
const primaryButton = `inline-flex min-h-10 items-center justify-center rounded-md bg-green-800 px-3.5 text-sm font-semibold text-white hover:bg-green-900 disabled:opacity-50 ${focusClass}`;

const emptyDraft = Object.fromEntries(draftFields.map((field) => [field, '']));
const valuesFor = (article) => Object.fromEntries(draftFields.map((field) => [field, article[field] ?? '']));
const slugFor = (title) => title.toLowerCase().normalize('NFKD').replace(/\p{M}/gu, '')
    .replace(/[^a-z0-9]+/g, '-').slice(0, 180).replace(/^-|-$/g, '');

export function ArticleStatus({ status }) {
    const [label, tone] = statuses[status] ?? statuses.draft;
    return <span className={`inline-flex w-fit rounded px-1.5 py-0.5 text-[11px] leading-4 font-semibold ${tone}`}>{label}</span>;
}

function Field({ name, label, value, onChange, error, options, rows, hint, ...props }) {
    const id = `article-${name}`;
    const controlProps = {
        id,
        name,
        value,
        onChange: (event) => onChange(name, event.target.value),
        'aria-invalid': Boolean(error),
        'aria-describedby': error ? `${id}-error` : hint ? `${id}-hint` : undefined,
        className: controlClass,
        ...props,
    };

    return (
        <div className="min-w-0">
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-gray-900">{label}</label>
            {options ? (
                <select {...controlProps}>
                    <option value="">Select a pillar</option>
                    {options.map((option) => <option key={option} value={option}>{option}</option>)}
                </select>
            ) : rows ? <textarea {...controlProps} rows={rows} /> : <input {...controlProps} />}
            {hint && <p id={`${id}-hint`} className="mt-1 text-xs text-gray-600">{hint}</p>}
            {error && <p id={`${id}-error`} className="mt-1 text-sm text-red-800">{error}</p>}
        </div>
    );
}

function FeaturedImage({ url, alt }) {
    const [failed, setFailed] = useState(false);
    if (failed) return <p className="mt-3 text-sm text-gray-700">The image could not be loaded. Check the featured image URL.</p>;
    return (
        <figure className="mt-4">
            <img src={url} alt={alt || 'Featured image preview'} referrerPolicy="no-referrer" onError={() => setFailed(true)}
                className="aspect-video w-full max-w-md rounded-md border border-gray-200 bg-gray-50 object-contain" />
            <figcaption className="mt-1.5 text-xs text-gray-600">Featured image preview</figcaption>
        </figure>
    );
}

export default function ArticleDialog({ articleId, canPublish, onClose, onSaved }) {
    const dialog = useRef(null);
    const closeButton = useRef(null);
    const titleInput = useRef(null);
    const feedback = useRef(null);
    const [article, setArticle] = useState(null);
    const [form, setForm] = useState(emptyDraft);
    const [loadAttempt, setLoadAttempt] = useState(0);
    const [loading, setLoading] = useState(Boolean(articleId));
    const [loadError, setLoadError] = useState('');
    const [pending, setPending] = useState('');
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState({});
    const [savedMessage, setSavedMessage] = useState('');
    const [confirmPublish, setConfirmPublish] = useState(false);
    const [customSlug, setCustomSlug] = useState(false);
    const [previewUrl, setPreviewUrl] = useState('');
    const status = article?.status ?? 'draft';
    const dirty = draftFields.some((field) => form[field] !== (article?.[field] ?? ''));
    const stageAction = {
        draft: 'submit',
        in_review: canPublish ? 'approve' : null,
        approved: canPublish ? 'publish' : null,
        published: null,
    }[status];

    useEffect(() => {
        dialog.current.showModal();
        closeButton.current.focus();
    }, []);

    useEffect(() => {
        if (!articleId) return;
        const controller = new AbortController();
        axios.get(`${articlesPath}/${articleId}`, { headers: requestHeaders, signal: controller.signal })
            .then(({ data }) => {
                setArticle(data);
                setForm(valuesFor(data));
            })
            .catch((error) => {
                if (!axios.isCancel(error)) setLoadError('The article could not be loaded. Try again.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [articleId, loadAttempt]);

    useEffect(() => {
        if (!articleId) titleInput.current?.focus();
    }, [articleId]);

    useEffect(() => {
        const timer = setTimeout(() => {
            try {
                const url = new URL(form.image_url);
                setPreviewUrl(url.protocol === 'https:' ? url.href : '');
            } catch {
                setPreviewUrl('');
            }
        }, 350);
        return () => clearTimeout(timer);
    }, [form.image_url]);

    useEffect(() => {
        if (message) feedback.current?.focus();
    }, [message]);

    function change(field, value) {
        if (field === 'slug') setCustomSlug(true);
        setForm((previous) => ({
            ...previous,
            [field]: value,
            ...(!article && !customSlug && field === 'title' ? { slug: slugFor(value) } : {}),
        }));
        setErrors((previous) => ({ ...previous, [field]: undefined }));
        setSavedMessage('');
        setConfirmPublish(false);
    }

    function close() {
        if (pending || (dirty && !window.confirm('Discard unsaved changes to this article?'))) return;
        dialog.current.close();
        onClose();
    }

    async function write(action) {
        if (pending) return;
        setPending(action);
        setMessage('');
        setErrors({});
        setSavedMessage('');
        try {
            const url = article ? `${articlesPath}/${article.id}` : articlesPath;
            const response = action === 'save'
                ? await axios.request({
                    method: article ? 'PUT' : 'POST',
                    url,
                    headers: requestHeaders,
                    data: { ...form, ...(article ? { revision: article.revision } : {}) },
                })
                : await axios.post(`${url}/${action}`, { revision: article.revision }, { headers: requestHeaders });

            setArticle(response.data);
            setForm(valuesFor(response.data));
            setSavedMessage(successMessages[action]);
            setConfirmPublish(false);
            onSaved();
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors ?? {});
                setMessage(action === 'submit'
                    ? 'Complete the highlighted fields, save the draft, then submit it for review.'
                    : 'Check the highlighted fields and save again.');
            } else {
                setMessage(error.response?.data?.message ?? 'The request could not be completed. Try again.');
            }
        } finally {
            setPending('');
        }
    }

    const firstError = (name) => errors[name]?.[0];
    const field = (name, label, props = {}) => (
        <Field name={name} label={label} value={form[name]} onChange={change} error={firstError(name)} {...props} />
    );

    return (
        <dialog
            ref={dialog}
            aria-labelledby="article-dialog-title"
            onCancel={(event) => { event.preventDefault(); close(); }}
            className="m-auto max-h-[calc(100dvh_-_1.5rem)] w-[calc(100%_-_1.5rem)] max-w-5xl overflow-hidden rounded-xl bg-white text-gray-900 shadow-xl backdrop:bg-gray-950/45 sm:max-h-[calc(100dvh_-_3rem)]"
        >
            <div className="flex max-h-[calc(100dvh_-_1.5rem)] flex-col sm:max-h-[calc(100dvh_-_3rem)]">
                <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 px-4 py-4 sm:px-6">
                    <div className="min-w-0">
                        <h2 id="article-dialog-title" className="text-lg font-semibold tracking-tight">{articleId ? 'Edit article' : 'New article'}</h2>
                        <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <ArticleStatus status={status} />
                            {article && <span className="text-xs text-gray-600">Revision {article.revision}</span>}
                            {article?.published_slug && (
                                <a href={`/articles/${article.published_slug}`} target="_blank" rel="noopener" className={`inline-flex items-center gap-1.5 text-xs font-semibold text-green-800 hover:underline ${focusClass}`}>
                                    View live article <ExternalLink size={13} aria-hidden="true" />
                                </a>
                            )}
                        </div>
                    </div>
                    <button type="button" ref={closeButton} onClick={close} disabled={Boolean(pending)} aria-label="Close article editor"
                        className={`grid size-9 shrink-0 place-items-center rounded-md text-gray-700 hover:bg-gray-100 disabled:opacity-50 ${focusClass}`}>
                        <X size={20} aria-hidden="true" />
                    </button>
                </header>
                {loading ? <p role="status" className="px-4 py-7 text-sm text-gray-700 sm:px-6">Loading article…</p> : loadError ? (
                    <div className="px-4 py-6 sm:px-6">
                        <p role="alert" className="mb-3 text-sm text-red-800">{loadError}</p>
                        <button type="button" className={secondaryButton} onClick={() => {
                            setLoading(true);
                            setLoadError('');
                            setLoadAttempt((attempt) => attempt + 1);
                        }}>Try again</button>
                    </div>
                ) : (
                    <form onSubmit={(event) => { event.preventDefault(); write('save'); }} className="flex min-h-0 flex-1 flex-col">
                        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                            {message && (
                                <div ref={feedback} role="alert" tabIndex={-1} className="mx-4 mt-4 rounded-md bg-red-50 px-3 py-2.5 text-sm text-red-900 sm:mx-6">
                                    {message}
                                </div>
                            )}
                            <fieldset disabled={Boolean(pending)} className="grid min-w-0 gap-y-6 md:grid-cols-[minmax(0,1fr)_minmax(15rem,0.6fr)] md:gap-y-0">
                                <div className="min-w-0 space-y-5 px-4 pt-5 pb-1 sm:px-6 md:pb-6">
                                    {field('title', 'Title', { maxLength: 200, required: true, ref: titleInput })}
                                    {field('summary', 'Summary', { maxLength: 600, rows: 3 })}
                                    <ArticleBodyEditor value={form.body_html} onChange={(value) => change('body_html', value)} disabled={Boolean(pending)} error={firstError('body_html')} />

                                    <section aria-labelledby="article-seo-heading" className="space-y-4 border-t border-gray-200 pt-5">
                                        <h3 id="article-seo-heading" className="text-base font-semibold">Search and article details</h3>
                                        {field('slug', 'URL slug', {
                                            maxLength: 180, required: true, readOnly: Boolean(article?.published_slug),
                                            pattern: '[a-z0-9]+(?:-[a-z0-9]+)*',
                                            hint: article?.published_slug ? 'The URL is fixed after first publication.' : 'Use lowercase letters, numbers and hyphens.',
                                        })}
                                        {field('author', 'Author', { maxLength: 120 })}
                                        {field('primary_keyword', 'Primary keyword', { maxLength: 180 })}
                                        {field('meta_title', 'Meta title', { maxLength: 100 })}
                                        {field('meta_description', 'Meta description', { maxLength: 220, rows: 3 })}
                                    </section>

                                    <section aria-labelledby="article-image-heading" className="space-y-4 border-t border-gray-200 pt-5">
                                        <h3 id="article-image-heading" className="text-base font-semibold">Featured image</h3>
                                        {field('image_url', 'Featured image URL', { type: 'url', maxLength: 2000, hint: 'Use an HTTPS link to the image.' })}
                                        {field('image_alt', 'Image alt text', { maxLength: 250, hint: 'Describe the image for readers who use assistive technology.' })}
                                        {previewUrl && <FeaturedImage key={previewUrl} url={previewUrl} alt={form.image_alt} />}
                                    </section>
                                </div>

                                <aside aria-labelledby="article-planning-heading" className="min-w-0 space-y-4 border-t border-gray-200 bg-gray-50 px-4 py-5 sm:px-6 md:border-t-0 md:border-l">
                                    <h3 id="article-planning-heading" className="text-base font-semibold">Planning</h3>
                                    {field('planned_date', 'Planned date', { type: 'date', hint: 'Manila time. This is your editorial target.' })}
                                    {field('pillar', 'Content pillar', { options: pillars })}
                                    <div className="border-t border-gray-200 pt-4">
                                        <h4 className="text-sm font-semibold">Editorial brief</h4>
                                        {article?.brief && Object.values(article.brief).some(Boolean) ? (
                                            <dl className="mt-3 space-y-4">
                                                {Object.entries(briefFields).map(([key, label]) => article.brief[key] && (
                                                    <div key={key}>
                                                        <dt className="text-sm font-medium">{label}</dt>
                                                        <dd className="mt-1 text-sm leading-relaxed wrap-anywhere whitespace-pre-wrap text-gray-700">{article.brief[key]}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        ) : (
                                            <p className="mt-1 text-sm text-gray-600">This article has no planning brief.</p>
                                        )}
                                    </div>
                                </aside>
                            </fieldset>
                        </div>

                        <footer className="shrink-0 border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
                            {confirmPublish && (
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md bg-green-50 px-3 py-2.5">
                                    <p className="text-sm text-green-950">Publish approved revision {article.revision} on the Luvep website?</p>
                                    <div className="flex gap-2">
                                        <button type="button" autoFocus className={secondaryButton} onClick={() => setConfirmPublish(false)} disabled={Boolean(pending)}>Cancel</button>
                                        <button type="button" className={primaryButton} onClick={() => write('publish')} disabled={Boolean(pending)}>{pending === 'publish' ? 'Publishing…' : 'Confirm publish'}</button>
                                    </div>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="text-xs text-gray-600" role="status">
                                    {savedMessage || (dirty && (status === 'draft' ? 'Unsaved changes' : 'Saving these changes returns the article to Draft.'))}
                                </div>
                                <div className="ml-auto flex flex-wrap justify-end gap-2">
                                    <button type="submit" className={stageAction && !dirty && article ? secondaryButton : primaryButton} disabled={Boolean(pending) || (!dirty && Boolean(article))}>
                                        {pending === 'save' ? 'Saving…' : 'Save draft'}
                                    </button>
                                    {article && stageAction && !confirmPublish && (
                                        <button type="button" className={primaryButton} disabled={Boolean(pending) || dirty}
                                            onClick={() => stageAction === 'publish' ? setConfirmPublish(true) : write(stageAction)}>
                                            {pending === stageAction ? 'Working…' : actionLabels[stageAction]}
                                        </button>
                                    )}
                                </div>
                            </div>
                        </footer>
                    </form>
                )}
            </div>
        </dialog>
    );
}

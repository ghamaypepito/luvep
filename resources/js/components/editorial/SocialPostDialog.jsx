import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { X, ImagePlus, ExternalLink } from 'lucide-react';

const api = '/social/marketing/posts';
const headers = { Accept: 'application/json' };
const frozen = ['scheduling', 'scheduled', 'published', 'needs_reconciliation'];
export const socialStatus = { draft: 'Draft', in_review: 'For review', approved: 'Approved', changes_requested: 'Changes requested', scheduling: 'Sending to GHL', scheduled: 'Scheduled in GHL', published: 'Published', needs_reconciliation: 'Check GHL result' };
const values = post => ({ campaign: post?.campaign || '', service: post?.service || 'General', caption: post?.caption || '', first_comment: post?.first_comment || '', post_type: post?.post_type || 'post', schedule_date: post?.calendar_date || '', schedule_time: post?.calendar_time || '', publish_now: !!post?.publish_now });

export default function SocialPostDialog({ postId, initialDate = '', canPublish, onClose, onSaved }) {
    const dialog = useRef(null), closeRef = useRef(null), busy = useRef(false), fileInput = useRef(null), cancelDelivery = useRef(null);
    const [post, setPost] = useState(null), [form, setForm] = useState(() => ({ ...values(), schedule_date: initialDate, schedule_time: initialDate ? '09:00' : '' }));
    const [loading, setLoading] = useState(!!postId), [loadError, setLoadError] = useState(''), [attempt, setAttempt] = useState(0);
    const [connection, setConnection] = useState(null), [connectionError, setConnectionError] = useState(''), [connectionAttempt, setConnectionAttempt] = useState(0);
    const [media, setMedia] = useState(null), [previewUrl, setPreviewUrl] = useState(''), [removeMedia, setRemoveMedia] = useState(false);
    const [pending, setPending] = useState(''), [errors, setErrors] = useState({}), [message, setMessage] = useState(''), [reviewNote, setReviewNote] = useState(''), [confirmDelivery, setConfirmDelivery] = useState(false);
    const [mode, setMode] = useState('approval');
    const [unconfirmedSave, setUnconfirmedSave] = useState(false);
    const readOnly = unconfirmedSave || frozen.includes(post?.status);
    const dirty = post ? JSON.stringify(form) !== JSON.stringify(values(post)) || !!media || removeMedia : Object.entries(form).some(([key, value]) => !['service', 'post_type', 'schedule_date', 'schedule_time', 'publish_now'].includes(key) && !!value) || !!media;
    const account = connection?.accounts?.find(account => account.selected);
    const changed = (key, value) => { setForm(previous => ({ ...previous, [key]: value })); setErrors({}); setMessage(''); setConfirmDelivery(false); };
    useEffect(() => { dialog.current.showModal(); closeRef.current.focus(); }, []);
    useEffect(() => {
        if (!postId) return;
        const controller = new AbortController();
        setLoading(true); setLoadError('');
        axios.get(api + '/' + postId, { headers, signal: controller.signal }).then(({ data }) => {
            setPost(data.post); setForm(values(data.post)); setMode(data.post.publish_now ? 'now' : 'schedule');
        }).catch(error => { if (!axios.isCancel(error)) setLoadError(error.response?.data?.message || 'The social post could not be loaded.'); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [postId, attempt]);
    useEffect(() => {
        const controller = new AbortController(); setConnectionError('');
        axios.get('/social/ghl/accounts', { headers, signal: controller.signal }).then(({ data }) => setConnection(data))
            .catch(error => { if (!axios.isCancel(error)) setConnectionError(error.response?.data?.message || 'The GHL account could not be checked. Drafts can still be saved.'); });
        return () => controller.abort();
    }, [connectionAttempt]);
    useEffect(() => {
        if (!media) { setPreviewUrl(''); return; }
        const url = URL.createObjectURL(media); setPreviewUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [media]);
    useEffect(() => { if (confirmDelivery) cancelDelivery.current?.focus(); }, [confirmDelivery]);

    function close() {
        if (busy.current || (dirty && !unconfirmedSave && !window.confirm('Discard unsaved changes to this social post?'))) return;
        dialog.current.close(); onClose();
    }
    function selectMedia(file) {
        if (!file) return;
        const video = file.type === 'video/mp4';
        if (!['image/jpeg', 'image/png', 'image/gif', 'video/mp4'].includes(file.type) || file.size >= (video ? 50 : 10) * 1024 * 1024) {
            setErrors({ media: ['Choose PNG, JPEG or GIF under 10MB, or MP4 under 50MB.'] }); if (fileInput.current) fileInput.current.value = ''; return;
        }
        setMedia(file); setRemoveMedia(false); setErrors({}); setMessage(''); setConfirmDelivery(false);
    }
    async function write(action) {
        if (busy.current) return;
        busy.current = true; setPending(action); setErrors({}); setMessage('');
        let current = post, stage = action;
        try {
            if (action === 'save' || action === 'submit-save') {
                stage = 'save';
                const data = new FormData();
                Object.entries(form).forEach(([key, value]) => data.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value));
                data.append('schedule_timezone', 'Asia/Manila');
                if (post) { data.append('id', post.id); data.append('version', post.version); }
                if (media) data.append('media', media);
                if (removeMedia) data.append('remove_media', '1');
                current = (await axios.post(api, data, { headers })).data.post;
                setPost(current); setForm(values(current)); setMedia(null); setRemoveMedia(false);
                if (fileInput.current) fileInput.current.value = '';
                onSaved();
            }
            if (action !== 'save') {
                const kind = action === 'submit-save' ? 'submit' : action;
                stage = kind;
                const data = { version: current.version, ...(kind === 'changes' ? { note: reviewNote } : {}) };
                current = (await axios.post(api + '/' + current.id + '/' + kind, data, { headers })).data.post;
                setPost(current); setForm(values(current)); onSaved();
            }
            setConfirmDelivery(false);
            setMessage({ save: 'Draft saved in Luvep.', 'submit-save': 'Saved and submitted for Super Admin review.', submit: 'Submitted for review.', approve: 'This version is approved.', changes: 'Changes requested.', schedule: current.publish_now ? 'Queued in GHL for about two minutes from now.' : 'Scheduled in GHL.' }[action]);
        } catch (error) {
            if (stage === 'save' && (!error.response || error.response.status >= 500)) { setUnconfirmedSave(true); onSaved(); }
            setErrors(error.response?.data?.errors || {});
            const result = error.response?.data?.post;
            if (result) { setPost(result); setForm(values(result)); onSaved(); }
            setConfirmDelivery(false);
            setMessage(error.response?.data?.message || 'The action could not be confirmed. Reopen this post to check its saved state before retrying.');
        } finally { busy.current = false; setPending(''); }
    }
    const mediaUrl = previewUrl || (!removeMedia ? post?.media_url : '');
    const mime = media?.type || post?.media_mime || '';
    const field = (key, label, extra = {}) => <label className="social-field">{label}<input aria-label={label} aria-invalid={!!errors[key]} value={form[key]} onChange={event => changed(key, event.target.value)} {...extra} />{errors[key] && <span className="social-error">{errors[key][0]}</span>}</label>;
    const ready = post?.status === 'approved' && !dirty && account?.active && connection?.publishing_enabled;
    return <dialog ref={dialog} aria-labelledby="social-dialog-title" className="social-dialog" onCancel={event => { event.preventDefault(); close(); }}>
        <div className="social-dialog-shell">
            <header className="social-dialog-header"><div><h2 id="social-dialog-title">{postId || post ? 'Social post' : 'New social post'}</h2><p>{socialStatus[post?.status] || 'New draft'} · Luv, Ep Facebook</p></div><button ref={closeRef} type="button" className="calendar-icon" aria-label="Close social post" disabled={!!pending} onClick={close}><X size={18} /></button></header>
            <div className="social-dialog-scroll">
                {loading ? <p className="social-notice" role="status">Loading social post…</p> : loadError ? <div className="social-notice" role="alert"><p>{loadError}</p><button type="button" className="calendar-button secondary" onClick={() => setAttempt(value => value + 1)}>Retry</button></div> : <>
                    {message && <p className="social-notice" role={Object.keys(errors).length ? 'alert' : 'status'}>{message}</p>}
                    {Object.entries(errors).filter(([key]) => !['campaign', 'service', 'caption', 'first_comment', 'schedule_date', 'schedule_time', 'media'].includes(key)).map(([key, messages]) => <p key={key} className="social-error" role="alert">{messages[0]}</p>)}
                    {connection && !connection.publishing_enabled && <p className="social-notice">GHL publishing is not ready. Drafts and reviews can still be saved. Check the existing account and scheduling identity in the Marketing workspace.</p>}
                    {unconfirmedSave && <p className="social-notice" role="alert">The save result is unconfirmed. Close this pop-up and check the calendar for the saved post before creating or editing another copy. Your entered values remain visible here.</p>}
                    {post?.review_note && <p className="social-notice">Review feedback: {post.review_note}</p>}
                    {post?.status === 'needs_reconciliation' && <p className="social-notice" role="alert">GHL did not confirm the result. Check Social Planner before posting manually. This post cannot be retried here.</p>}
                    <div className="social-compose-grid"><fieldset className="social-editor" disabled={!!pending || readOnly}>
                        <div className="social-account"><span className="social-facebook" aria-hidden="true">f</span><div><strong>Luv, Ep</strong><small>{account?.active ? 'Facebook page available in GHL' : connectionError || (connection ? 'Facebook page is unavailable. Reconnect in GHL.' : 'Checking GHL account…')}</small></div></div>
                        {field('campaign', 'Post title', { maxLength: 200, required: true })}
                        <label className="social-field">Service<select value={form.service} onChange={event => changed('service', event.target.value)}>{[...new Set(['General', 'Study', 'Immigration', 'Employment', 'Settlement', form.service])].map(value => <option key={value}>{value}</option>)}</select></label>
                        <label className="social-field">Facebook format<select value={form.post_type} onChange={event => changed('post_type', event.target.value)}><option value="post">Feed</option><option value="reel">Reel · one MP4 video</option><option value="story">Story · media only</option></select></label>
                        <label className="social-field">Caption<textarea aria-label="Caption" aria-invalid={!!errors.caption} rows={5} maxLength={12000} value={form.caption} onChange={event => changed('caption', event.target.value)} /><small>{form.caption.length.toLocaleString()} / 12,000{form.post_type === 'story' ? ' · Leave empty for Stories.' : ''}</small>{errors.caption && <span className="social-error">{errors.caption[0]}</span>}</label>
                        <div className="social-field"><span>Photo, video or GIF</span><label className="social-upload"><ImagePlus size={22} aria-hidden="true" /><span>Choose your finished media</span><input ref={fileInput} type="file" accept="image/png,image/jpeg,image/gif,video/mp4" aria-label="Post media" onChange={event => selectMedia(event.target.files[0])} /><small>PNG, JPEG or GIF under 10MB · MP4 under 50MB</small>{media && <span>{media.name}</span>}</label>{mediaUrl && <button className="calendar-text-button" type="button" onClick={() => { setMedia(null); setRemoveMedia(true); setConfirmDelivery(false); if (fileInput.current) fileInput.current.value = ''; }}>Remove media</button>}{errors.media && <span className="social-error">{errors.media[0]}</span>}</div>
                        <label className="social-field">First comment · optional<textarea aria-label="First comment" aria-invalid={!!errors.first_comment} rows={2} maxLength={8000} value={form.first_comment} onChange={event => changed('first_comment', event.target.value)} />{errors.first_comment && <span className="social-error">{errors.first_comment[0]}</span>}</label>
                        <fieldset className="social-delivery"><legend>Delivery</legend>{[['draft', 'Save as draft'], ['approval', 'Send for approval'], ['schedule', 'Schedule post'], ['now', 'Post now']].map(([value, title]) => <label key={value}><input type="radio" name="social-delivery" value={value} checked={mode === value} onChange={() => { setMode(value); changed('publish_now', value === 'now'); }} />{title}</label>)}</fieldset>
                        {!form.publish_now && <div className="social-date-fields">{field('schedule_date', 'Date · Manila', { type: 'date' })}{field('schedule_time', 'Time · Manila', { type: 'time' })}</div>}
                        <p className="social-help">{form.publish_now ? 'Post now queues the approved version in GHL about two minutes after confirmation.' : 'Scheduling needs a time at least one minute ahead. Saving a draft does not publish it.'} Super Admin approves the exact content, media, format and delivery intent.</p>
                    </fieldset><aside className="social-preview"><h3>Post preview</h3><article className="social-preview-card"><header><span className="social-facebook" aria-hidden="true">f</span><div><strong>Luv, Ep</strong><small>Facebook · {{ post: 'Feed', reel: 'Reel', story: 'Story' }[form.post_type]}</small></div></header>{form.caption && <p className="social-preview-caption">{form.caption}</p>}{mediaUrl ? mime.startsWith('video/') ? <video key={mediaUrl} src={mediaUrl} controls preload="metadata" aria-label="Selected post video" /> : <img src={mediaUrl} alt="Selected post media" /> : <div className="social-media-empty"><ImagePlus size={24} aria-hidden="true" /><p>Your media appears here</p></div>}<div className="social-preview-actions"><span>Like</span><span>Comment</span><span>Share</span></div></article>{form.first_comment && <div className="social-comment-preview"><strong>First comment</strong><p>{form.first_comment}</p></div>}<p className="social-help">Preview is approximate. Facebook Stories cannot include captions or first comments.</p>
                        <a href="https://app.gohighlevel.com/v2/location/EPwLVKAvmJdF8IvwBLwO/marketing/social-planner/post" target="_blank" rel="noopener noreferrer" className="calendar-text-button">Open GHL Social Planner <ExternalLink size={14} /></a>
                    </aside></div>
                    {canPublish && post?.status === 'in_review' && !dirty && <label className="social-field social-review-note">Feedback for requested changes<textarea rows={2} value={reviewNote} onChange={event => setReviewNote(event.target.value)} disabled={!!pending} /></label>}
                    {confirmDelivery && <section className="social-confirm" aria-label="Confirm GHL posting"><h3>{form.publish_now ? 'Queue this approved post now?' : 'Schedule this approved post?'}</h3><p>Destination: Luv, Ep Facebook · {form.post_type === 'post' ? 'Feed' : form.post_type}. {form.publish_now ? 'GHL will queue it about two minutes from now.' : form.schedule_date + ' at ' + form.schedule_time + ' Manila time.'}</p><div><button ref={cancelDelivery} type="button" className="calendar-button secondary" disabled={!!pending} onClick={() => setConfirmDelivery(false)}>Cancel posting</button><button type="button" className="calendar-button" disabled={!!pending} onClick={() => write('schedule')}>{pending ? 'Sending…' : 'Confirm and send to GHL'}</button></div></section>}
                </>}
            </div>
            <footer className="social-dialog-footer"><button type="button" className="calendar-button secondary" disabled={!!pending} onClick={close}>Close</button><div>
                {connectionError && <button type="button" className="calendar-text-button" disabled={!!pending} onClick={() => setConnectionAttempt(value => value + 1)}>Check GHL account again</button>}
                {!loading && !loadError && !readOnly && <>
                    <button type="button" className="calendar-button secondary" disabled={!!pending || (!!post && !dirty)} onClick={() => write('save')}>Save draft</button>
                    {(!post || dirty) && mode !== 'draft' ? <button type="button" className="calendar-button" disabled={!!pending} onClick={() => write('submit-save')}>{pending ? 'Saving…' : 'Save & submit for review'}</button> : post && ['draft', 'changes_requested'].includes(post.status) && !dirty && <button type="button" className="calendar-button" disabled={!!pending} onClick={() => write('submit')}>Submit for review</button>}
                    {canPublish && post?.status === 'in_review' && !dirty && <><button type="button" className="calendar-button secondary" disabled={!!pending || !reviewNote.trim()} onClick={() => write('changes')}>Request changes</button><button type="button" className="calendar-button" disabled={!!pending} onClick={() => write('approve')}>Approve this version</button></>}
                    {canPublish && post?.status === 'approved' && !dirty && <button type="button" className="calendar-button" disabled={!!pending || !ready || confirmDelivery} onClick={() => setConfirmDelivery(true)}>{form.publish_now ? 'Post now through GHL' : 'Schedule through GHL'}</button>}
                </>}
            </div></footer>
        </div>
    </dialog>;
}

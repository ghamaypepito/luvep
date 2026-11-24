import { useEffect, useRef, useState } from 'react';
import axios from 'axios';

let renderer;
function loadRenderer() {
    if (window.drawEpLayout) return Promise.resolve();
    if (!renderer) renderer = new Promise((resolve, reject) => {
        const script = document.createElement('script'); script.src = '/social/workspace-test/v1/ep-layout-renderer.js?v=article-1';
        script.onload = resolve; script.onerror = () => { script.remove(); renderer = null; reject(Error('The graphics tool could not load. Try again.')); }; document.head.append(script);
    });
    return renderer;
}
const mediaTypes = ['image/png', 'image/jpeg', 'image/webp'];
function checkFile(file) { if (!mediaTypes.includes(file.type) || file.size >= 10 * 1024 * 1024) throw Error('Choose PNG, JPEG or WebP images smaller than 10MB.'); }
const button = 'calendar-button secondary';

export default function ArticleVisuals({ form, format, onFormat, suggestions, onSelect, pending, onPending }) {
    const [previews, setPreviews] = useState([]), [photo, setPhoto] = useState(''), [error, setError] = useState('');
    const ticket = useRef(0), mounted = useRef(true), busy = useRef(false), sourceInput = useRef(null);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; ticket.current++; }; }, []);
    useEffect(() => () => previews.forEach(item => URL.revokeObjectURL(item.url)), [previews]);
    useEffect(() => () => { if (photo) URL.revokeObjectURL(photo); }, [photo]);
    useEffect(() => { ticket.current++; setPreviews([]); }, [form.title, form.summary, form.body_html, format, photo, suggestions]);
    async function run(action) {
        if (busy.current) return; busy.current = true; onPending('images'); setError('');
        try { await action(); } catch (e) { if (mounted.current) setError(e.response?.data?.message || e.message || 'Images could not be prepared. Your article has not changed.'); }
        finally { busy.current = false; if (mounted.current) onPending(''); }
    }
    async function upload(files, alts = []) {
        const data = new FormData(); for (const file of files) { checkFile(file); data.append('images[]', file); }
        const { data: result } = await axios.post('/admin/content-calendar/images', data, { headers: { Accept: 'application/json' }, timeout: 45000 });
        if (mounted.current) onSelect(result.images.map((image, index) => ({ ...image, alt: alts[index] || form.image_alt || form.title })));
    }
    async function build() {
        await run(async () => {
            const current = ++ticket.current; await loadRenderer();
            const base = { headline: form.title.slice(0, 90), supporting_copy: form.summary.slice(0, 180), cta: 'Talk to the EP team', alt: form.image_alt || form.title };
            const doc = new DOMParser().parseFromString(form.body_html || '', 'text/html');
            const sections = [...doc.querySelectorAll('h2')].slice(0, 3).map(heading => ({ headline: heading.textContent.slice(0, 90), supporting_copy: (heading.nextElementSibling?.textContent || form.summary).slice(0, 180), cta: 'Explore your next step with EP', alt: heading.textContent.slice(0, 250) }));
            const cards = format === 'featured' ? [suggestions[0] || base] : suggestions.length >= 3 ? suggestions.slice(0, 5) : [base, ...(sections.length ? sections : [{ ...base, headline: 'Plan your next step' }]), { ...base, headline: 'Start with a conversation', supporting_copy: 'Bring your questions to the EP team.' }].slice(0, 5);
            const images = [];
            try {
                for (const card of cards) {
                    const canvas = document.createElement('canvas');
                    await window.drawEpLayout(canvas, photo, { ...card, layout: 'photo-top' }, format === 'featured' ? { width: 1200, height: 630 } : { width: 1080, height: 1080 }, '/social/workspace-test/v1/assets/logo.png');
                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png')); if (!blob) throw Error('The image could not be rendered.');
                    images.push({ blob, url: URL.createObjectURL(blob), alt: card.alt || form.image_alt || form.title });
                }
                if (mounted.current && current === ticket.current) setPreviews(images); else images.forEach(item => URL.revokeObjectURL(item.url));
            } catch (e) { images.forEach(item => URL.revokeObjectURL(item.url)); throw e; }
        });
    }
    return <section className="space-y-3 border-t border-gray-200 pt-5" aria-labelledby="article-visual-heading">
        <h3 id="article-visual-heading" className="text-base font-semibold">3 · Featured image or carousel</h3>
        <p className="text-sm text-gray-600">Build branded graphics from your article, or upload your own images. Generated graphics use typography and an optional photo.</p>
        <label className="social-field">Visual format<select aria-label="Visual format" value={format} onChange={event => onFormat(event.target.value)}><option value="featured">Featured image · 1200 × 630</option><option value="carousel">Carousel · 3–5 square slides</option></select></label>
        <label className="social-field">Optional photo for generated visuals<input ref={sourceInput} aria-label="Optional photo for generated visuals" type="file" accept="image/png,image/jpeg,image/webp" onChange={event => { const file = event.target.files[0]; if (!file) return; try { checkFile(file); setPhoto(URL.createObjectURL(file)); setError(''); } catch (e) { setError(e.message); event.target.value = ''; } }} /></label>
        <button type="button" className={button} disabled={!!pending || !form.title.trim()} onClick={build}>{pending ? 'Preparing…' : 'Build visual preview'}</button>
        {photo && <button type="button" className="calendar-text-button" onClick={() => { setPhoto(''); if (sourceInput.current) sourceInput.current.value = ''; }}>Remove source photo</button>}
        {error && <p role="alert" className="text-sm text-red-800">{error}</p>}
        {!!previews.length && <div className="rounded-xl border border-green-100 bg-green-50/50 p-3"><div className="flex gap-3 overflow-x-auto pb-2">{previews.map((item, index) => <figure className="w-64 shrink-0" key={item.url}><img className="w-full rounded-lg" src={item.url} alt={item.alt} /><figcaption className="mt-1 text-xs text-gray-600">{index + 1} / {previews.length} · <a href={item.url} download={`article-${index + 1}.png`} className="underline">Download PNG</a></figcaption></figure>)}</div><button className="calendar-button" type="button" disabled={!!pending} onClick={() => run(() => upload(previews.map((item, index) => new File([item.blob], `article-${index}.png`, { type: 'image/png' })), previews.map(item => item.alt)))}>Use {previews.length > 1 ? 'carousel' : 'featured image'}</button></div>}
        <label className="social-field">Upload article images<input aria-label="Upload article images" type="file" accept="image/png,image/jpeg,image/webp" multiple onChange={event => { const files = [...event.target.files]; event.target.value = ''; if (!files.length) return; if (files.length > 5) { setError('Choose up to five images.'); return; } run(() => upload(files)); }} /><small>PNG, JPEG or WebP below 10MB each · up to 5. Uploads receive public web links for article publication.</small></label>
        {!!form.featured_images.length && <div className="space-y-2"><p className="text-sm font-semibold">Selected {form.featured_images.length > 1 ? 'carousel' : 'featured image'}</p>{form.featured_images.map((image, index) => <div key={image.url} className="flex items-center gap-3"><img src={image.url} alt={image.alt} className="h-16 w-24 rounded object-cover" /><label className="social-field grow">Image {index + 1} description<input maxLength={250} value={image.alt || ''} onChange={event => onSelect(form.featured_images.map((value, i) => i === index ? { ...value, alt: event.target.value } : value))} /></label></div>)}<button type="button" className="calendar-text-button" onClick={() => onSelect([])}>Remove image selection</button></div>}
    </section>;
}

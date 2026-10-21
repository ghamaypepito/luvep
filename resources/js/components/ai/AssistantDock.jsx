import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Sparkles, Minus, X, Maximize2, Loader2 } from 'lucide-react';
import useGlobalPage from '../ui/useGlobalPage';

const Chat = lazy(() => import('../../pages/ai/Assistant'));

export default function AssistantDock({ initialPage }) {
    const current = useGlobalPage();
    const page = current?.component || Object.hasOwn(current?.props || {}, 'auth') ? current : initialPage;
    const auth = page?.props?.auth;
    const internal = /^\/(admin|portal|assistant)(\/|\?|$)/.test(page?.url || '') || page?.component?.startsWith('hr/');
    if (!internal || !auth?.user || ['lead', 'revoked_lead'].includes(auth.user.role) || auth.ai_enabled === false) return null;
    return <DockSession key={auth.user.id} />;
}

export function DockSession() {
    const [open, setOpen] = useState(false);
    const [mounted, setMounted] = useState(false);
    const [subject, setSubject] = useState(null);
    const [conversationId, setConversationId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const panel = useRef(null);
    const trigger = useRef(null);
    const requestVersion = useRef(0);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;
        const show = async (event) => {
            setOpen(true);
            const id = event?.detail?.subjectId;
            if (!id) { setMounted(true); return; }
            const version = ++requestVersion.current;
            setLoading(true); setError('');
            try {
                const response = await fetch(`/assistant?subject_id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!alive.current || version !== requestVersion.current) return;
                if (!data.aiEnabled) throw new Error();
                setSubject(data.subject); setMounted(true);
                if (!data.subject) setError('That record is unavailable. You can use general chat.');
            } catch { if (alive.current && version === requestVersion.current) setError('Could not open this record. Please try again.'); }
            finally { if (alive.current && version === requestVersion.current) setLoading(false); }
        };
        window.addEventListener('ep-assistant-open', show);
        return () => { alive.current = false; window.removeEventListener('ep-assistant-open', show); };
    }, []);
    useEffect(() => {
        if (!open) return;
        panel.current?.focus();
        const mobile = window.matchMedia('(max-width: 639px)');
        const previous = document.body.style.overflow;
        const sync = () => { document.body.style.overflow = mobile.matches ? 'hidden' : previous; };
        sync(); mobile.addEventListener('change', sync);
        return () => { document.body.style.overflow = previous; mobile.removeEventListener('change', sync); };
    }, [open]);
    const handleKeyDown = (event) => {
        if (event.key === 'Escape') { event.stopPropagation(); close(); }
        if (event.key !== 'Tab' || !window.matchMedia('(max-width: 639px)').matches) return;
        const items = [...panel.current.querySelectorAll('a[href], button:not(:disabled), textarea:not(:disabled)')].filter(el => el.getClientRects().length);
        const first = items[0], last = items.at(-1);
        if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.current)) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    const close = () => { setOpen(false); trigger.current?.focus(); };
    const params = new URLSearchParams();
    if (subject?.id) params.set('subject_id', subject.id);
    if (conversationId) params.set('conversation_id', conversationId);
    const fullUrl = `/assistant${params.size ? `?${params}` : ''}`;

    return <>
        <button ref={trigger} type="button" aria-label="Open EP Assistant" aria-expanded={open} aria-controls="ep-assistant-panel"
            onClick={() => { setOpen(!open); setMounted(true); }}
            className="fixed bottom-5 right-5 z-40 inline-flex items-center gap-2 rounded-full bg-[#193c34] px-5 py-3 text-sm font-semibold text-white shadow-lg hover:bg-[#245347]">
            <Sparkles size={18} /> EP Assistant
        </button>
        <section ref={panel} id="ep-assistant-panel" role="dialog" aria-label="EP Assistant chat" tabIndex={-1}
            hidden={!open} onKeyDown={handleKeyDown}
            className={`${open ? 'flex' : 'hidden'} flex-col bg-white shadow-2xl outline-none border border-gray-200 overflow-hidden`}>
            <div className="flex items-center justify-between bg-[#193c34] px-4 py-3 text-white shrink-0">
                <span className="text-sm font-semibold">EP Assistant</span>
                <div className="flex gap-1">
                    <a href={fullUrl} aria-label="Expand EP Assistant" aria-disabled={busy || loading} onClick={(event) => { if (busy || loading) { event.preventDefault(); setError("Send or clear your draft and wait for the reply before opening the full page."); } }} title="Open full page" className="p-2 rounded-lg hover:bg-white/10"><Maximize2 size={16} /></a>
                    <button type="button" aria-label="Minimize EP Assistant" onClick={close} className="p-2 rounded-lg hover:bg-white/10"><Minus size={16} /></button>
                    <button type="button" aria-label="Close EP Assistant" onClick={close} className="p-2 rounded-lg hover:bg-white/10"><X size={16} /></button>
                </div>
            </div>
            {error && <p role="alert" className="px-4 py-2 text-xs text-rose-700 bg-rose-50">{error}</p>}
            {loading ? <div role="status" className="p-6 text-sm"><Loader2 className="animate-spin inline mr-2" size={16} />Opening record…</div> : null}
            {mounted && <div className={`${loading ? 'hidden' : 'flex-1'} min-h-0`}><Suspense fallback={<p role="status" className="p-6 text-sm">Loading assistant…</p>}><Chat key={subject?.id || 'general'} embedded subject={subject} onBusyChange={setBusy} onConversationChange={setConversationId} /></Suspense></div>}
        </section>
    </>;
}

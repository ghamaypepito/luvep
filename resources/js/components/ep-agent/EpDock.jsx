import { Suspense, lazy, useEffect, useRef, useState } from 'react';
import { Maximize2, Minus, X, MessageSquare } from 'lucide-react';
import { epAgentClient as api } from '../../lib/epAgentClient';
import '../../../css/ep-agent.css';
const Panel = lazy(() => import('./ConversationPanel'));
export default function EpDock({ portals, fullPage = false }) {
    const initial = window.location.pathname.includes('/immigration') && portals.includes('immigration') ? 'immigration' : portals[0];
    const [open, setOpen] = useState(false), [mounted, setMounted] = useState(false), [context, setContext] = useState(null);
    const [portal, setPortal] = useState(initial), [conversationId, setConversationId] = useState(null), [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false), [error, setError] = useState(''), [key, setKey] = useState(0);
    const dialog = useRef(null), trigger = useRef(null), alive = useRef(true), version = useRef(0), busyRef = useRef(false);
    busyRef.current = busy;
    const full = `/ep-agent?${new URLSearchParams(conversationId ? { conversation_id: conversationId } : { portal, ...(context ? { lead_id: context.id } : {}) })}`;
    useEffect(() => {
        alive.current = true;
        const show = async event => {
            if (fullPage) { window.location.assign('/ep-agent'); return; }
            setOpen(true);
            const id = event?.detail?.subjectId;
            if (!id) { setMounted(true); return; }
            if (busyRef.current) { setError('Save or clear your draft before opening another record.'); return; }
            const current = ++version.current; setLoading(true); setError('');
            const candidate = portals.includes(event.detail.portal) ? event.detail.portal : initial;
            try {
                const result = await api.context({ lead_id: id, portal: candidate });
                if (!alive.current || current !== version.current) return;
                if (!result.ok) throw Error();
                setContext(result.data.context); setPortal(result.data.portal); setConversationId(null); setKey(value => value + 1); setMounted(true);
            } catch { if (alive.current && current === version.current) setError('That record is unavailable in this portal. Open it from Manager or your authorized portal.'); }
            finally { if (alive.current && current === version.current) setLoading(false); }
        };
        window.addEventListener('ep-assistant-open', show);
        return () => { alive.current = false; ++version.current; window.removeEventListener('ep-assistant-open', show); };
    }, [portals.join(','), initial, fullPage]);
    useEffect(() => {
        if (!open) return;
        dialog.current?.focus(); const previous = document.body.style.overflow;
        const mobile = window.matchMedia('(max-width: 639px)');
        const sync = () => { document.body.style.overflow = mobile.matches ? 'hidden' : previous; };
        sync(); mobile.addEventListener('change', sync);
        return () => { document.body.style.overflow = previous; mobile.removeEventListener('change', sync); };
    }, [open]);
    const close = () => { setOpen(false); trigger.current?.focus(); };
    const keyboard = event => {
        if (event.key === 'Escape') { event.stopPropagation(); close(); }
        if (event.key !== 'Tab' || !window.matchMedia('(max-width: 639px)').matches) return;
        const items = [...dialog.current.querySelectorAll('button:not(:disabled), a[href], textarea:not(:disabled), select:not(:disabled)')].filter(element => element.getClientRects().length);
        const first = items[0], last = items.at(-1);
        if (event.shiftKey && [first, dialog.current].includes(document.activeElement)) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    if (fullPage) return null;
    return <div className="ep-shell ep-dock">
        <button ref={trigger} type="button" className="ep-dock-trigger" aria-expanded={open} aria-controls="ep-agent-panel" onClick={() => { setOpen(value => !value); setMounted(true); }}><MessageSquare size={18} /> Ep Agent</button>
        <section ref={dialog} id="ep-agent-panel" className="ep-dock-panel" role="dialog" aria-label="Ep Agent private chat" tabIndex={-1} hidden={!open} onKeyDown={keyboard}>
            <header className="ep-dock-bar"><strong>Ep Agent</strong><div><a href={full} aria-label="Expand Ep Agent" aria-disabled={busy || loading} onClick={event => { if (busy || loading) { event.preventDefault(); setError('Save or clear your draft and wait for saving before opening the full page.'); } }}><Maximize2 size={16} /></a><button type="button" aria-label="Minimize Ep Agent" onClick={close}><Minus size={17} /></button><button type="button" aria-label="Close Ep Agent" onClick={close}><X size={17} /></button></div></header>
            {error && <p className="ep-error" role="alert">{error}</p>}{loading && <p className="ep-notice" role="status">Opening record…</p>}
            {mounted && <div className="ep-dock-body" hidden={loading}><Suspense fallback={<p className="ep-notice" role="status">Loading conversation…</p>}><Panel key={key} embedded portal={portal} portals={portals} context={context} onConversationChange={setConversationId} onBusyChange={setBusy} /></Suspense></div>}
        </section>
    </div>;
}

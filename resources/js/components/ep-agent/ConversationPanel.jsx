import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowUp, BookOpen, History, LockKeyhole, MessageSquare, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { epAgentClient as api } from '../../lib/epAgentClient';

export default function ConversationPanel({ portal = 'education', portals = [portal], context: initialContext = null, initialConversationId = null, embedded = false, onConversationChange, onBusyChange }) {
    const [history, setHistory] = useState([]), [messages, setMessages] = useState([]);
    const [active, setActive] = useState(initialConversationId), [context, setContext] = useState(initialContext);
    const [currentPortal, setPortal] = useState(portal), [draft, setDraft] = useState('');
    const [title, setTitle] = useState('New conversation'), [error, setError] = useState(''), [notice, setNotice] = useState('');
    const [saving, setSaving] = useState(false), [loading, setLoading] = useState(false), [locked, setLocked] = useState(false), [accessLost, setAccessLost] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false), [moreHistory, setMoreHistory] = useState(false), [moreMessages, setMoreMessages] = useState(false);
    const alive = useRef(true), chatVersion = useRef(0), listVersion = useRef(0), controllers = useRef(new Set()), end = useRef(null);
    const callback = useRef({ onConversationChange, onBusyChange }); callback.current = { onConversationChange, onBusyChange };
    useEffect(() => { callback.current.onConversationChange?.(active); }, [active]);
    useEffect(() => { callback.current.onBusyChange?.(saving || !!draft.trim() || loading); }, [saving, draft, loading]);
    useEffect(() => { end.current?.scrollIntoView?.({ block: 'nearest' }); }, [messages.length, notice]);
    const deny = useCallback(() => {
        ++chatVersion.current; ++listVersion.current;
        setMessages([]); setHistory([]); setDraft(''); setActive(null); setContext(null); setTitle('Conversation unavailable');
        setLocked(true); setAccessLost(true); setLoading(false); setError('Your access changed or your session ended. Reload the page to continue.'); setNotice('');
    }, []);
    const authorized = useCallback(result => {
        if ([401, 403, 404, 419].includes(result.status)) { deny(); return false; }
        return true;
    }, [deny]);
    const signal = () => { const controller = new AbortController(); controllers.current.add(controller); return controller; };
    const list = useCallback(async (before) => {
        const version = ++listVersion.current, controller = signal();
        try {
            const result = await api.list({ before, signal: controller.signal });
            if (!alive.current || version !== listVersion.current) return;
            if (!authorized(result)) return;
            if (!result.ok) throw Error();
            const rows = result.data.data || [];
            setHistory(previous => before ? [...previous, ...rows.filter(row => !previous.some(item => item.id === row.id))] : rows);
            setMoreHistory(rows.length === 50);
        } catch (e) { if (alive.current && version === listVersion.current && e.name !== 'AbortError') setError('History could not load. Refresh to try again.'); }
        finally { controllers.current.delete(controller); }
    }, [authorized]);
    const load = useCallback(async (id, before) => {
        const version = ++chatVersion.current, controller = signal();
        if (!before) { setMessages([]); setContext(null); setActive(id); setLoading(true); setNotice(''); setError(''); }
        try {
            const result = await api.conversation(id, { before, signal: controller.signal });
            if (!alive.current || version !== chatVersion.current) return;
            if (!authorized(result)) return;
            if (!result.ok) throw Error();
            const conversation = result.data.conversation;
            let record = null;
            if (conversation.lead_id) {
                const scoped = await api.context({ conversation_id: id }, { signal: controller.signal });
                if (!alive.current || version !== chatVersion.current) return;
                if (!authorized(scoped)) return;
                if (!scoped.ok) throw Error();
                record = scoped.data.context;
            }
            if (!alive.current || version !== chatVersion.current) return;
            setContext(record); setPortal(conversation.portal); setTitle(conversation.title); setActive(id);
            const rows = result.data.messages || [];
            setMessages(previous => before ? [...rows.filter(row => !previous.some(item => item.id === row.id)), ...previous] : rows);
            setMoreMessages(rows.length === 50); setHistoryOpen(false);
            if (conversation.archived_at) { setLocked(true); setNotice('This conversation is archived. Start a new conversation to save another request.'); }
        } catch (e) { if (alive.current && version === chatVersion.current && e.name !== 'AbortError') setError('This conversation could not load. Refresh to try again.'); }
        finally { controllers.current.delete(controller); if (alive.current && version === chatVersion.current) setLoading(false); }
    }, [authorized]);
    useEffect(() => {
        alive.current = true; list(); if (initialConversationId) load(initialConversationId);
        return () => { alive.current = false; ++chatVersion.current; ++listVersion.current; controllers.current.forEach(controller => controller.abort()); controllers.current.clear(); };
    }, [initialConversationId, list, load]);
    useEffect(() => {
        const visible = () => { if (!document.hidden && !saving) { list(); if (active) load(active); } };
        document.addEventListener('visibilitychange', visible);
        return () => document.removeEventListener('visibilitychange', visible);
    }, [active, saving, list, load]);
    const canSwitch = () => {
        if (saving || draft.trim()) { setError('Save or clear your draft, and wait for the current request before switching conversations.'); return false; }
        return true;
    };
    const newChat = () => {
        if (accessLost || !canSwitch()) return;
        ++chatVersion.current; setActive(null); setMessages([]); setContext(initialContext); setTitle('New conversation'); setNotice(''); setError(''); setLocked(false);
    };
    const refresh = () => { if (saving) return; list(); if (active) load(active); };
    const save = async event => {
        event.preventDefault(); const text = draft.trim(); if (!text || saving || locked || loading) return;
        setSaving(true); setError(''); setNotice(''); const version = ++chatVersion.current; ++listVersion.current;
        let id = active, persisted = false;
        try {
            if (!id) {
                const created = await api.create(currentPortal, context?.id, text.slice(0, 80));
                if (!alive.current || version !== chatVersion.current) return;
                if (!authorized(created)) return;
                if (!created.ok) throw Error();
                id = created.data.id; setActive(id); setTitle(created.data.title || text.slice(0, 80));
                setHistory(previous => [{ id, title: created.data.title || text.slice(0, 80), portal: currentPortal }, ...previous.filter(item => item.id !== id)]);
            }
            const saved = await api.append(id, text);
            if (!alive.current || version !== chatVersion.current) return;
            if (!authorized(saved)) return;
            if (!saved.ok) throw Error();
            persisted = true; setMessages(previous => [...previous, saved.data]); setDraft('');
            setNotice('Request saved privately. AI generation is off; no reply has been generated.');
            const run = await api.run(id, crypto.randomUUID());
            if (!alive.current || version !== chatVersion.current) return;
            if (!authorized(run)) return;
            if (!run.ok) setError('Your request is saved, but its task status could not load. Refresh before retrying.');
        } catch {
            if (alive.current && version === chatVersion.current) setError(persisted ? 'Your request is saved, but its task status could not load. Refresh before retrying.' : 'Request not saved or not confirmed. Your text is kept below. Refresh history before retrying.');
        } finally { if (alive.current) setSaving(false); }
    };
    const archive = async id => {
        if (!canSwitch() || !window.confirm('Archive this private conversation?')) return;
        setSaving(true);
        try {
            const result = await api.archive(id);
            if (!alive.current || !authorized(result)) return;
            if (!result.ok) throw Error();
            setHistory(previous => previous.filter(item => item.id !== id));
            if (active === id) { setActive(null); setMessages([]); setContext(initialContext); setTitle('New conversation'); setNotice(''); }
        } catch { if (alive.current) setError('Could not archive the conversation. Refresh and try again.'); }
        finally { if (alive.current) setSaving(false); }
    };
    const portalName = currentPortal === 'immigration' ? 'Immigration' : 'Education';
    return <div className={`ep-conversation ${embedded ? 'ep-embedded' : ''}`}>
        <aside className={`ep-history ${historyOpen ? 'is-open' : ''}`} aria-label="Your private conversations">
            <button type="button" className="ep-primary" onClick={newChat} disabled={saving || loading || accessLost}><Plus size={16} /> New conversation</button>
            <h2>Your conversations <LockKeyhole size={14} /></h2>
            <div className="ep-history-list">{history.map(item => <div className={`ep-thread ${active === item.id ? 'is-active' : ''}`} key={item.id}>
                <button type="button" onClick={() => { if (canSwitch()) load(item.id); }} aria-current={active === item.id ? 'true' : undefined}><span>{item.title}</span><small>{item.portal === 'immigration' ? 'Immigration' : 'Education'}</small></button>
                <button type="button" className="ep-icon" aria-label={`Archive ${item.title}`} onClick={() => archive(item.id)} disabled={saving}><Trash2 size={14} /></button>
            </div>)}</div>
            {moreHistory && <button type="button" className="ep-text-button" onClick={() => list(history.at(-1)?.id)}>Older conversations</button>}
            {!history.length && <p className="ep-muted">Your saved conversations will appear here.</p>}
            <p className="ep-history-foot"><LockKeyhole size={14} /> Only your account can open these chats.</p>
        </aside>
        <section className="ep-chat" aria-label="Private conversation">
            <header className="ep-chat-header"><div className="ep-mark"><MessageSquare size={19} /></div><div className="ep-chat-heading"><h2>{title}</h2><p><LockKeyhole size={12} /> Private to you <span>·</span> Ep {portalName}</p></div>
                <button type="button" className="ep-icon ep-history-toggle" aria-label="Conversation history" aria-expanded={historyOpen} onClick={() => setHistoryOpen(!historyOpen)}><History size={18} /></button>
                <button type="button" className="ep-icon" aria-label="Refresh conversation" onClick={refresh} disabled={saving}><RefreshCw size={17} /></button>
            </header>
            {error && <p className="ep-error" role="alert">{error}</p>}
            {context && <div className="ep-record-context"><BookOpen size={15} /><span>{context.label}</span><small>Record context</small></div>}
            <div className="ep-message-list" aria-label="Messages" aria-busy={loading}>
                {moreMessages && <button type="button" className="ep-text-button" onClick={() => load(active, messages[0]?.id)}>Earlier messages</button>}
                {loading ? <p role="status" className="ep-muted">Loading your conversation…</p> : !messages.length && !locked ? <div className="ep-chat-empty"><MessageSquare size={32} /><h3>What are we working on?</h3><p>Keep your requests and working notes together in a private conversation.</p><p className="ep-muted">AI generation is off. You can save requests; Ep cannot produce a reply or take action yet.</p></div> : null}
                {messages.map(message => <article className={`ep-message ep-message-${message.role}`} key={message.id}><span>{message.role === 'user' ? 'You' : 'Ep Agent'}</span><p>{message.content}</p></article>)}
                {notice && <p className="ep-notice" role="status">{notice}</p>}<div ref={end} />
            </div>
            <form className="ep-compose" onSubmit={save}>
                <div className="ep-compose-top"><label htmlFor={`ep-draft-${embedded ? 'dock' : 'page'}`}>Your request</label>{!active && !context && portals.length > 1 && <select aria-label="Conversation portal" value={currentPortal} disabled={saving || !!draft.trim()} onChange={event => setPortal(event.target.value)}>{portals.map(value => <option key={value} value={value}>{value === 'immigration' ? 'Immigration' : 'Education'}</option>)}</select>}</div>
                <textarea id={`ep-draft-${embedded ? 'dock' : 'page'}`} rows={3} maxLength={8000} value={draft} disabled={locked || saving || loading} placeholder="Write a request or working note…" onChange={event => setDraft(event.target.value)} />
                <div className="ep-compose-bottom"><span>Saved privately · No AI generation</span><div>{draft && <button type="button" className="ep-text-button" disabled={saving} onClick={() => setDraft('')}>Clear draft</button>}<button type="submit" className="ep-primary" disabled={!draft.trim() || saving || locked || loading}>{saving ? 'Saving…' : 'Save request'} <ArrowUp size={16} /></button></div></div>
            </form>
        </section>
        {!embedded && <aside className="ep-context"><h2>Working with you</h2><strong>Ep Agent</strong><p>With Ep {portalName}</p><h3>Conversation context</h3><p>{context?.label || `General ${portalName.toLowerCase()} conversation`}</p><h3>What stays private</h3><p>Your messages, notes and conversation history. Admins can see permitted task and usage metadata.</p><h3>Available now</h3><p>Private request history and record context.</p><h3>Not connected yet</h3><p>AI replies, knowledge retrieval, follow-ups, voice and document creation.</p></aside>}
    </div>;
}

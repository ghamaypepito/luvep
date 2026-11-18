import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { UsersRound, X, ArrowLeft, Search } from 'lucide-react';

export function UserViewButton() {
    const { userView } = usePage().props;
    if (!userView?.available) return null;
    return <button type="button" onClick={() => window.dispatchEvent(new Event('luvep:user-view-picker'))} className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-800 hover:bg-gray-50" title="View as user">
        <UsersRound size={16} /><span>{userView.active ? 'Switch user' : 'View as user'}</span>
    </button>;
}

export default function UserViewControls({ initialPage }) {
    const [view, setView] = useState(initialPage.props.userView);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const dialog = useRef(null);
    const search = useRef(null);
    useEffect(() => router.on('navigate', ({ detail }) => setView(detail.page.props.userView)), []);
    useEffect(() => {
        const show = () => { setError(''); setOpen(true); };
        window.addEventListener('luvep:user-view-picker', show);
        return () => window.removeEventListener('luvep:user-view-picker', show);
    }, []);
    useEffect(() => {
        if (!dialog.current) return;
        if (open && view?.available) { dialog.current.showModal(); search.current?.focus(); }
        else dialog.current.close();
    }, [open, view?.available]);
    useEffect(() => {
        if (!open || !view?.available) return;
        const abort = new AbortController();
        setLoading(true); setError('');
        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.get('/admin/user-view/users', { params: { q: query }, signal: abort.signal });
                setUsers(data.users);
            } catch (e) { if (!abort.signal.aborted) setError(e.response?.data?.message || 'Could not load users.'); }
            finally { if (!abort.signal.aborted) setLoading(false); }
        }, 200);
        return () => { clearTimeout(timer); abort.abort(); };
    }, [open, query, view?.available]);
    if (!view?.available) return null;
    const act = async (path) => {
        setBusy(true); setError('');
        try {
            const { data } = await window.axios.post(path);
            // Reload all page-bound widgets so no previous user's data stays mounted.
            window.location.assign(data.redirect);
        } catch (e) { setError(e.response?.data?.message || 'Could not switch user. Please refresh and try again.'); setBusy(false); }
    };
    return <>
        {view.active && <aside aria-label="Active user view" className="fixed bottom-3 inset-x-3 z-[80] mx-auto max-w-4xl rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 shadow-lg flex flex-wrap items-center justify-between gap-3">
            <div className="text-sm text-amber-950"><strong>Acting as {view.target.name}</strong><span className="capitalize"> · {view.target.role.replaceAll('_', ' ')}</span><p className="text-xs mt-1">Changes are live · Logged as {view.actor.name} acting as this user · Applies across tabs</p></div>
            <div className="flex items-center gap-2"><button type="button" onClick={() => setOpen(true)} className="text-xs font-semibold px-3 py-2 rounded-lg border border-amber-300">Switch user</button><button type="button" disabled={busy} onClick={() => act('/admin/user-view/exit')} className="inline-flex gap-2 items-center text-xs font-semibold px-3 py-2 rounded-lg bg-gray-900 text-white disabled:opacity-50"><ArrowLeft size={14}/>Return to Super Admin</button></div>
            {error && !open && <p role="alert" className="text-sm text-red-700 w-full">{error}</p>}
        </aside>}
        <dialog ref={dialog} onCancel={() => setOpen(false)} onClose={() => setOpen(false)} className="m-auto rounded-2xl p-0 w-[calc(100%_-_2rem)] max-w-xl max-h-[90vh] overflow-y-auto shadow-xl backdrop:bg-black/40" aria-labelledby="user-view-title">
            <div className="p-5 border-b flex justify-between items-start"><div><h2 id="user-view-title" className="font-bold text-xl text-gray-900">View as user</h2><p className="mt-2 text-sm text-gray-600">Use their exact permissions. Changes are real and logged with your Super Admin identity.</p></div><button type="button" aria-label="Close user picker" onClick={() => setOpen(false)} className="p-2"><X size={18}/></button></div>
            <div className="p-5"><label htmlFor="user-view-search" className="block text-sm font-semibold mb-2">Find a user</label><div className="relative"><Search size={16} className="absolute top-3 left-3 text-gray-400"/><input ref={search} id="user-view-search" value={query} onChange={e => setQuery(e.target.value)} placeholder="Name or email" maxLength={100} className="w-full border border-gray-300 rounded-xl py-2 pl-9 pr-3"/></div>
                {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
                <div className="mt-4 max-h-80 overflow-y-auto" aria-live="polite">{loading ? <p className="text-sm text-gray-500 p-3">Searching…</p> : users.length === 0 ? <p className="text-sm text-gray-500 p-3">No active users found.</p> : users.map(user => <button key={user.id} type="button" disabled={busy || user.id === view.target?.id} onClick={() => act(`/admin/user-view/${user.id}`)} className="flex items-center justify-between gap-3 w-full text-left p-3 rounded-xl hover:bg-gray-50 disabled:opacity-50 border-b border-gray-100"><div><span className="font-semibold text-sm">{user.name}</span><p className="text-xs text-gray-500">{user.email}</p></div><span className="text-xs capitalize text-gray-600">{user.role.replaceAll('_', ' ')}</span></button>)}</div>
                <p className="text-xs text-gray-500 mt-4">Showing up to 30 active users. Search to narrow the list. Other tabs must refresh before making changes.</p>
            </div>
        </dialog>
    </>;
}

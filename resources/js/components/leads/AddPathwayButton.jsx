import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { ArrowUpRight, LoaderCircle, Plus, X } from 'lucide-react';

const fieldClass = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-1 focus:ring-gray-900 disabled:bg-gray-50';
const buttonClass = 'inline-flex items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900 disabled:cursor-wait disabled:opacity-60';

export default function AddPathwayButton({ leadId }) {
    const [open, setOpen] = useState(false);
    const trigger = useRef(null);

    return <>
        <button ref={trigger} type="button" className={buttonClass} onClick={() => setOpen(true)} aria-haspopup="dialog">
            <Plus size={15} aria-hidden="true" /> Add pathway
        </button>
        {open && <PathwayDialog key={leadId} leadId={leadId} onClose={() => {
            setOpen(false);
            trigger.current?.focus();
        }} />}
    </>;
}

export function PathwayDialog({ leadId, onClose }) {
    const dialog = useRef(null);
    const request = useRef(null);
    const id = useId();
    const [pathways, setPathways] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const form = useForm({ pathway: '', stage: '', assignee: '', note: '' });
    const selected = pathways.find(pathway => pathway.key === form.data.pathway);

    const load = useCallback(async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setLoadError('');
        try {
            const response = await axios.get(`/clients/${leadId}/pathways`, { signal: controller.signal });
            if (controller.signal.aborted) return;
            const options = response.data.pathways;
            setPathways(options);
            const first = options.find(pathway => !pathway.active) || options[0];
            form.setData({ pathway: first?.key || '', stage: first?.default_stage || '', assignee: '', note: '' });
        } catch (error) {
            if (!controller.signal.aborted) {
                setLoadError(error.response?.status === 403
                    ? 'You do not have permission to manage pathways for this client.'
                    : 'Could not load pathways. Try again.');
            }
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }, [leadId]);

    useEffect(() => {
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.current?.showModal();
        load();
        return () => {
            request.current?.abort();
            document.body.style.overflow = previousOverflow;
        };
    }, [load]);

    const choose = (key) => {
        const pathway = pathways.find(item => item.key === key);
        form.clearErrors();
        form.setData({ ...form.data, pathway: key, stage: pathway?.default_stage || '', assignee: '' });
    };
    const finishClose = () => {
        dialog.current?.close();
        onClose();
    };
    const close = () => { if (!form.processing) finishClose(); };
    const submit = (event) => {
        event.preventDefault();
        if (!selected || selected.active || form.processing) return;
        form.post(`/clients/${leadId}/pathways`, {
            preserveScroll: true,
            onSuccess: finishClose,
        });
    };
    const errorId = (field) => form.errors[field] ? `${id}-${field}-error` : undefined;
    const keepFocusInside = (event) => {
        if (event.key !== 'Tab') return;
        const controls = Array.from(dialog.current.querySelectorAll('button:not(:disabled), a[href], select:not(:disabled), textarea:not(:disabled)'))
            .filter(element => element.getClientRects().length > 0);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (!first) {
            event.preventDefault();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    return createPortal(
        <dialog ref={dialog} aria-labelledby={`${id}-title`} aria-describedby={`${id}-description`}
            onKeyDown={keepFocusInside}
            onCancel={event => { event.preventDefault(); close(); }}
            className="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl bg-white p-0 text-gray-900 shadow-xl backdrop:bg-black/40">
            <div className="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-5">
                <div>
                    <h2 id={`${id}-title`} className="text-lg font-semibold">Add pathway</h2>
                    <p id={`${id}-description`} className="mt-1 text-sm leading-relaxed text-gray-600">
                        Use this client’s existing profile, login, and documents. Their current pathways keep their progress.
                    </p>
                </div>
                <button type="button" onClick={close} disabled={form.processing} aria-label="Close pathways"
                    className="-mr-2 -mt-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 focus-visible:outline-2 disabled:opacity-50">
                    <X size={18} aria-hidden="true" />
                </button>
            </div>
            {loading ? <p role="status" className="flex items-center gap-2 px-6 py-8 text-sm text-gray-600">
                <LoaderCircle size={16} className="animate-spin motion-reduce:animate-none" aria-hidden="true" /> Loading pathways…
            </p> : loadError ? <div className="space-y-4 px-6 py-6">
                <p role="alert" className="text-sm text-red-700">{loadError}</p>
                <button type="button" onClick={load} className={buttonClass}>Try again</button>
            </div> : <form onSubmit={submit} className="space-y-5 px-6 py-6" aria-busy={form.processing}>
                <div className="space-y-2">
                    <label htmlFor={`${id}-pathway`} className="block text-sm font-medium">Pathway</label>
                    <select id={`${id}-pathway`} className={fieldClass} value={form.data.pathway} disabled={form.processing}
                        onChange={event => choose(event.target.value)} aria-invalid={!!form.errors.pathway} aria-describedby={errorId('pathway')}>
                        {pathways.map(pathway => <option key={pathway.key} value={pathway.key}>
                            {pathway.label}{pathway.active ? ' · Active' : ''}
                        </option>)}
                    </select>
                    {form.errors.pathway && <p id={errorId('pathway')} role="alert" className="text-sm text-red-700">{form.errors.pathway}</p>}
                </div>
                {selected?.active ? <div className="space-y-4">
                    <p className="text-sm text-gray-600">This client already has an active {selected.label} pathway.</p>
                    <dl className="space-y-2 text-sm">
                        {selected.stage && <div className="flex justify-between gap-4"><dt className="text-gray-600">Current stage</dt><dd className="font-medium">{selected.stage}</dd></div>}
                        {selected.assignee && <div className="flex justify-between gap-4"><dt className="text-gray-600">Assigned adviser</dt><dd className="font-medium">{selected.assignee}</dd></div>}
                    </dl>
                    {selected.open_url ? <Link href={selected.open_url} className={buttonClass} onClick={finishClose}>
                        Open {selected.label} pathway <ArrowUpRight size={15} aria-hidden="true" />
                    </Link> : <p className="text-sm text-gray-600">The {selected.label} team can open this pathway from their portal.</p>}
                </div> : selected && <>
                    {selected.stages.length > 0 && <div className="space-y-2">
                        <label htmlFor={`${id}-stage`} className="block text-sm font-medium">Starting stage</label>
                        <select id={`${id}-stage`} className={fieldClass} value={form.data.stage} disabled={form.processing}
                            onChange={event => form.setData('stage', event.target.value)} aria-invalid={!!form.errors.stage} aria-describedby={errorId('stage')}>
                            {selected.stages.map(stage => <option key={stage} value={stage}>{stage}</option>)}
                        </select>
                        {form.errors.stage && <p id={errorId('stage')} role="alert" className="text-sm text-red-700">{form.errors.stage}</p>}
                    </div>}
                    {selected.assignees.length > 0 && <div className="space-y-2">
                        <label htmlFor={`${id}-assignee`} className="block text-sm font-medium">Assigned adviser</label>
                        <select id={`${id}-assignee`} className={fieldClass} value={form.data.assignee} disabled={form.processing}
                            onChange={event => form.setData('assignee', event.target.value)} aria-invalid={!!form.errors.assignee} aria-describedby={errorId('assignee')}>
                            <option value="">{selected.assignee ? `Keep ${selected.assignee}` : 'Leave unassigned'}</option>
                            {selected.assignees.map(name => <option key={name} value={name}>{name}</option>)}
                        </select>
                        {form.errors.assignee && <p id={errorId('assignee')} role="alert" className="text-sm text-red-700">{form.errors.assignee}</p>}
                    </div>}
                    <div className="space-y-2">
                        <label htmlFor={`${id}-note`} className="block text-sm font-medium">Handoff note <span className="font-normal text-gray-600">(optional)</span></label>
                        <textarea id={`${id}-note`} rows={3} maxLength={2000} value={form.data.note} disabled={form.processing}
                            className={fieldClass} onChange={event => form.setData('note', event.target.value)} aria-invalid={!!form.errors.note} aria-describedby={errorId('note')} />
                        {form.errors.note && <p id={errorId('note')} role="alert" className="text-sm text-red-700">{form.errors.note}</p>}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-5">
                        <button type="button" onClick={close} disabled={form.processing}
                            className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus-visible:outline-2 disabled:opacity-50">Cancel</button>
                        <button type="submit" className={buttonClass} disabled={form.processing}>
                            {form.processing && <LoaderCircle size={15} className="animate-spin motion-reduce:animate-none" aria-hidden="true" />}
                            {form.processing ? 'Adding pathway…' : `Add ${selected.label} pathway`}
                        </button>
                    </div>
                </>}
            </form>}
        </dialog>, document.body,
    );
}

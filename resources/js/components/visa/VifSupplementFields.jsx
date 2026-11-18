import React, { useEffect, useState, useId } from 'react';
import catalog from '@/data/vifSupplementQuestions.json';

const inputClass = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900';

export function vifSupplementSections(type, values = {}) {
    return (catalog[type] || []).filter(section => {
        if (section.familyChildOnly && (values.applying_as !== 'Dependent child' || !/residen/i.test(values.visa_type || ''))) return false;
        if (!section.when) return true;
        const value = values.vif_answers?.[section.when.key] || '';
        return section.when.equals ? value === section.when.equals : value !== section.when.not;
    });
}

function Question({ field, value, onChange, path, errors }) {
    const id = useId();
    const error = errors[path];
    if (field.type === 'list') {
        const entries = Array.isArray(value) ? value : [];
        const update = (index, key, next) => onChange(entries.map((entry, i) => i === index ? { ...entry, [key]: next } : entry));
        return <fieldset className="space-y-3 sm:col-span-2"><legend className="text-sm font-semibold">{field.label}</legend>
            {entries.map((entry, i) => <div key={i} className="rounded-lg border border-gray-200 p-4 space-y-3">
                <div className="flex justify-between"><span className="text-sm font-semibold">Entry {i + 1}</span><button type="button" className="text-sm text-red-700" onClick={() => onChange(entries.filter((_, j) => i !== j))}>Remove entry {i + 1}</button></div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">{field.fields.map(child => <Question key={child.key} field={child} value={[child.key, ...(child.aliases || [])].map(key => entry?.[key]).find(value => value !== undefined && value !== null && value !== '') ?? ''} onChange={next => update(i, child.key, next)} path={`${path}.${i}.${child.key}`} errors={errors} />)}</div>
            </div>)}
            {entries.length < 25 && <button type="button" className="rounded-lg border border-teal-700 px-3 py-2 text-sm text-teal-800" onClick={() => onChange([...entries, {}])}>Add {field.label.toLowerCase()}</button>}
            {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        </fieldset>;
    }
    const props = { id, value: typeof value === 'boolean' && field.type === 'select' ? (value ? 'Yes' : 'No') : typeof value === 'string' || typeof value === 'number' ? value : '', onChange: e => onChange(e.target.value), className: inputClass, 'aria-invalid': !!error, 'aria-describedby': error ? `${id}-error` : undefined };
    return <div className={field.type === 'textarea' ? 'sm:col-span-2' : ''}>
        <label htmlFor={id} className="mb-1 block text-sm font-medium text-gray-700">{field.label}</label>
        {field.type === 'select' ? <select {...props}><option value="">Please select</option>{field.options.map(option => <option key={option} value={option}>{option}</option>)}</select>
            : field.type === 'textarea' ? <textarea {...props} rows={3} maxLength={field.max || 5000} />
                : <input {...props} type={field.type === 'date' && (!value || /^\d{4}-\d{2}-\d{2}$/.test(String(value))) ? 'date' : 'text'} maxLength={255} />}
        {error && <p id={`${id}-error`} role="alert" className="mt-1 text-sm text-red-700">{error}</p>}
    </div>;
}

/** Only the additional questions; existing scalar answers stay in their original sections. */
export default function VifSupplementFields({ type, values = {}, onChange, errors = {}, categoryOnly = false, hideCategory = false }) {
    const sections = vifSupplementSections(type, values).filter(s => categoryOnly ? s.title === 'Work visa category' : !hideCategory || s.title !== 'Work visa category');
    return <div className="space-y-5" data-vif-supplement={type}>{sections.map(section => <section key={section.title} className="rounded-xl border border-gray-200 bg-white p-4">
        <h3 className="mb-4 text-base font-semibold text-gray-900">{section.title}</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">{section.fields.map(field => {
            const root = field.storage === 'root';
            return <Question key={field.key} field={field} value={root ? values[field.key] : values.vif_answers?.[field.key]} path={root ? field.key : `vif_answers.${field.key}`} errors={errors}
                onChange={next => root ? onChange(field.key, next) : onChange('vif_answers', { ...(values.vif_answers || {}), [field.key]: next })} />;
        })}</div>
    </section>)}</div>;
}

export function VifSupplementEditor({ type, values, saveUrl, csrfHeaders, onSaved }) {
    const [draft, setDraft] = useState(values || {});
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    useEffect(() => { setDraft(values || {}); }, [values]);
    if (!catalog[type] || !values) return null;
    const save = async () => {
        if (saving) return;
        setSaving(true); setErrors({}); setMessage('');
        try {
            const response = await fetch(saveUrl, { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders }, body: JSON.stringify({ fields: draft }) });
            const fresh = await response.json().catch(() => null);
            if (!response.ok) {
                setErrors(Object.fromEntries(Object.entries(fresh?.errors || {}).map(([key, value]) => [key.replace(/^fields\./, ''), Array.isArray(value) ? value[0] : value])));
                throw new Error('Could not save additional answers. Your edits are still here.');
            }
            if (!fresh?.sections) throw new Error('Could not confirm saved answers.');
            onSaved(fresh); setMessage('Additional answers saved.');
        } catch (error) { setMessage(error.message || 'Could not save additional answers.'); }
        finally { setSaving(false); }
    };
    return <section className="space-y-4"><h2 className="text-base font-bold">Additional visa information</h2>
        <VifSupplementFields type={type} values={draft} onChange={(key, value) => setDraft(previous => ({ ...previous, [key]: value }))} errors={errors} />
        <button type="button" disabled={saving} onClick={save} className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{saving ? 'Saving…' : 'Save additional answers'}</button>
        {message && <p role="status" className="text-sm">{message}</p>}
    </section>;
}

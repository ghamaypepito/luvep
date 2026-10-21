import React from 'react';

// Internal discussion only: selecting a program here never changes a proposal.
export default function NoteProgramPicker({ options = [], value = [], onChange }) {
    const titles = [...new Set(options.map(option => typeof option === 'string' ? option : option.title).filter(Boolean))];
    return (
        <div className="space-y-2">
            <label className="block text-[11px] font-semibold text-gray-600">
                Programs to discuss
                <select aria-label="Programs to discuss" value="" onChange={event => {
                    if (event.target.value && !value.includes(event.target.value)) onChange([...value, event.target.value]);
                }} className="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs">
                    <option value="">Select a program to add…</option>
                    {titles.filter(title => !value.includes(title)).map(title => <option key={title} value={title}>{title}</option>)}
                </select>
            </label>
            <div className="flex flex-wrap gap-1.5">
                {value.map(title => <button key={title} type="button" aria-label={`Remove ${title}`}
                    onClick={() => onChange(value.filter(item => item !== title))}
                    className="rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700">{title} ×</button>)}
            </div>
            <p className="text-[11px] text-gray-400">Saved with this internal note. No proposal is sent.</p>
        </div>
    );
}

export function NotePrograms({ programs = [] }) {
    if (!programs.length) return null;
    return <p className="my-1.5 text-xs text-gray-600"><span className="font-semibold">Programs:</span> {programs.join(' · ')}</p>;
}

import { useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { ArrowUpRight, BookOpen, Search, X } from 'lucide-react';
import RoleLayout from '@/components/layout/RoleLayout';

export default function HelpCenter({ guides, departments }) {
    const [query, setQuery] = useState('');
    const [department, setDepartment] = useState('all');
    const searchInput = useRef(null);
    const terms = query.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    const matches = guides.filter(guide => {
        const content = [guide.title, guide.description, ...guide.topics, ...guide.departments.map(id => departments.find(item => item.id === id)?.label || ''), guide.keywords].join(' ').toLocaleLowerCase();
        return (department === 'all' || guide.departments.includes(department)) && terms.every(term => content.includes(term));
    });
    const startGuide = guides.find(guide => guide.id === '00');
    const selectedLabel = departments.find(item => item.id === department)?.label || 'All guides';
    const focusClass = 'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-800';

    function reset() {
        setQuery('');
        setDepartment('all');
        searchInput.current?.focus();
    }

    return <>
        <Head title="Help Center" />
        <div className="mx-auto max-w-6xl pb-12">
            <header className="mb-8 rounded-2xl border border-gray-200 bg-white p-5 sm:p-8">
                <div className="flex flex-wrap items-start justify-between gap-5">
                    <div className="max-w-2xl">
                        <p className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-emerald-900"><BookOpen size={16} aria-hidden="true" /> Luvep knowledge base</p>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Help Center</h1>
                        <p className="mt-3 text-sm leading-6 text-gray-600">Find a guide, follow the steps, and get back to work. Browse by department or search for what you need.</p>
                    </div>
                    {startGuide && <a href={startGuide.url} target="_blank" rel="noopener noreferrer" className={`inline-flex min-h-11 items-center gap-2 rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-950 ${focusClass}`}>Start here <ArrowUpRight size={16} aria-hidden="true" /><span className="sr-only"> (opens in Google Drive in a new tab)</span></a>}
                </div>
                <div className="mt-6">
                    <label htmlFor="help-search" className="mb-2 block text-sm font-semibold text-gray-900">Search guides</label>
                    <div className="relative max-w-3xl">
                        <Search className="pointer-events-none absolute left-4 top-3.5 text-gray-500" size={20} aria-hidden="true" />
                        <input ref={searchInput} id="help-search" type="search" maxLength={200} value={query} onChange={event => setQuery(event.target.value)} placeholder="Try payment proofs, clock in, or student intake" aria-describedby="help-search-hint" className="min-h-12 w-full rounded-xl border border-gray-300 bg-gray-50 py-3 pl-12 pr-4 text-sm text-gray-900 outline-none focus:border-emerald-800 focus:ring-2 focus:ring-emerald-800/20" />
                    </div>
                    <p id="help-search-hint" className="mt-2 text-xs leading-5 text-gray-600">Search guide titles and topics. Open a guide to read its latest instructions and screenshots in Drive.</p>
                </div>
            </header>

            <div className="grid items-start gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                <aside>
                    <h2 className="mb-3 text-sm font-semibold text-gray-900">Browse by department</h2>
                    <nav aria-label="Help departments" className="flex flex-wrap gap-2 lg:flex-col lg:gap-1">
                        {[{ id: 'all', label: 'All guides' }, ...departments].map(item => <button key={item.id} type="button" aria-pressed={department === item.id} onClick={() => setDepartment(item.id)} className={`flex min-h-11 items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm ${focusClass} ${department === item.id ? 'bg-emerald-100 font-semibold text-emerald-950' : 'text-gray-700 hover:bg-white'}`}>
                            <span>{item.label}</span><span className="text-xs tabular-nums">{item.id === 'all' ? guides.length : guides.filter(guide => guide.departments.includes(item.id)).length}</span>
                        </button>)}
                    </nav>
                    <p className="mt-5 text-xs leading-5 text-gray-600">Guides open in a new tab. Use your authorized Google account; request access in Drive if needed.</p>
                </aside>

                <section aria-labelledby="help-results-heading" className="min-w-0">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div><h2 id="help-results-heading" className="text-lg font-semibold text-gray-900">{selectedLabel}</h2><p role="status" aria-live="polite" className="mt-1 text-sm text-gray-600">{matches.length} {matches.length === 1 ? 'guide' : 'guides'}{query.trim() ? ` matching “${query.trim()}”` : ' available'}</p></div>
                        {(query || department !== 'all') && <button type="button" onClick={reset} className={`inline-flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-white ${focusClass}`}><X size={15} aria-hidden="true" /> Clear filters</button>}
                    </div>
                    {matches.length ? <ul className="divide-y divide-gray-200 rounded-2xl border border-gray-200 bg-white">
                        {matches.map(guide => <li key={guide.id} className="p-5 sm:p-6">
                            <p className="mb-2 text-xs font-medium text-emerald-900">{guide.departments.map(id => departments.find(item => item.id === id)?.label).join(' / ')}</p>
                            <h3><a href={guide.url} target="_blank" rel="noopener noreferrer" className={`inline-flex items-start gap-2 rounded text-base font-semibold leading-6 text-gray-900 hover:text-emerald-800 ${focusClass}`}><span>{guide.title}</span><ArrowUpRight size={17} className="mt-1 shrink-0" aria-hidden="true" /><span className="sr-only"> (opens in Google Drive in a new tab)</span></a></h3>
                            <p className="mt-2 text-sm leading-6 text-gray-600">{guide.description}</p>
                            <p className="mt-3 text-xs leading-5 text-gray-600"><span className="font-semibold">Inside: </span>{guide.topics.slice(0, 3).join(' · ')}</p>
                        </li>)}
                    </ul> : <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                        <Search size={24} className="mx-auto mb-3 text-gray-500" aria-hidden="true" />
                        <h3 className="font-semibold text-gray-900">No guides found</h3>
                        <p className="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-600">Try a shorter phrase, another department, or clear your filters to browse all available guides.</p>
                        <button type="button" onClick={reset} className={`mt-5 min-h-11 rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-950 ${focusClass}`}>Show all guides</button>
                    </div>}
                </section>
            </div>
        </div>
    </>;
}

HelpCenter.layout = page => <RoleLayout>{page}</RoleLayout>;

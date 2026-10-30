/** Small source references; tag editing remains in the profile's Tags panel. */
export default function LeadReferenceBadges({ tags = [] }) {
    const references = [...new Set(tags.filter((tag) => typeof tag === 'string' && tag.toLowerCase().startsWith('reference-')))];
    if (references.length === 0) return null;

    return (
        <div className="mt-1 flex max-w-[240px] flex-wrap gap-1" aria-label="Lead reference tags">
            {references.map((reference) => (
                <span key={reference} title={reference} className="max-w-full break-all rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium leading-tight text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                    {reference}
                </span>
            ))}
        </div>
    );
}

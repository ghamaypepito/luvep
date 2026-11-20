// Education staff addition; do not mutate the shared/client checklist catalogue.
export function educationDocumentSections(sections, enabled) {
    if (!enabled) return sections;
    return sections.map(section => section.key !== 'academic' || section.items.some(item => item.id === 'acad.application_forms')
        ? section
        : { ...section, items: [...section.items, { id: 'acad.application_forms', name: 'Application Forms' }] });
}

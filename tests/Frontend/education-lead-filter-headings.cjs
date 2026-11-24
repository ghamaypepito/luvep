const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { transformSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const requests = [];
const Empty = () => null;
const inertia = { Head: Empty, Link: ({ children, ...props }) => React.createElement('a', props, children),
    usePage: () => ({ props: { auth: { user: { role: 'education' } } } }),
    router: new Proxy({}, { get: () => (...args) => requests.push(args) }) };
const file = path.resolve('resources/js/pages/portal/sales/Leads.jsx');
// Render the actual page and inline filters; isolate unrelated external widgets.
const loaded = new Module(file, module); loaded.filename = file; loaded.paths = module.paths;
loaded.require = name => {
    if (name === '@inertiajs/react') return inertia;
    if (name === 'lucide-react') return new Proxy({}, { get: () => Empty });
    if (name === '@/utils/priority') return { priorityRank: () => 0, priorityRing: () => '' };
    if (name === 'sonner') return { toast: { success() {}, error() {} } };
    if (name.startsWith('@/') || name.startsWith('@dnd-kit/')) return { __esModule: true, default: Empty,
        AvatarPhoto: ({ children }) => children, PathwayDialog: Empty, NotePrograms: Empty };
    return module.require(name);
};
loaded._compile(transformSync(fs.readFileSync(file, 'utf8'), { loader: 'jsx', format: 'cjs', jsx: 'automatic' }).code, file);
const Page = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const statuses = ['New Leads', 'Contact Attempted', 'Qualified', 'Booked Consultation', 'Consultancy Agreement', 'Visa Process'];
const leads = statuses.map((status, i) => ({ id: i + 1, name: `Synthetic ${i + 1}`, status, updated_at: '2026-11-20T00:00:00Z' }));
const original = JSON.stringify(leads);
const button = text => [...document.querySelectorAll('button')].find(b => b.textContent.trim() === text);
const render = portal => act(async () => root.render(React.createElement(Page, { key: portal, portal, leads, statuses })));
(async () => {
    await render('education');
    for (const label of ['All', ...statuses.slice(0, 4)]) assert.ok(button(label), `${label} remains available`);
    const filterRow = button('All').parentElement;
    for (const label of statuses.slice(4)) assert.equal([...filterRow.querySelectorAll('button')].some(b => b.textContent === label), false);
    assert.equal(document.querySelectorAll('tbody tr').length, 6, 'All existing stages remain visible in All');
    assert.match(document.querySelector('tbody').textContent, /Consultancy Agreement/);
    assert.match(document.querySelector('tbody').textContent, /Visa Process/);
    await act(async () => button('Qualified').click());
    assert.equal(document.querySelectorAll('tbody tr').length, 1, 'Remaining filters still work');
    assert.match(document.querySelector('tbody').textContent, /Synthetic 3/);
    await act(async () => button('All').click());
    assert.equal(document.querySelectorAll('tbody tr').length, 6);
    for (const portal of ['sales', 'admin', 'immigration', 'agent']) {
        await render(portal);
        const row = button('All').parentElement;
        for (const label of statuses) assert.ok([...row.querySelectorAll('button')].some(b => b.textContent === label), `${portal} retains ${label}`);
    }
    assert.equal(JSON.stringify(leads), original, 'No input records or statuses are modified');
    assert.equal(requests.length, 0, 'Filtering does not submit writes or navigation');
    await act(async () => root.unmount());
    console.log('Education lead headings: confirmed removals, existing records/stages, remaining filters and unchanged other portals passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

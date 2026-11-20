const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const posts = [], visits = [];
const inertia = { Head: () => null, Link: ({ children, ...props }) => React.createElement('a', { ...props, onClick: e => { e.preventDefault(); visits.push(props.href); } }, children), router: { post: (...args) => posts.push(args) } };
// Isolate the shared table from the large Leads page; exercise its real JSX and state.
const helpers = { initials: name => name.slice(0, 2), priorityMeta: () => null, priorityRank: p => p === 'urgent' ? 0 : 1,
    fmtDateShort: value => value ? value.slice(0, 10) : '—', fmtTime: value => value ? value.slice(11, 16) : '',
    StagePicker: ({ lead, onSelect }) => React.createElement('button', { 'data-stage': lead.id, onClick: () => onSelect('Qualified') }, lead.status),
    LeadDashboardPanel: ({ lead }) => React.createElement('div', { 'data-details': lead.id }, 'Details') };
function load(file, shared) {
    const code = buildSync({ entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react', '@/pages/portal/sales/Leads', '@/pages/portal/sales/AgentLeads'] }).outputFiles[0].text;
    const loaded = new Module(path.resolve(file), module); loaded.filename = path.resolve(file); loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? inertia : name === 'lucide-react' ? new Proxy({}, { get: () => () => null })
        : name === '@/pages/portal/sales/Leads' ? helpers : name === '@/pages/portal/sales/AgentLeads' ? shared : module.require(name);
    loaded._compile(code, loaded.filename); return loaded.exports.default;
}
const Shared = load('resources/js/pages/portal/sales/AgentLeads.jsx');
const Education = load('resources/js/pages/portal/education/AgentLeads.jsx', Shared);
const root = createRoot(document.getElementById('root'));
const lead = { id: 42, name: 'Synthetic Referral', status: 'New Leads', created_at: '2026-07-01T01:00:00Z', updated_at: '2026-11-19T23:00:00Z', updated_by: 'Reviewer', updated_desc: 'Note added' };
const props = { agent: { name: 'Synthetic Agent' }, leads: [lead], portalBase: '/portal/education' };
const render = async (Page, data) => act(async () => root.render(React.createElement(Page, data)));
(async () => {
    await render(Education, props);
    assert.equal(document.querySelectorAll('th').length, 8);
    const link = document.querySelector('a[href="/portal/education/leads/42"]'); assert.ok(link);
    await act(async () => link.click()); assert.deepEqual(visits, ['/portal/education/leads/42']); assert.equal(posts.length, 0);
    assert.equal(document.querySelector('[data-details]'), null);
    const cells = document.querySelectorAll('tbody tr:first-child td');
    assert.match(cells[6].textContent, /2026-07-01/); assert.match(cells[7].textContent, /2026-11-19.*Reviewer.*Note added/);
    await act(async () => document.querySelector('[data-stage]').click());
    assert.equal(posts[0][0], '/portal/education/leads/42'); assert.deepEqual(posts[0][1], { status: 'Qualified' });
    await act(async () => { posts[0][2].onSuccess(); posts[0][2].onFinish(); });
    await render(Education, { ...props, leads: [{ ...lead, status: 'Qualified', updated_at: '2026-11-20T04:00:00Z', updated_desc: 'Stage changed' }] });
    assert.match(document.querySelector('tbody').textContent, /2026-11-20.*Stage changed/);
    await act(async () => document.querySelector('[title="Expand details"]').click());
    assert.equal(document.querySelector('[data-details]').closest('td').colSpan, 8);
    await render(Education, { ...props, leads: [] }); assert.equal(document.querySelector('tbody td').colSpan, 8);
    for (const base of ['/portal/sales', '/admin', '/portal/sub-agent']) {
        await render(Shared, { ...props, key: base, portalBase: base });
        assert.equal(document.querySelectorAll('th').length, 7);
        assert.equal(document.querySelector(`a[href="${base}/leads/42"]`), null);
        assert.doesNotMatch(document.querySelector('thead').textContent, /Updated/);
    }
    await act(async () => root.unmount());
    console.log('Education affiliate leads: profile navigation, dates, server refresh, expansion, empty state and unchanged other portals passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });


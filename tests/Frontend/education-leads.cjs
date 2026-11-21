const assert = require('node:assert/strict');
const { build } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const posts = [];
const empty = () => null;
const fragment = ({ children }) => React.createElement(React.Fragment, null, children);
Object.assign(empty, { PathwayDialog: empty, NotePrograms: empty, AvatarPhoto: empty });
const inertia = { Head: empty, Link: ({ children, ...props }) => React.createElement('a', props, children),
    usePage: () => ({ props: { flash: {}, auth: { user: { id: 71, role: 'education' }, modules: ['leads'] } } }),
    router: { post: (...args) => posts.push(args) } };
async function load(file) {
    const code = (await build({ entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        alias: { '@': path.resolve('resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react', 'sonner', '@dnd-kit/core', '@dnd-kit/utilities'],
        plugins: [{ name: 'unrelated-leaves', setup(build) {
            build.onResolve({ filter: /[\\/]resources[\\/]js[\\/]components[\\/]/ }, args => ({ path: args.path, external: true }));
            build.onResolve({ filter: /[\\/]resources[\\/]js[\\/]lib[\\/]http/ }, args => ({ path: args.path, external: true }));
        } }],
    })).outputFiles[0].text;
    const loaded = new Module(path.resolve('education-leads-test.cjs'), module);
    loaded.filename = path.resolve('education-leads-test.cjs'); loaded.paths = module.paths;
    loaded.require = name => {
        if (name === '@inertiajs/react') return inertia;
        if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
        if (name === 'sonner') return { toast: { success() {}, error() {} } };
        if (name === '@dnd-kit/core') return { DndContext: fragment, DragOverlay: fragment, useSensor: () => ({}), useSensors: () => [] };
        if (name === '@dnd-kit/utilities') return { CSS: { Transform: { toString: () => '' } } };
        if (name.includes('/components/')) return empty;
        if (name.includes('/lib/http')) return { getJson: async () => ({}) };
        return module.require(name);
    };
    loaded._compile(code, loaded.filename);
    return loaded.exports.default;
}
const root = createRoot(document.getElementById('root'));
const click = node => act(async () => { assert.ok(node); node.click(); });
const button = text => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === text);
const render = (Page, props) => act(async () => root.render(React.createElement(Page, props)));
(async () => {
    const AgentLeads = await load('resources/js/pages/portal/education/AgentLeads.jsx');
    const row = { id: 901, name: 'Synthetic Referral', status: 'New Leads', created_at: '2026-01-01T12:00:00Z',
        updated_at: '2026-11-20T12:00:00Z', updated_by: 'Synthetic Staff', updated_desc: 'Updated notes' };
    const props = { agent: { id: 9, name: 'Synthetic Affiliate' }, leads: [row], statuses: ['New Leads', 'Contact Attempted'] };
    for (const portalBase of ['/portal/education', '/portal/sales', '/admin', '/portal/immigration-adviser']) {
        await render(AgentLeads, { ...props, portalBase });
        assert.equal(document.querySelector(`a[href="${portalBase}/leads/901"]`)?.textContent, row.name);
    }
    await render(AgentLeads, { ...props, portalBase: '/portal/education' });
    const headers = [...document.querySelectorAll('th')].map(node => node.textContent.trim());
    assert.ok(headers.includes('Added'));
    assert.ok(headers.includes('Updated'));
    const updatedCell = () => document.querySelector('tbody tr').children[headers.indexOf('Updated')];
    assert.match(updatedCell().textContent, /Synthetic Staff/);
    assert.match(updatedCell().textContent, /Updated notes/);
    const before = updatedCell().textContent;
    await click(button('New Leads'));
    await click(button('Contact Attempted'));
    assert.equal(posts.at(-1)[0], '/portal/education/leads/901');
    assert.equal(posts.at(-1)[1].status, 'Contact Attempted');
    await act(async () => posts.at(-1)[2].onFinish());
    assert.equal(updatedCell().textContent, before, 'Failed request retains the saved date');
    await render(AgentLeads, { ...props, portalBase: '/portal/education', leads: [{ ...row, status: 'Contact Attempted',
        updated_at: '2026-11-21T12:00:00Z', updated_desc: 'Stage → Contact Attempted' }] });
    assert.match(updatedCell().textContent, /Stage → Contact Attempted/);
    assert.notEqual(updatedCell().textContent, before, 'New server props replace stale row dates');
    assert.ok(button('Contact Attempted'));

    const Leads = await load('resources/js/pages/portal/education/Leads.jsx');
    await render(Leads, { portal: 'education', leads: [
        { id: 1, name: 'Legacy Agreement', status: 'Consultancy Agreement' },
        { id: 2, name: 'Sent Agreement', status: 'Consultancy Agreement Sent' },
        { id: 3, name: 'Signed Agreement', status: 'Consultancy Agreement Signed' },
        { id: 4, name: 'Other Prospect', status: 'New Leads' },
    ], statuses: ['New Leads', 'Consultancy Agreement', 'Consultancy Agreement Sent', 'Consultancy Agreement Signed'] });
    await click(button('Consultancy Agreement'));
    for (const name of ['Legacy Agreement', 'Sent Agreement', 'Signed Agreement']) assert.match(document.querySelector('tbody').textContent, new RegExp(name));
    assert.doesNotMatch(document.querySelector('tbody').textContent, /Other Prospect/);
    await click(button('All'));
    assert.match(document.querySelector('tbody').textContent, /Other Prospect/);

    const Students = await load('resources/js/pages/portal/education/Students.jsx');
    const beforeRender = posts.length;
    await render(Students, { students: [
        { id: 11, name: 'Recorded Decline', status: 'Declined Visa', education_stage: null, education_stage_hint: 'Declined Visa', immigration_stage: 'Decline Visa', is_student: true },
        { id: 12, name: 'Explicit Education', status: 'Started Course', education_stage: 'Started Course', immigration_stage: 'Decline Visa', is_student: true },
        { id: 13, name: 'Generic Pipeline', status: 'Proposal Sent', education_stage: null, immigration_stage: 'Visa Lodged', is_student: true },
    ] });
    const studentRow = name => [...document.querySelectorAll('tbody tr')].find(node => node.textContent.includes(name));
    assert.match(studentRow('Recorded Decline').textContent, /Declined Visa/);
    assert.match(studentRow('Explicit Education').textContent, /Started Course/);
    assert.match(studentRow('Generic Pipeline').textContent, /Set stage/, 'Generic Sales status is not an assigned Education stage');
    await click(button('Proposal Sent'));
    assert.equal(studentRow('Generic Pipeline'), undefined, 'A colliding Sales enum is not counted by the Education stage filter');
    await click(button('All'));
    await click(button('Declined Visa'));
    assert.ok(studentRow('Recorded Decline'), 'Displayed fallback is also discoverable through the status filter');
    assert.equal(studentRow('Explicit Education'), undefined);
    await click(button('All'));
    const trigger = [...studentRow('Recorded Decline').querySelectorAll('button')].find(node => node.textContent.includes('Declined Visa'));
    assert.match(trigger.title, /Education stage has not been set/);
    await click(trigger);
    const decline = [...document.querySelectorAll('[role=option]')].find(node => node.textContent.trim() === 'Declined Visa');
    assert.equal(decline?.getAttribute('aria-selected'), 'false', 'A displayed Immigration outcome is not an assigned Education stage');
    assert.equal(posts.length, beforeRender, 'Rendering/filtering/inspection does not save a stage or trigger a handoff');
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Education leads: profile links, authoritative dates, stage interaction, agreement grouping and non-mutating decline display/filter passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); dom.window.close(); process.exitCode = 1; });

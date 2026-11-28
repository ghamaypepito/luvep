const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/accommodation/onboarding' });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;

const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const gets = [];
const posts = [];
const mocks = {
    '@inertiajs/react': {
        Head: () => null,
        Link: ({ children, ...props }) => React.createElement('a', props, children),
        router: { get: (...args) => gets.push(args), post: (...args) => posts.push(args) },
    },
    'lucide-react': new Proxy({}, { get: () => () => null }),
};
const output = buildSync({
    entryPoints: ['resources/js/pages/portal/accommodation/Applications.jsx'],
    alias: { '@': path.resolve('resources/js') },
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', ...Object.keys(mocks)],
}).outputFiles[0].text;
const loaded = new Module(path.resolve('accommodation-onboarding-test.cjs'), module);
loaded.filename = path.resolve('accommodation-onboarding-test.cjs');
loaded.paths = module.paths;
loaded.require = (name) => mocks[name] ?? module.require(name);
loaded._compile(output, loaded.filename);

const Onboarding = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const list = ['Registration / Leads', 'Shortlisted', 'Approved to Book a Viewing', 'Viewing Booked', 'Viewing Completed',
    'Proposal / Agreement Sent', 'Agreement Signed', 'Move-In Cost Invoice Sent', 'Move-In Cost Payment Confirmed', 'Welcome Email Sent'];
const change = (control, value) => act(async () => {
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(control), 'value').set.call(control, value);
    control.dispatchEvent(new Event('change', { bubbles: true }));
});
(async () => {
    await act(async () => root.render(React.createElement(Onboarding, {
        submissions: { data: [{id: 42, full_legal_name: 'Sample Applicant', property_label: '97 Sample Avenue',
            assigned_to: {id: 1, name: 'Alaine'}, stage_label: list[3], created_at: '2026-11-26T11:30:00Z'}] },
        options: { stages: list.map((label, id) => ({ label, id: String(id) })),
            team: [{id: 1, name: 'Alaine'}, {id: 2, name: 'Janille'}] },
    })));
    assert.equal(document.querySelectorAll('table').length, 1);
    assert.deepEqual([...document.querySelectorAll('th')].map((cell) => cell.textContent),
        ['Applicant', 'Property', 'Assigned to', 'Stage', 'Submitted', 'Actions']);
    const stage = document.querySelector('select[aria-label=Stage]');
    assert.deepEqual([...stage.options].map((option) => option.textContent), ['All stages', ...list]);
    const assignee = document.querySelector('select[aria-label=Assignee]');
    assert.deepEqual([...assignee.options].map((option) => option.textContent), ['All assignees', 'Alaine', 'Janille', 'Unassigned']);
    await change(assignee, 'unassigned');
    assert.deepEqual(gets.at(-1)[1], { assigned_to: 'unassigned' });
    await act(async () => [...document.querySelectorAll('button')].find((button) => button.textContent === 'Assigned to me').click());
    assert.deepEqual(gets.at(-1)[1], { assigned_to: 'me' });
    await change(document.querySelector('select[aria-label="Assign Sample Applicant"]'), '2');
    assert.deepEqual(posts[0].slice(0, 2), ['/portal/accommodation/applications/42/assign', {user_id: '2'}]);
    assert.equal(document.querySelector('tbody a').getAttribute('href'), '/portal/accommodation/applications/42');
    assert.doesNotMatch(document.body.textContent, /Sort: Newest|Stalling 7d|Active pipeline/);
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Accommodation onboarding UI passed: one table, ten stages, owner filters, reassignment, and record link.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});

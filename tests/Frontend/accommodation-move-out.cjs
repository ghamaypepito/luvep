const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/accommodation/move-out' });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const calls = [];
const Shell = ({ children }) => React.createElement('section', { 'data-testid': 'portal-shell' }, children);
const inertia = {
    Head: () => null,
    Link: ({ children, ...props }) => React.createElement('a', props, children),
    router: Object.fromEntries(['get', 'post', 'patch'].map(method => [method, (...args) => calls.push([method, ...args])])),
    useForm: (initial) => {
        const [data, set] = React.useState(initial);
        return { data, errors: {}, processing: false, setData: (key, value) => set(old => ({ ...old, [key]: value })),
            post: (...args) => calls.push(['post', ...args, data]), patch: (...args) => calls.push(['patch', ...args, data]), reset: () => set(initial) };
    },
};
function page(name) {
    const output = buildSync({ entryPoints: [`resources/js/pages/portal/accommodation/${name}.jsx`],
        bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react/jsx-runtime', '@inertiajs/react', path.resolve('resources/js/components/layout/AccommodationLayout.jsx')],
    }).outputFiles[0].text;
    const loaded = new Module(path.resolve(`move-out-${name}-test.cjs`), module);
    loaded.filename = path.resolve(`move-out-${name}-test.cjs`);
    loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? inertia : name.includes('AccommodationLayout') ? Shell : module.require(name);
    loaded._compile(output, loaded.filename);
    return loaded.exports.default;
}
const root = createRoot(document.getElementById('root'));
const render = async (Page, props) => {
    await act(async () => root.render(React.createElement(Shell, null, React.createElement(Page, props))));
    assert.equal(document.querySelectorAll('[data-testid=portal-shell]').length, 1, 'The global portal shell must not be nested by a page.');
};
const change = async (control, value) => act(async () => {
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(control), 'value').set.call(control, value);
    control.dispatchEvent(new Event('change', { bubbles: true }));
});
(async () => {
    const owners = [{ id: 1, name: 'Alaine' }, { id: 2, name: 'Janille' }];
    const stages = { check_in: 'Renewal or Move-Out Check-In', decision: 'Renewal Requested / Move-Out Confirmed' };
    const row = { id: 7, tenant: 'Synthetic Tenant', property: 'Synthetic House', agreement_ends: '2026-12-20', stage: 'check_in', assignee_id: null };
    await render(page('MoveOutIndex'), { records: { data: [row], last_page: 1 }, filters: {}, stages, owners, automaticEmail: false });
    await change(document.querySelector('select[aria-label="Assign Synthetic Tenant"]'), '2');
    assert.deepEqual(calls.at(-1).slice(0, 3), ['patch', '/portal/accommodation/move-out/7/assignee', { assignee_id: '2' }]);
    await act(async () => [...document.querySelectorAll('button')].find(el => el.textContent === 'Pipeline').click());
    assert.equal(calls.at(-1)[0], 'get');
    assert.equal(calls.at(-1)[2].view, 'pipeline');
    assert.equal(document.querySelector('a[aria-label="View move-out record for Synthetic Tenant"]').getAttribute('href'), '/portal/accommodation/move-out/7');
    await render(page('MoveOutStart'), { tenants: [{ id: 7, name: 'Synthetic Tenant', property: 'Synthetic House', ends: '2026-12-20' }] });
    await change(document.querySelector('select'), '7');
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(calls.at(-1)[1], '/portal/accommodation/move-out');
    assert.equal(calls.at(-1).at(-1).tenant_id, '7');
    await render(page('MoveOutShow'), { record: { ...row, tenantId: 7, propertyId: 1, ends: '2026-12-20', checkInOn: '2026-11-20' }, stages, events: [], owners, agreements: [], actions: [], today: '2026-11-28', pipelineAutomation: false });
    assert.match(document.body.textContent, /Assignment and stage history/);
    assert.match(document.body.textContent, /Synthetic Tenant/);
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Move-Out mounted checks passed: one portal shell, table/pipeline filters, owner write, record links, start form and detail history.');
})().catch(error => { console.error(error); process.exitCode = 1; });

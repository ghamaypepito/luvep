const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/accommodation/rental-invoices' });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { createRoot } = require('react-dom/client');
const previews = [];
window.axios = { post: async (...args) => {
    previews.push(args);
    return { data: new Blob(['preview'], { type: 'application/pdf' }) };
} };
const inertia = {
    Head: () => null, Link: ({ children, ...props }) => React.createElement('a', props, children),
    useForm(initial) {
        const [data, update] = React.useState(initial);
        return { data, setData: (key, value) => update(typeof key === 'function' ? key : old => ({ ...old, [key]: value })), errors: {} };
    },
};
const file = path.resolve('resources/js/pages/portal/accommodation/RentalInvoices.jsx');
const compiled = buildSync({
    entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', '@inertiajs/react'],
}).outputFiles[0].text;
const page = new Module(file, module);
page.paths = module.paths;
page.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
page._compile(compiled, file);
const root = createRoot(document.getElementById('root'));
const render = props => React.act(async () => root.render(React.createElement(page.exports.default, props)));
const click = node => React.act(async () => { assert.ok(node); node.click(); });
const change = (node, value) => React.act(async () => {
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value);
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
});
const button = text => [...document.querySelectorAll('button')].find(node => node.textContent === text);

(async () => {
    const tenant = { id: 1, name: 'Sample Tenant', property: 'Sample Property' };
    const props = { tenant, tenants: [tenant], history: { data: [] }, today: '2026-11-27', due_date: '2026-11-29',
        bank: { name: 'EXALT PROPERTY MANAGEMENT LTD', account: '01-0288-0332767-00' }, can_overdue: true, can_purchase: true };
    const lines = ['Bond', 'Deposit', 'Rent in advance', 'Grocery Package'].map((description, index) => ({
        description, amount_cents: index === 3 ? 15000 : 36500, group: index === 3 ? 'package' : 'move-in',
    }));
    await render({ ...props, kind: 'move-in', title: 'Move-In Invoice', agreements: [{ id: 8, label: 'Signed agreement', lines }], weeks: [] });
    assert.match(document.body.textContent, /Move-in subtotal NZD\s*1,095\.00/);
    assert.match(document.body.textContent, /Total amount due NZD\s*1,245\.00/);
    assert.deepEqual([...document.querySelectorAll('input[type="date"]')].map(node => node.value), ['2026-11-27', '2026-11-29']);
    await change(document.querySelector('input[type="date"]'), '2026-11-28');
    assert.equal(document.querySelectorAll('input[type="date"]')[1].value, '2026-11-30');
    await click(button('Preview PDF'));
    assert.equal(previews[0][1].lines[3].description, 'Grocery Package');
    assert.ok(document.querySelector('iframe[title="Invoice PDF preview"]'));

    const week = { start: '2026-11-09', end: '2026-11-15', original_due: '2026-11-06', received_on: '2026-11-07',
        due_cents: 36500, received_cents: 20000, outstanding_cents: 16500, version: 'a'.repeat(64) };
    await render({ ...props, kind: 'overdue', title: 'Overdue Payment Invoice', agreements: [], weeks: [week] });
    assert.match(document.body.textContent, /2026-11-06.*2026-11-07/);
    await click(document.querySelector('input[aria-label="Select week of 2026-11-09"]'));
    assert.match(document.body.textContent, /Total amount due NZD\s*165\.00/);
    assert.equal(button('Issue and save invoice').disabled, true);
    await click([...document.querySelectorAll('input[type="checkbox"]')].at(-1));
    assert.equal(button('Issue and save invoice').disabled, false);
    await click(button('Preview PDF'));
    assert.deepEqual(previews[1][1].weeks[0], { start: '2026-11-09', version: week.version, amount_cents: 16500 });
    await React.act(async () => root.unmount());
    dom.window.close();
    console.log('Rental invoice UI passed: move-in subtotal, editable due date, PDF preview and overdue week selection.');
})().catch(error => { console.error(error); process.exitCode = 1; });

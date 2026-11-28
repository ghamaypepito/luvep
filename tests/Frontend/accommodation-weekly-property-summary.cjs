const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test' });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { createRoot } = require('react-dom/client');

const writes = [];
const inertia = {
    Head: () => null,
    Link: ({ children, ...props }) => React.createElement('a', props, children),
    router: { patch: (...args) => writes.push(args) },
};
function loadPage(name) {
    const file = path.resolve(`resources/js/pages/portal/accommodation/${name}.jsx`);
    const compiled = buildSync({
        entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react/jsx-runtime', '@inertiajs/react', 'sonner', 'lucide-react'],
    }).outputFiles[0].text;
    const page = new Module(file, module);
    page.paths = module.paths;
    page.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
    page._compile(compiled, file);
    return page.exports.default;
}
const root = createRoot(document.getElementById('root'));

async function change(field, value) {
    const input = document.querySelector(`[aria-label="${field}"]`);
    await React.act(async () => {
        Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

(async () => {
    const week = { start: '2026-11-23', end: '2026-11-29', previous: '2026-11-16', next: '2026-11-30' };
    const rows = [
        { id: 2, name: 'First property', revenue_nzd: 1250.15, cost_nzd: 900.10, version: 3 },
        { id: 3, name: 'Second property', revenue_nzd: 1000, cost_nzd: 1100, version: 1 },
    ];
    const Summary = loadPage('WeeklyPropertySummary');
    const render = category => React.act(async () => root.render(React.createElement(Summary, { rows, week, category })));
    await render('rent');
    assert.deepEqual([...document.querySelectorAll('thead th')].map(node => node.textContent), ['Property', 'Revenue', 'Cost', 'Profit']);
    assert.match(document.querySelector('tfoot').textContent, /2,250\.15.*2,000\.10.*250\.05/);
    await change('Cost for First property', '900.11');
    assert.match(document.querySelector('tfoot').textContent, /250\.04/);
    assert.equal(writes.length, 0);
    await React.act(async () => document.querySelector('#weekly-property-2').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.deepEqual(writes[0][1], { week: '2026-11-23', category: 'rent', version: 3, revenue_nzd: 1250.15, cost_nzd: '900.11' });
    await React.act(async () => {
        writes[0][2].onError({ version: 'This property summary changed.' });
        writes[0][2].onFinish();
    });
    assert.match(document.querySelector('[role="alert"]').textContent, /summary changed/);
    assert.equal(document.querySelector('[aria-label="Cost for First property"]').value, '900.11');
    await render('utilities');
    assert.equal(document.querySelector('[aria-label="Cost for First property"]').value, '900.1');
    assert.ok(document.querySelector('a[href="?week=2026-11-16&category=utilities"],a[href$="?week=2026-11-16&category=utilities"]'));

    const RentUtilities = loadPage('RentUtilities');
    const weeks = [{ start: '2026-11-16', label: '16 Nov' }, { start: '2026-11-23', label: '23 Nov' }];
    const tenants = [380, 420, 260, 150].map((due, index) => ({
        id: index + 1,
        display_name: `Tenant ${index + 1}`,
        weekly_rent_nzd: due + 25,
        can_edit_rates: true,
        can_edit_payments: true,
        weekly: {
            '2026-11-16': { due: null, received: 20 },
            '2026-11-23': { due, received: [380, 400, 280, 150][index], captured: true },
        },
    }));
    const groups = [{ property_id: 1, property_address: '10A Chivalry Road', tenants }];
    await React.act(async () => root.render(React.createElement(RentUtilities, { groups, weeks, window: { start: '2026-11-16', weeks: 2 } })));
    const total = document.querySelector('th[scope="row"]').parentElement;
    assert.match(total.textContent, /Due \$1,210\.00Received \$1,210\.00/);
    assert.match(total.textContent, /Due —Received \$80\.00/);
    assert.match(document.querySelector('tbody').textContent, /Due unavailable.*Paid in full.*Underpaid — short \$20\.00.*Overpaid — credit \$20\.00/);
    await React.act(async () => root.unmount());
    dom.window.close();
    console.log('Finance UI passed: weekly summary tabs and totals, conflict feedback, historical payment display and rent roll property totals.');
})().catch(error => { console.error(error); process.exitCode = 1; });

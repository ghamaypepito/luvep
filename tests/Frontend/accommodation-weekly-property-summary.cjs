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
const file = path.resolve('resources/js/pages/portal/accommodation/WeeklyPropertySummary.jsx');
const compiled = buildSync({
    entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', '@inertiajs/react'],
}).outputFiles[0].text;
const page = new Module(file, module);
page.paths = module.paths;
page.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
page._compile(compiled, file);
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
    const render = category => React.act(async () => root.render(React.createElement(page.exports.default, { rows, week, category })));
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
    await React.act(async () => root.unmount());
    dom.window.close();
    console.log('Weekly Property Summary UI passed: tabs, week navigation, cent-accurate totals, revision-aware save and conflict feedback.');
})().catch(error => { console.error(error); process.exitCode = 1; });

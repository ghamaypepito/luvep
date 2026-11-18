const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/admin/agents' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const requests = [];
const inertia = { Head: () => null, Link: ({ children, ...props }) => React.createElement('a', props, children), router: { get: (...args) => requests.push(args) } };
const component = path.resolve('resources/js/pages/admin/agents/Index.jsx');
const compiled = buildSync({ entryPoints: [component], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react-dom', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(component, module);
loaded.filename = component;
loaded.paths = module.paths;
loaded.require = (name) => name === '@inertiajs/react' ? inertia : module.require(name);
loaded._compile(compiled, component);
const Page = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const click = async (element) => act(async () => element.dispatchEvent(new MouseEvent('click', { bubbles: true })));
const setInput = async (element, value) => act(async () => {
    Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
});

(async () => {
    const props = {
        agents: [{ id: 1, name: 'Synthetic Partner', email: 'partner@example.test', leads_count: 3, converted_count: 1, status_counts: [{ status: 'New Leads', count: 2 }, { status: 'Started Course', count: 1 }], has_agreement: true }],
        summary: { registered_partners: 1, leads: 3, converted: 1 },
        filters: { agent_id: '', from: '2026-11-01', to: '2026-11-09' },
        daily: [{ date: '2026-11-01', count: 1 }, { date: '2026-11-02', count: 1 }, { date: '2026-11-09', count: 1 }],
        statusCounts: [{ status: 'New Leads', count: 2 }, { status: 'Started Course', count: 1 }],
    };
    await act(async () => root.render(React.createElement(Page, props)));
    assert.match(document.body.textContent, /33.3%/);
    assert.equal(document.querySelectorAll('[role=listitem]').length, 9);
    await click([...document.querySelectorAll('button')].find((element) => element.textContent === 'Weekly'));
    assert.equal(document.querySelectorAll('[role=listitem]').length, 3);
    assert.match(document.querySelectorAll('[role=listitem]')[1].getAttribute('aria-label'), /1 leads/);
    assert.match(document.querySelector('a[href="/admin/agents/1"]').textContent, /Synthetic Partner/);
    assert.ok(document.querySelector('a[href="/admin/agents/agreements"]'));
    await setInput(document.querySelector('input[type=search]'), 'absent');
    assert.match(document.querySelector('tbody').textContent, /No affiliate partners match/);
    await setInput(document.querySelector('input[type=date]'), '2026-10-01');
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(requests[0][0], '/admin/agents');
    assert.equal(requests[0][1].from, '2026-10-01');
    assert.equal(requests[0][1].to, '2026-11-09');
    let printed = false;
    window.print = () => { printed = true; };
    await click([...document.querySelectorAll('button')].find((element) => element.textContent === 'Print / Save PDF'));
    assert.equal(printed, true);
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Affiliate performance: grouping, conversion ratio, filters, directory links/search and PDF action passed.');
})().catch(async (error) => {
    console.error(error);
    await act(async () => root.unmount());
    dom.window.close();
    process.exitCode = 1;
});

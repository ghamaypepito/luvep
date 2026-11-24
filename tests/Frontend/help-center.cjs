const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');
const React = require('react');
const { act } = React;

const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/help-center' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const { createRoot } = require('react-dom/client');
const output = buildSync({ entryPoints: ['resources/js/pages/help/Index.jsx'], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react-dom', '@/*', '@inertiajs/react', 'lucide-react'] }).outputFiles[0].text;
const compiled = new Module(path.resolve('help-center-test.cjs'), module);
const empty = () => null;
compiled.require = name => {
    if (name === '@inertiajs/react') return { Head: empty };
    if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
    if (name.startsWith('@/')) return { __esModule: true, default: empty };
    return module.require(name);
};
compiled.filename = path.resolve('help-center-test.cjs'); compiled.paths = module.paths; compiled._compile(output, compiled.filename);
const Page = compiled.exports.default;
const props = {
    departments: [{ id: 'getting-started', label: 'Getting started' }, { id: 'finance', label: 'Finance' }, { id: 'hr', label: 'HR' }],
    guides: [
        { id: '00', title: 'Start Here', description: 'Orientation and training.', departments: ['getting-started'], topics: ['First day'], keywords: '', url: 'https://docs.google.com/document/d/start/edit' },
        { id: '05', title: 'Finance', description: 'Invoices and reconciliation.', departments: ['finance'], topics: ['Proof of payment', 'Supporting evidence'], keywords: 'receipt bank transfer', url: 'https://docs.google.com/document/d/finance/edit' },
        { id: '07', title: 'HR', description: 'Employee and manager handbook.', departments: ['hr'], topics: ['Clock in', 'Leave'], keywords: 'DTR timesheet', url: 'https://docs.google.com/document/d/hr/edit' },
    ],
};
const root = createRoot(document.getElementById('root'));
const input = () => document.getElementById('help-search');
async function search(value) {
    await act(async () => {
        Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(input(), value);
        input().dispatchEvent(new Event('input', { bubbles: true }));
    });
}
const button = label => [...document.querySelectorAll('button')].find(b => b.textContent.trim() === label || b.textContent.trim() === label + '1');
const results = () => [...document.querySelectorAll('section[aria-labelledby="help-results-heading"] li h3')].map(x => x.textContent.trim());

(async () => {
    await act(async () => root.render(React.createElement(Page, props)));
    assert.equal(results().length, 3);
    assert.equal(document.querySelector('a').href, props.guides[0].url);
    for (const link of document.querySelectorAll('a')) {
        assert.equal(link.target, '_blank');
        assert.match(link.rel, /noopener/);
    }
    await search('  PROOF    PAYMENT  ');
    assert.equal(results().length, 1);
    assert.match(results()[0], /Finance/);
    await act(async () => button('HR').click());
    assert.match(document.body.textContent, /No guides found/);
    assert.equal(results().length, 0, 'Department and query filters must intersect');
    await act(async () => button('Show all guides').click());
    assert.equal(results().length, 3);
    assert.equal(document.activeElement, input());
    await search('DTR');
    assert.match(results()[0], /HR/);
    assert.equal(results().length, 1);
    await search('<img src=x onerror=alert(1)>');
    assert.equal(document.querySelector('img'), null, 'Search text must not become HTML');
    assert.match(document.body.textContent, /No guides found/);
    await act(async () => button('Clear filters').click());
    await act(async () => button('HR').click());
    assert.equal(results().length, 1);
    assert.equal(button('HR').getAttribute('aria-pressed'), 'true');
    assert.match(document.querySelector('[role="status"]').textContent, /1 guide available/);
    await act(async () => root.unmount());
    console.log('Help Center search, departments, empty state, safe links and reset interactions passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

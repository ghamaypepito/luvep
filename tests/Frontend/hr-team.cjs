const assert = require('node:assert/strict');
const path = require('node:path'), Module = require('node:module');
const { buildSync } = require('esbuild'), { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/hr/team' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const requests = [];
let poll, cleared = false, now = Date.now();
Date.now = () => now;
window.setInterval = (callback, duration) => { assert.equal(duration, 60000); poll = callback; return 1; };
window.clearInterval = () => { cleared = true; };
const inertia = {
    usePage: () => ({ props: { hr: { permissions: { 'leave.review': true, 'dtr.review': true }, can_view_dtr_changes: true } } }),
    Head: () => null,
    Link: ({ children, ...props }) => React.createElement('a', props, children),
    router: { reload: options => requests.push(options), on: (name, callback) => { document.addEventListener('inertia:' + name, callback); return () => document.removeEventListener('inertia:' + name, callback); } },
};
const compiled = buildSync({ entryPoints: ['resources/js/pages/hr/Team.jsx'], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, external: ['react', 'react-dom', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('hr-team-test.cjs'), module);
loaded.filename = path.resolve('hr-team-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(compiled, loaded.filename);
const Team = loaded.exports.default, root = createRoot(document.getElementById('root'));
const members = [
    { id: 1, name: 'Alex Active', department: 'Sales', status: 'working', started_at: '2026-11-18T01:00:00Z', timezone: 'Asia/Manila' },
    { id: 2, name: 'Bo Break', department: 'Education', status: 'on_break', started_at: '2026-11-18T01:00:00Z', break_started_at: '2026-11-18T02:00:00Z', timezone: 'Asia/Manila' },
    { id: 3, name: 'Chris Off', department: 'Sales', status: 'off_shift', timezone: 'UTC' },
];
const click = async node => act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true })));
const button = name => [...document.querySelectorAll('button')].find(node => node.textContent.startsWith(name));
(async () => {
    await act(async () => root.render(React.createElement(Team, { members, asOf: '2026-11-18T03:00:00Z' })));
    assert.equal(document.querySelectorAll('tbody tr').length, 3);
    await click(button('On break'));
    assert.equal(document.querySelectorAll('tbody tr').length, 1);
    assert.match(document.querySelector('tbody').textContent, /Bo Break/);
    await click(button('Everyone'));
    const input = document.querySelector('input[type="search"]');
    await act(async () => { Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(input, 'Sales'); input.dispatchEvent(new Event('input', { bubbles: true })); });
    assert.equal(document.querySelectorAll('tbody tr').length, 2);
    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
    now += 60000;
    await act(async () => poll());
    assert.equal(requests.length, 0, 'Hidden pages must not poll');
    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
    await act(async () => document.dispatchEvent(new Event('visibilitychange')));
    assert.equal(requests.length, 1);
    assert.deepEqual(requests[0].only, ['members', 'asOf', 'reviews', 'absences']);
    now += 60000;
    await act(async () => poll());
    assert.equal(requests.length, 1, 'No overlapping polls');
    const failure = new dom.window.CustomEvent('inertia:exception', { cancelable: true, detail: { exception: { config: { headers: requests[0].headers } } } });
    await act(async () => { document.dispatchEvent(failure); requests[0].onFinish(); });
    assert.equal(failure.defaultPrevented, true);
    assert.match(document.querySelector('[role="alert"]').textContent, /last confirmed clock states/);
    assert.equal(document.querySelectorAll('tbody tr').length, 2, 'Failed refresh keeps confirmed records');
    await click(button('Refresh status'));
    await act(async () => { requests[1].onSuccess(); requests[1].onFinish(); });
    assert.equal(document.querySelector('[role="alert"]'), null);
    await act(async () => root.unmount());
    assert.ok(cleared, 'Polling is cleaned up on navigation');
    console.log('HR team interactions passed');
})().catch(error => { console.error(error); process.exitCode = 1; });

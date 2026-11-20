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
let now = Date.parse("2026-11-18T15:00:00Z");
let monotonicNow = 0;
const advance = milliseconds => { now += milliseconds; monotonicNow += milliseconds; };
Object.defineProperty(window.performance, "now", { value: () => monotonicNow });
const timers = new Map();
const poll = () => timers.get(60000)();
const tick = () => timers.get(1000)();
Date.now = () => now;
window.setInterval = (callback, duration) => { assert.ok([1000, 60000].includes(duration)); timers.set(duration, callback); return duration; };
window.clearInterval = id => timers.delete(id);
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
    { id: 1, name: 'Alex Active', department: 'Sales', status: 'working', working_seconds: 7200, break_seconds: 0, started_at: '2026-11-18T01:00:00Z', timezone: 'Asia/Manila' },
    { id: 2, name: 'Bo Break', department: 'Education', status: 'on_break', working_seconds: 6600, break_seconds: 600, started_at: '2026-11-18T01:00:00Z', break_started_at: '2026-11-18T02:00:00Z', timezone: 'Asia/Manila' },
    { id: 3, name: 'Chris Off', department: 'Sales', status: 'off_shift', timezone: 'UTC' },
];
const click = async node => act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true })));
const button = name => [...document.querySelectorAll('button')].find(node => node.textContent.startsWith(name));
(async () => {
    await act(async () => root.render(React.createElement(Team, { members, asOf: '2026-11-18T03:00:00Z' })));
    assert.equal(document.querySelectorAll('tbody tr').length, 3);
    const timer = label => document.querySelector(`output[aria-label="${label}"]`)?.textContent;
    assert.equal(timer('Alex Active work time'), '02:00:00', 'Server totals do not depend on the device clock');
    assert.equal(timer('Bo Break work time'), '01:50:00');
    assert.equal(timer('Bo Break break time'), '00:10:00');
    assert.equal(timer('Chris Off work time'), undefined);
    advance(2000);
    await act(async () => tick());
    assert.equal(timer('Alex Active work time'), '02:00:02');
    assert.equal(timer('Alex Active break time'), '00:00:00');
    assert.equal(timer('Bo Break work time'), '01:50:00', 'Work pauses during a break');
    assert.equal(timer('Bo Break break time'), '00:10:02');
    assert.equal(requests.length, 0, 'Second-by-second ticking must not make network requests');
    const originalWallClock = now;
    for (const adjustment of [3600000, -3600000]) {
        now = originalWallClock + adjustment;
        await act(async () => tick());
        assert.equal(timer('Alex Active work time'), '02:00:02', 'Changing the device clock must not move elapsed time');
    }
    now = originalWallClock;
    await click(button('On break'));
    assert.equal(document.querySelectorAll('tbody tr').length, 1);
    assert.match(document.querySelector('tbody').textContent, /Bo Break/);
    await click(button('Everyone'));
    const input = document.querySelector('input[type="search"]');
    await act(async () => { Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(input, 'Sales'); input.dispatchEvent(new Event('input', { bubbles: true })); });
    assert.equal(document.querySelectorAll('tbody tr').length, 2);
    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
    advance(60000);
    await act(async () => { poll(); tick(); });
    assert.equal(requests.length, 0, 'Hidden pages must not poll');
    assert.equal(timer('Alex Active work time'), '02:00:02', 'Hidden tabs do not repaint timers');
    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
    await act(async () => document.dispatchEvent(new Event('visibilitychange')));
    assert.equal(requests.length, 1);
    assert.deepEqual(requests[0].only, ['members', 'asOf', 'reviews', 'absences']);
    advance(60000);
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
    const updated = members.map(member => member.id === 1 ? { ...member, status: 'on_break', working_seconds: 7240, break_seconds: 20 } : member);
    await act(async () => root.render(React.createElement(Team, { members: updated, asOf: '2026-11-18T03:02:00Z' })));
    assert.equal(timer('Alex Active work time'), '02:00:40');
    assert.equal(timer('Alex Active break time'), '00:00:20', 'New server snapshots rebase the timers');
    advance(3000);
    await act(async () => tick());
    assert.equal(timer('Alex Active work time'), '02:00:40');
    assert.equal(timer('Alex Active break time'), '00:00:23');
    advance(180000);
    await act(async () => tick());
    assert.equal(timer('Alex Active break time'), '00:02:20', 'Stale timers stop extrapolating');
    assert.match(document.querySelector('[role="alert"]').textContent, /Timers are paused/);
    const finished = updated.map(member => member.id === 1 ? { ...member, status: 'off_shift' } : member);
    await act(async () => root.render(React.createElement(Team, { members: finished, asOf: '2026-11-18T03:05:00Z' })));
    assert.equal(timer('Alex Active work time'), undefined);
    assert.equal(document.querySelector('[role="alert"]'), null);
    await act(async () => root.render(React.createElement(Team, { members: [{ ...members[0], working_seconds: 90000 }], asOf: '2026-11-18T03:06:00Z' })));
    assert.equal(timer('Alex Active work time'), '25:00:00', 'Duration must not wrap at midnight');
    await act(async () => root.unmount());
    assert.equal(timers.size, 0, 'Polling and live ticks are cleaned up on navigation');
    console.log('HR team interactions passed');
})().catch(error => { console.error(error); process.exitCode = 1; });

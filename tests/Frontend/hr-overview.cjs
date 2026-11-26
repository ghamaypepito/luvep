const assert = require('node:assert/strict');
const path = require('node:path'), Module = require('node:module');
const { buildSync } = require('esbuild'), { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/hr/dashboard' });
for (const key of ['window', 'document', 'HTMLElement', 'CustomEvent', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
dom.window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
dom.window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };

const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
let hr = { enabled: true, employee: { id: 42 }, portal_mode: true };
const inertia = {
    usePage: () => ({ props: { hr } }),
    Head: () => null,
    Link: props => React.createElement('a', props),
};
const state = {
    status: 'working', working_seconds: 18720, break_seconds: 1800,
    version: 3, shift_id: 21, timezone: 'Asia/Manila', started_at: '2024-01-31T00:02:00Z',
    server_now: '2024-01-31T03:00:00Z', scheduled_end_at: '2024-01-31T03:10:00Z',
};
let gets = 0, fail = false, current = state;
let now = Date.now();
Date.now = () => now;
const timers = new Map();
let timerId = 0;
global.setInterval = (callback, delay) => {
    timers.set(++timerId, { callback, delay });
    return timerId;
};
global.clearInterval = id => timers.delete(id);
const posts = [];
const axios = {
    get: async endpoint => {
        assert.match(endpoint, /^\/(portal\/)?hr\/clock$/);
        gets++;
        if (fail) throw Error('offline');
        return { data: current };
    },
    post: async (endpoint, body) => {
        posts.push({ endpoint, body });
        if (endpoint.endsWith('/reminder')) return { data: { notice: { shift_id: current.shift_id } } };
        const status = body.action === 'break_out' ? 'on_break' : 'working';
        current = { ...current, status, version: current.version + 1, break_started_at: status === 'on_break' ? current.server_now : null };
        return { data: current };
    },
};
const compiled = buildSync({
    stdin: { contents: `
        export { default as Dashboard } from './resources/js/pages/hr/Dashboard.jsx';
        export { default as ClockWidget, HrClockProvider } from './resources/js/components/hr/ClockWidget.jsx';
    `, resolveDir: process.cwd() },
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    alias: { '@': path.resolve('resources/js') }, loader: { '.css': 'empty' },
    external: ['react', '@inertiajs/react', 'axios'],
}).outputFiles[0].text;
const loaded = new Module(path.resolve('hr-overview-test.cjs'), module);
loaded.filename = path.resolve('hr-overview-test.cjs');
loaded.paths = module.paths;
loaded.require = name => ({ '@inertiajs/react': inertia, axios }[name] || module.require(name));
loaded._compile(compiled, loaded.filename);
const { Dashboard, ClockWidget, HrClockProvider } = loaded.exports;
const root = createRoot(document.getElementById('root'));
const props = {
    profile: { user: { name: 'Andrea Employee' }, manager: 'Casey Manager' },
    overview: { mode: 'employee', today: '2024-01-31', server_now: state.server_now, timezone: state.timezone },
    ownSummary: { week_reports: 7, pending_changes: 2, week_from: '2024-01-29', week_to: '2024-01-31' },
    upcomingLeave: [
        { id: 3, status: 'pending', start_date: '2024-02-28', end_date: '2024-02-28' },
        { id: 2, status: 'approved', start_date: '2024-02-29', end_date: '2024-03-01' },
    ],
};
const button = label => [...document.querySelectorAll('button')].find(node => node.getAttribute('aria-label') === label || node.textContent.trim() === label);
const click = async node => {
    assert.ok(node);
    await act(async () => node.click());
};
const render = async (key, child) => act(async () => root.render(React.createElement(HrClockProvider, { key, hr }, child)));
const dashboard = overrides => React.createElement(Dashboard, { ...props, ...overrides });
const timer = () => document.querySelector('[data-testid="work-timer"]');
(async () => {
    await render('employee', dashboard());
    assert.equal(gets, 1);
    assert.equal(timers.size, 2);
    assert.equal(document.querySelector('h1').textContent, 'Good morning, Andrea.');
    assert.equal(document.querySelector('time').dateTime, '2024-01-31');
    assert.equal(timer().textContent.trim(), '05:12:00');
    assert.match(document.querySelector('.hr-clock-totals').textContent, /08:02/);
    assert.match(document.querySelector('.hr-personal-summary').textContent, /7 daily reports.*2 awaiting acknowledgement/);
    assert.match(document.querySelector('.hr-rail-contact').textContent, /Casey Manager/);
    assert.equal(posts.filter(row => row.endpoint.endsWith('/reminder')).length, 1);

    await click(button('Next month'));
    assert.equal(document.querySelector('.hr-calendar h3').textContent, 'February 2024');
    await click(button('2024-02-28'));
    assert.equal(document.querySelector('.hr-leave-date small').textContent, 'Wednesday');
    assert.equal(document.querySelector('.hr-rail-section .hr-staff-on_break').textContent, 'Awaiting leave decision');
    await click(button('2024-02-29'));
    assert.equal(button('2024-02-29').getAttribute('aria-pressed'), 'true');
    assert.equal(document.querySelector('.hr-leave-date strong').textContent, '29 Feb');
    assert.equal(document.querySelector('.hr-leave-date small').textContent, '– 1 Mar');
    assert.equal(document.querySelector('.hr-rail-section .hr-staff-working').textContent, 'Approved');
    const selectionLink = document.querySelector('.hr-calendar-caption a');
    assert.equal(selectionLink.getAttribute('href'), '/portal/hr/calendar?date=2024-02-29&view=day');
    assert.equal(selectionLink.getAttribute('aria-label'), 'Open calendar for 2024-02-29');
    await click(button('Previous month'));
    await click(button('Previous month'));
    assert.equal(document.querySelector('.hr-calendar h3').textContent, 'December 2023');
    await click(button('2023-12-31'));
    assert.match(document.querySelector('.hr-rail-section').textContent, /No upcoming leave shown/);
    await click(button('Next month'));
    assert.equal(document.querySelector('.hr-calendar h3').textContent, 'January 2024');

    await click(button('Start break'));
    const dialog = document.querySelector('dialog[open]');
    assert.ok(dialog);
    assert.equal(posts.find(row => row.body.action === 'break_out').body.expected_version, 3);
    await render('employee', React.createElement('div', null, 'Documents'));
    assert.equal(gets, 1);
    assert.equal(document.querySelector('dialog[open]'), dialog);
    now += 31000;
    await act(async () => [...timers.values()].find(({ delay }) => delay === 1000).callback());
    assert.equal(button('I’m back · resume work').disabled, true);
    assert.match(dialog.querySelector('[role="alert"]').textContent, /may be out of date/);
    await click(button('Refresh clock'));
    assert.equal(button('I’m back · resume work').disabled, false);
    await click(button('I’m back · resume work'));
    assert.equal(posts.find(row => row.body.action === 'break_in').body.expected_version, 4);
    assert.equal(document.querySelector('dialog[open]'), null);
    await render('employee', React.createElement(ClockWidget));
    assert.equal(gets, 2);
    assert.equal(timer().textContent.trim(), '05:12:00');
    assert.equal(posts.filter(row => row.endpoint.endsWith('/reminder')).length, 1);

    fail = true;
    await act(async () => window.dispatchEvent(new dom.window.Event('online')));
    assert.match(document.querySelector('[role="alert"]').textContent, /Clock unavailable/);
    fail = false;
    await click(button('Refresh clock'));
    assert.equal(document.querySelector('[role="alert"]'), null);

    current = { ...current, status: 'working', working_seconds: 35999, max_working_seconds: 36000 };
    await act(async () => window.dispatchEvent(new dom.window.Event('online')));
    now += 5000;
    await act(async () => [...timers.values()].find(({ delay }) => delay === 1000).callback());
    assert.equal(timer().textContent.trim(), '10:00:00', 'The live timer must stop extrapolating at the server work limit');
    assert.equal(button('Start break').disabled, true, 'A reached work limit cannot start another break');

    current = { ...current, status: 'off_shift', can_clock_in: false, completed_today: true, automatically_clocked_out: true };
    await act(async () => window.dispatchEvent(new dom.window.Event('online')));
    assert.equal(button('Clock in').disabled, true);
    assert.match(document.querySelector('.hr-clock-paused').textContent, /Automatically clocked out after 10 hours of work/);

    const team = {
        summary: { working: 1, on_break: 2, off_shift: 1 },
        reviews: { acknowledgements: 8, dtr: 4, leave: 2 },
        members: [
            { id: 1, name: 'Real Staff', status: 'working' }, { id: 2, name: 'Another Staff', status: 'on_break' },
            { id: 3, name: 'Third Staff', status: 'on_break' }, { id: 4, name: 'Fourth Staff', status: 'off_shift' },
        ],
    };
    const adminOverview = { ...props.overview, mode: 'admin' };
    hr = { enabled: true, permissions: { 'leave.review': true }, can_view_dtr_changes: true };
    const previousGets = gets;
    await render('admin', dashboard({ overview: adminOverview, teamOverview: team }));
    assert.equal(gets, previousGets);
    assert.equal(timer(), null);
    assert.match(document.querySelector('.hr-empty').textContent, /sets up your employee profile/);
    assert.equal(document.querySelectorAll('.hr-overview-staff > div').length, 3);
    assert.match(document.querySelector('.hr-overview-staff').textContent, /Real Staff/);
    assert.doesNotMatch(document.querySelector('.hr-overview-staff').textContent, /Fourth Staff/);
    assert.match(document.querySelector('a[href="/portal/hr/dtr/changes"]').textContent, /8/);
    assert.equal(document.querySelector('a[href="/portal/hr/dtr/review"]'), null, 'Review links follow authorization');

    hr = { enabled: true, employee: { id: 42 }, portal_mode: true, can_view_dtr_changes: true };
    await render('head', dashboard({ overview: { ...props.overview, mode: 'head' }, teamOverview: team }));
    assert.ok(timer());
    assert.equal(document.querySelector('h1').textContent, 'A clear view of your team.');

    hr = { ...hr, preview: true };
    await render('preview', dashboard({ teamOverview: team }));
    assert.match(document.querySelector('.hr-attendance').textContent, /Read-only preview/);
    assert.equal(button('Clock in'), undefined);
    assert.equal(document.querySelector('dialog'), null);
    assert.equal(document.querySelector('.hr-overview-staff'), null);
    assert.equal(document.querySelector('a[href="/portal/hr/dtr/changes?scope=mine"]'), null, 'Preview hides links to protected DTR changes');

    hr = { enabled: true, employee: { id: 42 }, url: 'https://hr.luvep.test' };
    const standaloneGets = gets;
    await act(async () => root.render(React.createElement(ClockWidget)));
    assert.equal(gets, standaloneGets + 1);
    assert.ok(timer(), 'Standalone clock callers retain their own clock session');
    console.log('HR overview and shared clock interactions passed');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
}).finally(async () => {
    await act(async () => root.unmount());
    assert.equal(timers.size, 0);
    dom.window.close();
});

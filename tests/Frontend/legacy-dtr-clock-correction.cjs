const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const test = require('node:test');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/immigration/dtr' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
HTMLElement.prototype.scrollIntoView = function () {};
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const posts = [], empty = () => null;
function load(file) {
    const code = buildSync({ entryPoints: [file], bundle: true, write: false, platform: 'node', format: 'cjs', jsx: 'automatic', external: ['react', 'react-dom', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react', '@/components/creative/AssignedCreativeWork'] }).outputFiles[0].text;
    const loaded = new Module(path.resolve('legacy-dtr-test.cjs'), module);
    loaded.filename = path.resolve('legacy-dtr-test.cjs'); loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? { Head: empty, Link: ({ children, ...props }) => React.createElement('a', props, children), router: { post: (...args) => posts.push(args), get() {} } }
        : name === 'lucide-react' ? new Proxy({}, { get: () => empty }) : name === '@/components/creative/AssignedCreativeWork' ? empty : module.require(name);
    loaded._compile(code, loaded.filename);
    return loaded.exports.default;
}
const Dtr = load('resources/js/pages/dtr/DtrPage.jsx'), Reports = load('resources/js/pages/admin/DtrReports.jsx');
const root = createRoot(document.getElementById('root'));
const render = (Component, props) => act(async () => root.render(React.createElement(Component, props)));
const button = text => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === text);
const click = node => act(async () => { assert.ok(node, 'Control exists'); node.click(); });
const input = (node, value) => act(async () => { Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value); node.dispatchEvent(new Event('input', { bubbles: true })); });

test('actual overnight banner binds the displayed entry rather than the newest open day', async () => {
    const setting = { is_complete: true, timezone: 'Asia/Manila', team: 'Philippines', sched_in: '05:00', sched_out: '14:00', std_hours: 8, break_hours: 1, break_after: 6 };
    await render(Dtr, { setting, today: '2026-11-21', account: { name: 'Synthetic employee' }, entries: [
        { id: 8, work_date: '2026-11-21', time_in: '06:00', time_out: null, tasks: [] },
        { id: 7, work_date: '2026-11-20', time_in: '05:00', time_out: null, tasks: [] },
    ] });
    assert.match(document.body.textContent, /Still clocked in from 2026-11-20/);
    await click(button('Clock out that shift'));
    assert.equal(posts.at(-1)[0], '/dtr/time-out');
    assert.deepEqual(posts.at(-1)[1], { entry_id: 7 });
});

test('actual time-only editor submits intent and preserves newer omitted report content', async () => {
    let row = { user_id: 41, entry_id: 99, name: 'Synthetic staff', submitted: true, time_in: '05:00', time_out: null,
        tasks: [{ task: '', tid: 'blank' }, { task: 'Existing work', pending: '', status: 'todo', tid: 'keep-id', kind: 'side' }], remarks: 'Original remarks' };
    const props = () => ({ date: '2026-11-20', today: '2026-11-21', weekStart: '2026-11-16', staffCount: 1, roster: [row] });
    await render(Reports, props());
    await click(document.querySelector('button[title="Edit this day"]'));
    await input(document.querySelectorAll('input[type=time]')[1], '14:00');
    row = { ...row, remarks: 'Newer saved remarks', tasks: [...row.tasks, { task: 'Newer task', tid: 'new-id' }] };
    await render(Reports, props());
    await click(button('Save changes'));
    const correction = posts.at(-1);
    assert.equal(correction[0], '/admin/dtr/entry');
    assert.deepEqual(correction[1], { user_id: 41, work_date: '2026-11-20', expected_entry_id: 99, time_out: '14:00' });
    assert.equal(button('Saving…').disabled, true);
    await act(async () => { correction[2].onSuccess(); correction[2].onFinish(); });
    assert.equal(button('Save changes'), undefined);

    row = { ...row, time_out: '14:00' };
    await render(Reports, props());
    await click(document.querySelector('button[title="Edit this day"]'));
    await input(document.querySelector('textarea'), '');
    await click(button('Save changes'));
    const clear = posts.at(-1);
    assert.deepEqual(clear[1], { user_id: 41, work_date: '2026-11-20', expected_entry_id: 99, remarks: null });
    await act(async () => { clear[2].onSuccess(); clear[2].onFinish(); });
    row = { ...row, remarks: null };
    await render(Reports, props());
    await click(document.querySelector('button[title="Edit this day"]'));
    const before = posts.length;
    await click(button('Save changes'));
    assert.equal(posts.length, before, 'Unchanged existing records do not post');
});

test('switching report dates discards the previous day editor draft', async () => {
    const props = { date: '2026-11-19', today: '2026-11-21', weekStart: '2026-11-16', staffCount: 1,
        roster: [{ user_id: 42, entry_id: null, name: 'Synthetic new record', submitted: false, tasks: [] }] };
    await render(Reports, props);
    await click(document.querySelector('button[title="Add a record for this day"]'));
    await input(document.querySelectorAll('input[type=time]')[0], '05:00');
    await input(document.querySelectorAll('input[type=time]')[1], '14:00');
    const before = posts.length;
    await render(Reports, { ...props, date: '2026-11-20' });
    assert.equal(Boolean(button('Save changes')), false, 'Changing the day closes the old editor');
    assert.equal(posts.length, before);
    await click(document.querySelector('button[title="Add a record for this day"]'));
    assert.equal(document.querySelectorAll('input[type=time]')[0].value, '');
    assert.equal(document.querySelectorAll('input[type=time]')[1].value, '');
    await input(document.querySelectorAll('input[type=time]')[0], '06:00');
    await click(button('Save changes'));
    assert.deepEqual(posts.at(-1)[1], { user_id: 42, work_date: '2026-11-20', expected_entry_id: null,
        time_in: '06:00', time_out: null, remarks: null, tasks: [] });
});

test.after(async () => { await act(async () => root.unmount()); dom.window.close(); });

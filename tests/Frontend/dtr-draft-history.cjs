const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/hr/dtr' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const requests = [], reads = [];
let preview = false, fetchFails = false;
const inertia = {
    Head: () => null, Link: ({children,...props}) => React.createElement('a',props,children), usePage: () => ({ props: { hr: { preview } } }), router: { reload() {}, get: (url, data) => requests.push({ url, data }) },
    useForm(initial) {
        const [data, setData] = React.useState(initial), [errors, setErrors] = React.useState({});
        return { data, errors, processing: false, isDirty: JSON.stringify(initial) !== JSON.stringify(data),
            setData: (key, value) => setData(old => ({ ...old, [key]: value })), reset: () => setData(initial), clearErrors: () => setErrors({}),
            post: (url, options) => requests.push({ url, data, options, fail: messages => setErrors(messages) }),
        };
    },
};
global.fetch = async url => {
    reads.push(url);
    return { ok: !fetchFails, json: async () => ({ history: { current_page: 1, last_page: 2, data: [{ id: 2, version: 6, action: 'dtr.draft_saved', actor: 'Synthetic employee', created_at: '2026-11-04T03:00:00Z', changes: { work_location: { before: 'Office', after: 'Remote' }, meetings: { before: 0, after: 1 }, remarks: { before: 'case_ref_1', after: 'case_ref_2' } } }] } }) };
};
const compiled = buildSync({ entryPoints: ['resources/js/pages/hr/Dtr.jsx'], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, external: ['react', 'react-dom', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('dtr-history-test.cjs'), module); loaded.filename = path.resolve('dtr-history-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(compiled, loaded.filename);
const Dtr = loaded.exports.default, root = createRoot(document.getElementById('root'));
const task = { task: 'Client coordination', status: 'done', category: 'client', start: '08:00', end: '09:00', reference: 'Synthetic', seconds: 3600 };
const old = { id: 17, hr_version: 5, work_date: '2026-11-02', hr_status: 'draft', can_edit: true, time_in: '08:00', time_out: '16:00', tasks: [task], work_location: 'Office', working_seconds: 27000, break_seconds: 1800 };
const today = { ...old, id: 19, work_date: '2026-11-04', hr_version: 2 };
const render = async entries => act(async () => root.render(React.createElement(Dtr, { entries, weeks: [], today: '2026-11-04' })));
const button = text => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === text);
const click = async node => { assert.ok(node, 'Control exists'); await act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true }))); };
const input = async (node, value) => act(async () => { const prototype = node.tagName === 'SELECT' ? dom.window.HTMLSelectElement.prototype : dom.window.HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(prototype, 'value').set.call(node, value); node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); });
const toggleHistory = async () => act(async () => { const details = document.querySelector('.hr-dtr-history'); details.open = !details.open; details.dispatchEvent(new Event('toggle')); });
(async () => {
    await render([today, old]);
    await input(document.querySelector('select'), '17');
    await click(button('Edit draft'));
    assert.match(document.body.textContent, /work for 2026-11-02/);
    assert.equal(document.querySelector('input[name="time_in"]'), null);
    const location = [...document.querySelectorAll('label')].find(node => node.textContent.trim() === 'Work location').querySelector('input');
    await input(location, 'Remote');
    await click(document.querySelector('.hr-checkbox input'));
    assert.equal(button('Submit daily report').disabled, true, 'Unsaved changes cannot be submitted');
    await act(async () => location.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(requests.at(-1).url, '/portal/hr/dtr');
    assert.equal(requests.at(-1).data.work_date, '2026-11-02');
    assert.equal(requests.at(-1).data.expected_version, 5);
    assert.equal(requests.at(-1).data.work_location, 'Remote');
    assert.equal(Object.hasOwn(requests.at(-1).data, 'time_in'), false);
    await act(async () => requests.at(-1).fail({ tasks: 'Task times overlap a break.' }));
    assert.match(document.querySelector('[role="alert"]').textContent, /overlap a break/);
    assert.equal(location.value, 'Remote');
    await render([today, { ...old, hr_version: 6, work_location: 'Remote' }]);
    assert.ok(button('Edit draft'), 'Successful save returns to the selected dated record');
    await click(document.querySelector('.hr-checkbox input'));
    assert.equal(button('Submit daily report').disabled, false);
    await act(async () => button('Submit daily report').closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(requests.at(-1).url, '/portal/hr/dtr/17/submit');
    assert.equal(requests.at(-1).data.version, 6);
    assert.equal(requests.at(-1).data.declaration, true);
    assert.equal(reads.length, 0, 'History is loaded on demand');
    await toggleHistory();
    assert.equal(reads.at(-1), '/portal/hr/dtr/17/history?page=1');
    assert.match(document.body.textContent, /Synthetic employee/);
    assert.match(document.querySelector('.hr-dtr-revision').textContent, /BeforeAfter/);
    assert.match(document.querySelector('.hr-dtr-revision').textContent, /OfficeRemote/);
    assert.match(document.querySelector('.hr-dtr-revision').textContent, /case_ref_1case_ref_2/, 'History preserves exact work-report wording');
    assert.equal(document.querySelector('tbody td:last-child').textContent, 'done');
    await click(button('Older changes')); assert.equal(reads.at(-1), '/portal/hr/dtr/17/history?page=2');
    await toggleHistory(); fetchFails = true; await toggleHistory();
    assert.match(document.querySelector('[role="alert"]').textContent, /Unable to load/);
    fetchFails = false; await click(button('Retry history')); assert.equal(document.querySelector('[role="alert"]'), null);
    await render([today, { ...old, hr_version: 7, hr_status: 'submitted', can_edit: false }]);
    assert.equal(button('Edit draft'), undefined); assert.equal(button('Submit daily report'), undefined);
    const findDate = document.querySelector('input[type=date]'); await input(findDate, '2026-08-01');
    await act(async () => findDate.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(requests.at(-1).url, '/portal/hr/dtr'); assert.equal(requests.at(-1).data.date, '2026-08-01');
    preview = true; await render([today, { ...old, hr_version: 8 }]);
    assert.equal(button('Edit draft'), undefined); assert.equal(document.querySelector('.hr-dtr-history'), null);
    await act(async () => root.unmount()); dom.window.close();
    console.log('DTR selected-date editing, save/version/declaration, validation retention, locks, lazy before/after history, pagination and retry verified.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); dom.window.close(); process.exitCode = 1; });

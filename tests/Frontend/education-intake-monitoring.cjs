const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/students/intake-monitoring' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const inertia = { Head: () => null, Link: ({ children, ...props }) => React.createElement('a', props, children) };
const output = buildSync({ entryPoints: ['resources/js/pages/portal/education/IntakeMonitoring.jsx'], bundle: true, write: false,
    format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('education-intake-monitoring.cjs'), module);
loaded.filename = path.resolve('education-intake-monitoring.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
loaded._compile(output, loaded.filename);
const Board = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const make = (id, status, extra = {}) => Object.freeze({ id, status, name: 'Student ' + id, lead_id: 'REF-' + id,
    school: 'A School', track: 'education', month_key: '2026-11', month_label: 'November 2026', intake: '2026-11-23', ...extra });
const rows = Object.freeze([
    make(1, 'Decline Visa'), make(2, 'Conditional Offer'), make(3, 'Endorsed to Immigration', { track: 'immigration' }),
    make(4, 'Unconditional Offer'), make(5, 'Visa Lodged', { track: 'immigration' }),
    make(6, 'Approved Visa', { track: 'immigration', school: 'Z School' }),
    make(7, 'Started Course', { name: 'Started Z', school: 'Z School' }), make(8, 'Started Course', { name: 'Started A' }),
    make(9, 'Visa Approved', { track: 'english' }), make(10, 'Visa Lodged'),
    make(11, 'Approved Visa', { month_key: '9999-99', month_label: 'Unscheduled', intake: null }),
    make(12, 'On Hold', { month_key: '2026-12', month_label: 'December 2026' }),
]);
const render = students => act(async () => root.render(React.createElement(Board, { students })));
const byLabel = label => document.querySelector('[aria-label="' + label + '"]');
const button = label => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === label);
const track = label => [...document.querySelectorAll('button')].find(node => node.textContent.trim().startsWith(label + '·'));
const click = node => act(async () => { assert.ok(node); node.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
const search = value => act(async () => {
    const node = document.querySelector('input[placeholder="Search name or school"]');
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value);
    node.dispatchEvent(new Event('input', { bubbles: true }));
});
const ids = element => [...element.querySelectorAll('a[href^="/portal/education/leads/"]')].map(node => Number(node.getAttribute('href').split('/').at(-1)));
(async () => {
    await render(rows);
    const month = byLabel('November 2026 intake');
    assert.match(byLabel('Monthly stage summary for November 2026').textContent, /7\/10 in the four tracked stages/);
    assert.deepEqual([...month.querySelectorAll('[role="group"]')].map(node => node.getAttribute('aria-label')), [
        'Started Course intake entries', 'Approved Visa intake entries', 'Visa Lodged intake entries',
        'Endorsed to Immigration intake entries', 'Unconditional Offer intake entries', 'Conditional Offer intake entries', 'Declined Visa intake entries',
    ]);
    assert.deepEqual(ids(month), [8, 7, 6, 9, 10, 5, 3, 4, 2, 1]);
    const summary = byLabel('Monthly stage summary for November 2026');
    assert.deepEqual([...summary.querySelectorAll('dd')].map(node => node.textContent), ['2', '2', '2', '1']);
    assert.equal(byLabel('Unscheduled intake').querySelector('dl'), null, 'Unscheduled entries are not a month denominator');
    assert.match(byLabel('Monthly stage summary for December 2026').textContent, /0\/1/);
    assert.equal(ids(document).length, 12, 'Keep every student visible, not just the four counted stages');
    assert.deepEqual([...document.querySelectorAll('section[aria-label$=" intake"]')].map(node => node.getAttribute('aria-label')), [
        'Unscheduled intake', 'November 2026 intake', 'December 2026 intake',
    ]);
    await click(button('school'));
    assert.deepEqual([...month.querySelectorAll('[role="group"]')].map(node => node.getAttribute('aria-label')), ['A School intake entries', 'Z School intake entries']);
    assert.deepEqual(ids(month.querySelector('[aria-label="Z School intake entries"]')), [7, 6], 'Keep stage priority inside each school');
    assert.match(summary.textContent, /7\/10/);
    await click(button('stage'));
    await click(track('Immigration'));
    assert.match(byLabel('Monthly stage summary for November 2026').textContent, /2\/3/);
    assert.deepEqual(ids(byLabel('November 2026 intake')), [6, 5, 3]);
    await click(track('All'));
    await search('Started');
    assert.match(byLabel('Monthly stage summary for November 2026').textContent, /2\/2/);
    assert.deepEqual(ids(byLabel('November 2026 intake')), [8, 7]);
    await search('not a student');
    assert.match(document.body.textContent, /No students match/);
    assert.equal(document.querySelector('dl'), null, 'Do not render a misleading zero/zero ratio');
    await search('');
    await render([...rows, make(13, 'Request for Information'), make(14, null)]);
    assert.match(byLabel('Monthly stage summary for November 2026').textContent, /7\/12/);
    const groups = byLabel('November 2026 intake').querySelectorAll('[role="group"]');
    assert.equal(groups[groups.length - 1].getAttribute('aria-label'), 'Other stages intake entries');
    assert.deepEqual(ids(groups[groups.length - 1]), [14, 13]);
    await render(rows);
    let csvBlob;
    URL.createObjectURL = blob => { csvBlob = blob; return 'blob:synthetic'; };
    URL.revokeObjectURL = () => {};
    dom.window.HTMLAnchorElement.prototype.click = function () {};
    await click(button('Export CSV'));
    const csv = await csvBlob.text();
    assert.match(csv.split('\n')[0], /"Name","Reference","Location","Status","Track","Intake","School","Program"/);
    assert.deepEqual(csv.split('\n').slice(1).map(line => line.split(',')[1]), [11, 8, 7, 6, 9, 10, 5, 3, 4, 2, 1, 12].map(id => '"REF-' + id + '"'));
    assert.match(csv, /"Visa Approved"/, 'Preserve original status text in export');
    assert.deepEqual(rows.map(row => row.id), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'Do not mutate incoming students');
    await act(async () => root.unmount());
    console.log('Intake Monitoring actual-page tests passed: exact stage order, monthly 7/10 and breakdown, aliases, other stages, school/track/search, unscheduled, CSV and immutable input');
})().catch(error => { console.error(error); process.exitCode = 1; });

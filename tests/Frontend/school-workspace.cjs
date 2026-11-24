const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/schools/1/management' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'); const { act } = React; const { createRoot } = require('react-dom/client');
const posts = [];
const inertia = { Head: () => null, Link: ({ children, ...props }) => React.createElement('a', props, children), router: { post: (...args) => posts.push(args) } };
const output = buildSync({ entryPoints: ['resources/js/pages/portal/education/SchoolWorkspace.jsx'], bundle: true, write: false,
    format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('school-workspace-test.cjs'), module); loaded.filename = path.resolve('school-workspace-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(output, loaded.filename);
const Page = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const props = { school: { id: 1, name: 'Synthetic School' }, today: '2026-11-24',
    programs: [{ id: 10, title: 'Course One', level: 7, status: 'published', tuition_fee: 12345 }, { id: 11, title: 'Course Two', status: 'published' }],
    students: [{ id: 20, first_name: 'Synthetic', last_name: 'Student', school_id: 1 }, { id: 21, first_name: 'Review', last_name: 'Student', school_id: 1 }],
    staff: [{ id: 30, name: 'Synthetic Staff' }],
    statuses: { enrolment: ['Enrolled', 'Currently Studying', 'Completed', 'Deferred', 'Withdrawn'], communication: ['Pending', 'Completed', 'Awaiting Response'], intake: ['Open', 'Closing Soon', 'Closed', 'Fully Booked', 'Postponed'] },
    records: [
        { id: 1, kind: 'enrolment', lead_id: 20, program_id: 10, version: 1, data: { status: 'Currently Studying', intake: 'February 2027', starts_on: '2027-02-01', ends_on: '2028-02-01' } },
        { id: 2, kind: 'enrolment', lead_id: 20, program_id: 11, version: 1, data: { status: 'Enrolled', intake: 'July 2027', starts_on: '2027-07-01' } },
        { id: 3, kind: 'course', program_id: 11, version: 1, data: { status: 'Archived', currency: 'NZD' } },
        { id: 4, kind: 'communication', version: 2, data: { communicated_on: '2026-11-23', subject: 'Earlier meeting', status: 'Pending', staff_id: 30, follow_up_on: '2026-11-23', follow_up: 'Call school' }, files: [{ id: 8, name: 'meeting.pdf' }] },
        { id: 5, kind: 'communication', version: 1, data: { communicated_on: '2026-11-24', subject: 'Latest meeting', status: 'Completed' } },
        { id: 6, kind: 'intake', program_id: 10, version: 1, data: { name: 'February 2027', starts_on: '2027-02-01', closes_on: '2026-11-23', status: 'Open' } },
        { id: 7, kind: 'intake', program_id: 11, version: 1, data: { name: 'Old intake', starts_on: '2025-02-01', status: 'Open' } },
        { id: 8, kind: 'intake', program_id: 11, version: 1, data: { name: 'Archived course intake', starts_on: '2027-07-01', status: 'Open' } },
    ] };
const immutable = JSON.stringify(props);
const button = text => [...document.querySelectorAll('button')].find(n => n.textContent.trim() === text);
const click = text => act(async () => { assert.ok(button(text), text); button(text).click(); });
const change = (label, value) => act(async () => {
    const node = document.querySelector(`[aria-label="${label}"]`); assert.ok(node, label);
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value);
    node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
});
const count = label => [...document.querySelectorAll('main > div:first-of-type > div')].find(n => n.textContent.endsWith(label))?.firstChild.textContent;
(async () => {
    await act(async () => root.render(React.createElement(Page, props)));
    assert.equal(count('Enrolled students'), '1', 'Two programmes count one student');
    assert.equal(count('Currently studying'), '1'); assert.equal(count('Courses offered'), '1'); assert.equal(count('Upcoming intakes'), '1');
    assert.match(document.body.textContent, /overdue/); assert.match(document.body.textContent, /deadline passed/);
    assert.ok(document.querySelector('a[href="/portal/education/leads/20"]'));
    assert.ok(document.querySelector('a[href="/portal/education/leads/21"]'), 'Unconfirmed association remains visible without inflating totals');
    await change('Filter programme', '11');
    assert.equal(document.querySelectorAll('section[aria-label="Student Enrolments"] article').length, 1);
    await change('Filter intake', 'February');
    assert.equal(document.querySelectorAll('section[aria-label="Student Enrolments"] article').length, 0);
    await click('Communications');
    const articles = [...document.querySelectorAll('section[aria-label="Communications"] article')];
    assert.match(articles[0].textContent, /Latest meeting/);
    assert.equal(document.querySelector('a[href*="management-records"]')?.getAttribute('href'), '/portal/education/schools/1/management-records/4/files/8');
    await click('Courses & Fees'); assert.match(document.body.textContent, /12345/);
    assert.ok(document.querySelector('a[href="/portal/education/programs"]'));
    await click('Intakes'); await change('Filter intake', 'February');
    assert.equal(document.querySelectorAll('section[aria-label="Intakes"] article').length, 1);
    await click('Edit record'); await change('Intake name / period', 'Updated intake');
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(posts.length, 1); assert.equal(posts[0][0], '/portal/education/schools/1/management/intake/6');
    assert.equal(posts[0][1].version, 1); assert.equal(posts[0][1].name, 'Updated intake');
    await act(async () => { posts[0][2].onError({ record: 'This record changed. Refresh before editing again.' }); posts[0][2].onFinish(); });
    assert.match(document.querySelector('[role="alert"]').textContent, /record changed/); assert.ok(document.querySelector('form'), 'Error retains unsaved input');
    await click('Cancel'); assert.equal(document.querySelector('form'), null);
    assert.equal(JSON.stringify(props), immutable, 'Rendering/filtering/editing does not mutate server records');
    assert.equal(posts.length, 1, 'Only explicit Save writes');
    await act(async () => root.unmount()); dom.window.close();
    console.log('School workspace actual React checks passed: distinct counts, candidates, dates, chronology, filters, links, private files, versioned saves and errors.');
})().catch(error => { console.error(error); process.exitCode = 1; });

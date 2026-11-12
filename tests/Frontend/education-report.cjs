const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/reports' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'FormData']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
window.matchMedia = () => ({ matches: false });
global.ResizeObserver = class { observe() {} disconnect() {} };
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const requests = [], reloads = [], posts = [], listeners = {};
// Only transport and document-title effects are replaced; render and interact with the real report.
const inertia = { Head: () => null, useForm: initial => {
    const [data, update] = React.useState(initial);
    return { data, setData: (field, value) => update(current => ({ ...current, [field]: value })),
        post: (url, options) => posts.push({ url, data, options }), clearErrors: () => {}, errors: {} };
}, router: {
    get: (url, data, options) => requests.push({ url, data, options }),
    reload: options => reloads.push(options),
    post: (url, data, options) => posts.push({ url, data, options }),
    on: (name, callback) => { listeners[name] = callback; return () => delete listeners[name]; },
} };
const output = buildSync({ entryPoints: ['resources/js/pages/portal/education/Reports.jsx'], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('education-report.cjs'), module);
loaded.filename = path.resolve('education-report.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
loaded._compile(output, loaded.filename);
const Report = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const click = async node => { assert.ok(node, 'Control exists'); await act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true }))); };
const button = text => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === text);
const render = async props => act(async () => root.render(React.createElement(Report, props)));
const change = async (node, value) => act(async () => { Object.getOwnPropertyDescriptor(node.tagName === 'TEXTAREA' ? dom.window.HTMLTextAreaElement.prototype : dom.window.HTMLInputElement.prototype, 'value').set.call(node, value); node.dispatchEvent(new Event('input', { bubbles: true })); node.dispatchEvent(new Event('change', { bubbles: true })); });
const metric = (key, label, count, previous) => ({ key, label, count, previous_count: previous, points: [{ from: '2026-11-01', to: '2026-11-01', previous_from: '2026-10-30', previous_to: '2026-10-30', count, previous_count: previous }, { from: '2026-11-02', to: '2026-11-02', previous_from: '2026-10-31', previous_to: '2026-10-31', count: 0, previous_count: 0 }] });
const department = (name, count) => ({ total: count, register_total: 20, activity_total: count, event_count: 9, scope: 'activity', stages: [{ stage: `${name} enrolled`, count, moved: count, clients: [{ id: name, name: `${name} student`, ref: 'TEST', school: null, date: '1999-01-01', moved: true }] }], insights: { metrics: [metric(`${name} enrolled`, 'Enrolled', count, 1), metric(`${name} offer`, 'Offer issued', 2, 0)], largest: { stage: `${name} enrolled`, count, share: 60 }, active_students: count, register_total: 20, missing_stage: 3, missing_history: 4 } });
const props = { range: { preset: 'this_month', from: '2026-11-01', to: '2026-11-02', days: 2, label: 'This month', timezone: 'Pacific/Auckland', all: false, compare: true, bucket_days: 1, bucket_label: 'Daily', previous: { from: '2026-10-30', to: '2026-10-31' } }, departments: { education: department('Education', 6), english: department('English', 7) }, pipeline: [{ stage: 'Qualified', count: 1, moved: 1, num: 1 }], register: { Qualified: [{ id: 9, name: 'Recorded lead', date: null }] }, totalRegister: 1, movements: 1, programs: { total: null, published: null, draft: null, history_available: false, scope: 'unavailable' }, pipeline_history: { source: 'audit', notice: 'Only recorded lead status audit changes are included.' }, conclusion: { auto: 'Recorded activity.', note: '', note_key: '2026-11', stats: { movements: 1, qualified: 1, proposals: 0, register: 1 }, labels: { movements: 'Recorded status events' } } };
(async () => {
    // Catches legacy-only presets, absent server insights and accidentally showing current catalogue totals for dated filters.
    await render(props);
    const contactProps = {
        ...props,
        departments: { ...props.departments, education: { ...props.departments.education, stages: [
            { stage: 'New Lead', count: 1, clients: [] },
            ...props.departments.education.stages,
            { stage: 'No stage set', count: 3, clients: [] },
        ] } },
        register: { ...props.register,
            'Contact Attempted': [{ id: 12, name: 'Attempted lead', date: null }],
            'Contacted for Booking': [{ id: 13, name: 'Reached lead', date: null }],
        },
    };
    await render(contactProps);
    const statusCards = [...document.querySelectorAll('button[aria-label$="with recorded entries"]')];
    assert.deepEqual(statusCards.map(node => node.querySelector('p').textContent), ['New Lead', 'Education enrolled']);
    assert.ok(statusCards.every(node => !node.textContent.includes('Period')));
    assert.doesNotMatch(document.body.textContent, /Attempted lead|Reached lead/, 'Shared contact totals must not appear as student accomplishments');
    await render(props);
    assert.match(document.querySelector('h1').nextElementSibling.textContent, /1 Nov – 2 Nov/, 'NZ calendar date labels must not shift date-only boundaries into tomorrow');
    assert.deepEqual([...document.querySelectorAll('[aria-label="Report period"] button')].map(n => n.textContent), ['Today', 'This week', 'Last week', 'This month', 'This year', 'Custom', 'All']);
    assert.ok(document.querySelector('svg[aria-label*="6 distinct students"]'), 'Uses the server metric count rather than reconstructing client dates');
    assert.match(document.querySelector('table').textContent, /6/);
    assert.match(document.body.textContent, /4 records lack.*3 have no current stage/s);
    assert.match(document.body.textContent, /snapshot coverage counts, independent of the selected dates/i);
    assert.match(document.body.textContent, /Only recorded lead status audit changes/);
    assert.equal([...document.querySelectorAll('[aria-label="Program totals"] .tabular-nums')].map(n => n.textContent).join(','), '—,—,—');
    await click(document.querySelector('[aria-label="Education enrolled: 6 students with recorded entries"]'));
    assert.match(document.body.textContent, /Education student/);
    await click(button('This year')); assert.equal(requests.at(-1).url, '/portal/education/reports'); assert.deepEqual(requests.at(-1).data, { preset: 'this_year' }); assert.equal(requests.at(-1).options.preserveState, true);
    // Catches using stale React state instead of entered date fields, silent validation, and lost custom drafts on polling.
    await click(button('Custom'));
    const from = document.querySelector('input[name=from]'), to = document.querySelector('input[name=to]');
    from.value = '2026-10-03'; to.value = '2026-10-11';
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.deepEqual(requests.at(-1).data, { preset: 'custom', from: '2026-10-03', to: '2026-10-11' });
    await act(async () => requests.at(-1).options.onError({ from: 'Use a valid date.' }));
    assert.match(document.querySelector('[role=alert]').textContent, /Use a valid date/);
    assert.equal(from.value, '2026-10-03');
    const count = requests.length; from.value = '2026-10-12'; to.value = '2026-10-11';
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(requests.length, count); assert.match(document.querySelector('[role=alert]').textContent, /on or before/i);
    // Catches remounting departments/metrics/commentary or hiding reload failures.
    await click([...document.querySelectorAll('[aria-label="Department"] button')].find(n => n.textContent.startsWith('English')));
    const select = document.querySelector('select[aria-label="Insight metric"]');
    await act(async () => { select.value = 'English offer'; select.dispatchEvent(new Event('change', { bubbles: true })); });
    await click(button('Add note')); await change(document.querySelector('textarea'), 'Keep this commentary draft');
    await act(async () => window.dispatchEvent(new Event('focus')));
    assert.ok(reloads.at(-1).only.includes('range')); assert.ok(reloads.at(-1).only.includes('error')); assert.ok(reloads.at(-1).only.includes('pipeline_history')); assert.ok(reloads.at(-1).only.includes('personalReport'));
    await act(async () => reloads.at(-1).onError({ report: 'Refresh failed. Try again.' }));
    assert.match(document.querySelector('[role=alert]').textContent, /Refresh failed/);
    await render({ ...props, generated_at: '2026-11-02T03:00:00Z' });
    assert.equal(document.querySelector('select[aria-label="Insight metric"]').value, 'English offer');
    assert.equal(document.querySelector('textarea').value, 'Keep this commentary draft');
    assert.equal(document.querySelector('input[name=from]').value, '2026-10-12');
    await act(async () => reloads.at(-1).onSuccess());
    // Catches backend-error partial props unmounting editors or mixing a newly requested range with retained counts.
    await click(document.querySelector('[aria-label="English enrolled: 7 students with recorded entries"]'));
    await click(document.querySelector('[aria-label="Qualified: 1 leads with recorded changes"]'));
    const commentaryNode = document.querySelector('textarea');
    const metricNode = document.querySelector('select[aria-label="Insight metric"]');
    const successfulProps = { ...props, error: null, generated_at: '2026-11-02T03:00:00Z' };
    const failedProps = { ...successfulProps, ...{ error: 'Backend refresh failed.', range: { ...props.range, preset: 'this_year', from: '2026-01-01', days: 306, label: 'This year' } } };
    await render(failedProps);
    assert.equal(document.querySelector('textarea'), commentaryNode, 'A failed backend refresh must keep the existing commentary editor mounted');
    assert.equal(document.querySelector('select[aria-label="Insight metric"]'), metricNode);
    assert.equal(commentaryNode.value, 'Keep this commentary draft');
    assert.equal(metricNode.value, 'English offer');
    assert.match(document.querySelector('[role=alert]').textContent, /Backend refresh failed.*previously loaded.*stale/is);
    assert.match(document.querySelector('h1').nextElementSibling.textContent, /1 Nov – 2 Nov/);
    assert.equal(button('This month').getAttribute('aria-pressed'), 'true', 'Failed range changes must not relabel stale figures');
    assert.match(document.body.textContent, /English student/);
    assert.match(document.body.textContent, /Recorded lead/);
    // Inertia merges recovery props with the failed page; an explicit null error must restore fresh data without a remount.
    const recoveredProps = { ...failedProps, ...{ error: null, range: props.range, departments: { ...props.departments, english: department('English', 8) } } };
    await render(recoveredProps);
    assert.equal(document.querySelector('textarea'), commentaryNode);
    assert.equal(document.querySelector('select[aria-label="Insight metric"]'), metricNode);
    assert.equal(metricNode.value, 'English offer');
    assert.equal(commentaryNode.value, 'Keep this commentary draft');
    assert.ok(document.querySelector('[aria-label="English enrolled: 8 students with recorded entries"]'));
    assert.equal(document.querySelector('[role=alert]'), null, 'Successful recovery clears the stale backend error');
    await click(button('Save')); assert.equal(posts.at(-1).data.note, 'Keep this commentary draft'); assert.equal(posts.at(-1).data.note_key, '2026-11');
    // Catches arbitrary previous comparisons under All and exclusion of undated current records.
    const allDept = department('English', 7); allDept.scope = 'current'; allDept.stages[0].clients[0].date = null;
    await render({ ...props, range: { ...props.range, preset: 'all', all: true, compare: false, label: 'All' }, departments: { ...props.departments, english: allDept }, programs: { total: 12, published: 10, draft: 2 } });
    assert.doesNotMatch(document.querySelector('table').textContent, /Previous/);
    assert.match(document.body.textContent, /including records with missing dates or stages/);
    assert.equal([...document.querySelectorAll('[aria-label="Program totals"] .tabular-nums')].map(n => n.textContent).join(','), '12,10,2');
    const currentStage = document.querySelector('[aria-label="English enrolled: 7 current students"]');
    if (currentStage.getAttribute('aria-pressed') === 'true') await click(currentStage);
    await click(currentStage); assert.match(document.body.textContent, /English student/);
    await render({ ...props, error: 'Report unavailable. Please retry.' }); assert.match(document.querySelector('[role=alert]').textContent, /Report unavailable/);
    await act(async () => root.render(React.createElement(Report, { key: 'initial-error', error: 'Initial report unavailable.' })));
    assert.match(document.querySelector('[role=alert]').textContent, /Initial report unavailable/);
    assert.equal(document.querySelector('[aria-label="Department"]'), null, 'An initial failure has no trusted report to show');
    assert.equal(document.querySelector('[aria-label="Program totals"]'), null, 'An initial failure must not invent zero programme totals');
    await render({ ...props, range: { ...props.range, preset: 'this_week', label: 'This week' }, weeklyReport: { from: '2026-11-02', to: '2026-11-04', note: '' } });
    const notes = [...document.querySelectorAll('section')].find(node => node.querySelector('h2')?.textContent === 'Weekly notes');
    await click(notes.querySelector('button'));
    await change(notes.querySelector('textarea'), 'Follow up with Education');
    await act(async () => window.dispatchEvent(new Event('focus')));
    assert.ok(reloads.at(-1).only.includes('weeklyReport'));
    await render({ ...props, range: { ...props.range, preset: 'this_week', label: 'This week' }, weeklyReport: { from: '2026-11-02', to: '2026-11-05', note: '' } });
    assert.equal(notes.querySelector('textarea').value, 'Follow up with Education');
    await act(async () => notes.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.deepEqual(posts.at(-1).data, { week_start: '2026-11-02', note: 'Follow up with Education' });
    await click(button('Last week'));
    assert.deepEqual(requests.at(-1).data, { preset: 'last_week' });
    await render({ ...props, range: { ...props.range, preset: 'last_week', label: 'Last week' }, weeklyReport: { from: '2026-10-26', to: '2026-11-01', note: 'Previous weekly report' } });
    assert.equal(button('Last week').getAttribute('aria-pressed'), 'true');
    assert.match(document.body.textContent, /Previous weekly report/);
    assert.equal(document.querySelector('textarea[placeholder*="Record updates"]'), null);
    await act(async () => root.unmount());
    console.log('Education report: server insights, matching drilldowns, presets, custom native fields/validation, live refresh errors and preserved department/metric/commentary, All and program scope verified.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });

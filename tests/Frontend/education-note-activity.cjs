const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/reports' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const visits = [];
const inertia = { Link: ({ href, children, ...props }) => React.createElement('a', { href, ...props }, children),
    router: { get: (...args) => visits.push(args) } };
const load = file => {
    const output = buildSync({ entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node',
        jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
    const loaded = new Module(path.resolve(file + '.cjs'), module);
    loaded.filename = path.resolve(file + '.cjs'); loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
    loaded._compile(output, loaded.filename);
    return loaded.exports;
};
const control = load('resources/js/components/education/EducationNoteReportControl.jsx');
const Report = load('resources/js/components/education/EducationNoteActivityReport.jsx').default;
const root = createRoot(document.getElementById('root'));
const render = element => act(async () => root.render(element));
const click = element => act(async () => element.dispatchEvent(new MouseEvent('click', { bubbles: true })));
(async () => {
    let selected;
    await render(React.createElement(control.default, { value: 'update', onChange: value => { selected = value; } }));
    const select = document.querySelector('select');
    assert.equal(select.value, 'update');
    await act(async () => { select.value = 'follow_up'; select.dispatchEvent(new Event('change', { bubbles: true })); });
    assert.equal(selected, 'follow_up');
    assert.deepEqual(control.educationNoteSubmission(true, '/admin', selected), {
        base: '/portal/education', fields: { education_report_activity: 'follow_up' },
    });
    for (const base of ['/admin', '/portal/immigration', '/portal/agent', '/portal/sub-agent']) {
        assert.deepEqual(control.educationNoteSubmission(false, base, selected), { base, fields: {} });
    }
    await render(React.createElement(control.default, { value: 'update', onChange() {}, disabled: true }));
    assert.ok(document.querySelector('select').disabled);
    const data = { total: 10, clients: 1, counts: { follow_up: { label: 'Follow-ups', events: 10, clients: 1 } },
        entries: { data: Array.from({ length: 10 }, (_, i) => ({ id: i + 1, client: '<Synthetic client>',
            url: '/portal/education/leads/1?tab=activity', author: 'Synthetic Emma', activity: 'follow_up',
            posted_at: '2026-11-12T00:00:00Z', note_available: i !== 0, is_reply: i === 1 })),
            current_page: 1, last_page: 2, prev_page_url: null, next_page_url: '/portal/education/reports?preset=this_week&note_page=2' } };
    await render(React.createElement(Report, { data }));
    assert.equal(document.querySelectorAll('tbody tr').length, 10);
    assert.match(document.body.textContent, /10 posted notes · 1 distinct clients/);
    assert.match(document.body.textContent, /Original note removed/);
    assert.match(document.body.textContent, /Reply/);
    assert.equal(document.querySelectorAll('synthetic').length, 0, 'Client names remain escaped text');
    assert.equal(document.querySelector('tbody a').getAttribute('href'), '/portal/education/leads/1?tab=activity');
    const next = [...document.querySelectorAll('button')].find(node => node.textContent === 'Next note activities');
    await click(next);
    assert.equal(visits[0][0], data.entries.next_page_url);
    assert.equal(visits[0][2].preserveScroll, true);
    await render(React.createElement(Report, { data: null }));
    assert.equal(document.querySelector('section'), null);
    await act(async () => root.unmount());
    console.log('Education note reporting: explicit Follow-up, portal-safe submission, repeated rows, author/client display, pagination and escaping passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

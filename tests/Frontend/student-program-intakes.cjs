const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/students' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'KeyboardEvent', 'localStorage']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const posts = [];
const output = buildSync({ stdin: { contents: `export { default as Students } from './resources/js/pages/portal/education/Students.jsx'; export { default as Modal } from './resources/js/pages/portal/education/AddEditStudentModal.jsx'; export { default as Dates } from './resources/js/components/education/ProgramIntakeDates.jsx';`,
    resolveDir: process.cwd(), loader: 'jsx' }, bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    alias: { '@': path.resolve('resources/js') }, external: ['react', 'react-dom', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('student-program-intakes.cjs'), module);
loaded.filename = path.resolve('student-program-intakes.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? { Head: () => null, Link: 'a', router: { post: (url, data, options) => posts.push({ url, data, options }) } } : module.require(name);
loaded._compile(output, loaded.filename);
const { Students, Modal, Dates } = loaded.exports;
const root = createRoot(document.getElementById('root'));
const A = 'English for Academic Purposes', B = 'Master of Education', C = 'Third program';
const student = { id: 22, first_name: 'Synthetic', last_name: 'Student', email: 'synthetic@example.test', phone: '+64000000000',
    education_stage: 'School Enrolment', program: A + ' · ' + B, program_schools: ['School A', 'School B'], intake: '2027-02-17', actual_course_started_on: '2026-09-03' };
const render = (value = student, key = 'edit', open = true) => act(async () => root.render(React.createElement(React.Fragment, null,
    React.createElement(Modal, { key, student: value, open, onClose() {}, schoolOptions: [{ id: 1, name: 'School A' }, { id: 2, name: 'School B' }], programOptions: [] }),
    value && React.createElement(Dates, { student: value }))));
const control = label => document.querySelector('[aria-label="' + label + '"]');
const click = node => act(async () => { assert.ok(node); node.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
const change = (node, value) => act(async () => {
    assert.ok(node); Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value);
    node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
});
const submit = () => act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
const finish = () => act(async () => posts.at(-1).options.onFinish());
const addProgram = async title => {
    const input = document.querySelector('input[placeholder="Add another program…"]') || document.querySelector('input[placeholder="Search or type a program…"]');
    await change(input, title);
    await act(async () => input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })));
};
(async () => {
    await render();
    assert.equal(control('Planned intake for ' + A).value, '2027-02-17');
    assert.equal(control('Planned intake for ' + B).value, '', 'Do not guess a second date from the old shared intake');
    assert.equal(control('Actual course start date').value, '2026-09-03');
    await change(control('Planned intake for ' + B), '2027-07-12');
    await submit();
    const saved = posts.at(-1).data;
    assert.equal(saved.intake, '2027-02-17');
    assert.deepEqual(saved.program_intakes, [{ program: A, intake: '2027-02-17' }, { program: B, intake: '2027-07-12' }]);
    assert.deepEqual(saved.program_schools, ['School A', 'School B']);
    assert.equal(saved.actual_course_started_on, '2026-09-03');
    await act(async () => { posts.at(-1).options.onError({ 'program_intakes.1.intake': 'Check this date' }); posts.at(-1).options.onFinish(); });
    assert.equal(control('Planned intake for ' + B).value, '2027-07-12');
    assert.match(document.body.textContent, /Check this date/);
    const reopened = { ...student, program_intakes: saved.program_intakes };
    await render(reopened, 'reopened');
    assert.equal(control('Planned intake for ' + B).value, '2027-07-12');
    assert.match(control('Program intake dates').textContent, /2027-02-17/);
    assert.match(control('Program intake dates').textContent, /2027-07-12/);
    const programChip = [...document.querySelectorAll('span')].find(node => node.textContent === A && node.parentElement.querySelector('button'));
    await click(programChip.parentElement.querySelector('button'));
    assert.equal(control('Planned intake for ' + A), null);
    assert.equal(control('Planned intake for ' + B).value, '2027-07-12');
    await addProgram(C);
    assert.equal(control('Planned intake for ' + C).value, '');
    await submit();
    assert.equal(posts.at(-1).data.intake, '2027-07-12');
    assert.deepEqual(posts.at(-1).data.program_intakes, [{ program: B, intake: '2027-07-12' }, { program: C, intake: null }]);
    await finish();
    await change(control('Planned intake for ' + B), '');
    await submit();
    assert.equal(posts.at(-1).data.intake, '');
    assert.equal(posts.at(-1).data.program_intakes[0].intake, null);
    await finish();
    await render({ ...student, intake: 'November 2027' }, 'legacy');
    assert.match(document.body.textContent, /Saved intake: November 2027/);
    await submit();
    assert.equal(posts.at(-1).data.program_intakes[0].intake, 'November 2027');
    await finish();
    await click(control('Clear saved intake for ' + A));
    await submit();
    assert.equal(posts.at(-1).data.program_intakes[0].intake, null);
    await finish();
    localStorage.clear();
    await render(null, 'new');
    await addProgram(C);
    await change(control('Planned intake for ' + C), '2028-01-15');
    assert.equal(JSON.parse(localStorage.getItem('education.newStudent.draft')).program_intakes[0].intake, '2028-01-15');
    await render(null, 'restore');
    assert.equal(control('Planned intake for ' + C).value, '2028-01-15');
    await submit();
    assert.equal(posts.at(-1).url, '/portal/education/students');
    assert.deepEqual(posts.at(-1).data.program_intakes, [{ program: C, intake: '2028-01-15' }]);
    const searchedStudent = { ...reopened, name: 'Synthetic Student', english_stage: 'Enrolled', education_stage: '' };
    await act(async () => root.render(React.createElement(Students, { students: [searchedStudent], readOnly: true })));
    for (const needle of ['2027-07-12', '12 Jul 2027']) {
        await change(document.querySelector('input[placeholder^="Search name, school, intake"]'), needle);
        assert.match(document.querySelector('tbody').textContent, /Synthetic Student/);
        assert.match(document.body.textContent, /Pipeline · 1 student/);
    }
    await act(async () => root.unmount());
    console.log('Student per-program intakes actual-modal tests passed: independent dates, legacy preservation/clear, retry/reopen, program identity after removal, new drafts, read display and actual-start isolation');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });

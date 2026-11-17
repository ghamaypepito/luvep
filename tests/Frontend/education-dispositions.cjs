const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/students' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'localStorage']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const posts = [];
const output = buildSync({ entryPoints: ['resources/js/pages/portal/education/AddEditStudentModal.jsx'], bundle: true,
    write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('education-dispositions-test.cjs'), module);
loaded.filename = path.resolve('education-dispositions-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? { router: { post: (...args) => posts.push(args) } }
    : name === 'lucide-react' ? new Proxy({}, { get: () => () => null }) : module.require(name);
loaded._compile(output, loaded.filename);
const Modal = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const change = async (element, value) => act(async () => { element.value = value; element.dispatchEvent(new Event('change', { bubbles: true })); });
const stageSelect = () => [...document.querySelectorAll('select')].find(select => [...select.options].some(o => o.value === 'Qualified but No Funds'));
const student = stage => ({ id: 1, name: 'Synthetic Student', email: 'student@example.test', phone: '+639000000000',
    education_stage: stage, immigration_stage: 'Visa Lodged', english_stage: 'PTE Review',
    immigration_assignee: 'Dev', english_assignee: 'Paula', program_text: '', school: '' });
(async () => {
    for (const stage of ['Qualified but No Funds', 'Archive']) {
        await act(async () => root.render(React.createElement(Modal, { key: stage, student: student(stage), open: true, onClose() {} })));
        const select = stageSelect();
        assert.ok(select, 'The Education dropdown includes the new options');
        assert.equal(select.value, stage, 'Editing keeps the Education disposition selected despite parallel work');
        await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        const payload = posts.at(-1)[1];
        assert.equal(payload.education_stage, stage);
        for (const field of ['immigration_stage', 'english_stage', 'immigration_assignee', 'english_assignee']) {
            assert.equal(field in payload, false, `${field} must not be cleared`);
        }
    }
    await act(async () => root.render(React.createElement(Modal, { key: 'resume', student: student('Archive'), open: true, onClose() {} })));
    await change(stageSelect(), 'Proposal Sent');
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(posts.at(-1)[1].education_stage, 'Proposal Sent');
    assert.equal('immigration_stage' in posts.at(-1)[1], false, 'Resuming Education preserves Immigration');
    await act(async () => root.render(React.createElement(Modal, { key: 'enter', student: student('Proposal Sent'), open: true, onClose() {} })));
    const department = [...document.querySelectorAll('select')].find(select => [...select.options].some(o => o.value === 'education'));
    await change(department, 'education');
    await change(stageSelect(), 'Qualified but No Funds');
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(posts.at(-1)[1].education_stage, 'Qualified but No Funds');
    assert.equal('immigration_stage' in posts.at(-1)[1], false, 'Entering a disposition preserves Immigration too');
    await act(async () => root.unmount());
    console.log('Education dispositions: both dropdown values, round-trip editing, parallel-track preservation and resuming passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/students' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'localStorage']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const posts = [];
const existing = { id: 32, name: 'Existing Person', first_name: 'Existing', last_name: 'Person',
    email: 'existing@example.test', phone: '+64000000000', agent_id: 7, referral: 'Previous Referee' };
global.fetch = async () => ({ ok: true, json: async () => ({ leads: [existing] }) });
const output = buildSync({ stdin: {
    contents: `export { default } from './resources/js/pages/portal/education/AddEditStudentModal.jsx'; export { studentReferralLabel } from './resources/js/lib/studentReferral.js';`,
    resolveDir: process.cwd(), loader: 'jsx',
}, bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('student-referral-name-test.cjs'), module);
loaded.filename = path.resolve('student-referral-name-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? { router: { post: (url, data, options) => posts.push({ url, data, options }) } } : module.require(name);
loaded._compile(output, loaded.filename);
const { default: Modal, studentReferralLabel } = loaded.exports;
const root = createRoot(document.getElementById('root'));
const student = { id: 22, name: 'Synthetic Student', email: 'referee@example.test', phone: '+64000000000',
    education_stage: 'Proposal Sent', agent_id: 7, referral: 'Original referee' };
const render = (student, key = 'edit', open = true) => act(async () => root.render(React.createElement(Modal, {
    key, student, open, onClose() {}, agentOptions: [{ id: 7, name: 'Linked Agent' }],
})));
const control = label => document.querySelector(`[aria-label="${label}"]`);
const click = node => act(async () => { assert.ok(node); node.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
const change = (node, value) => act(async () => {
    assert.ok(node);
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value);
    node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
});
const submit = () => act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
const finish = () => act(async () => posts.at(-1).options.onFinish());
(async () => {
    await render(student);
    assert.equal(control('Agent or referee name').value, 'Original referee');
    assert.equal(control('Agent or referee name').maxLength, 191);
    assert.equal(document.querySelector('datalist option').value, 'SIT');
    await click(control('Use SIT as agent or referee'));
    assert.equal(control('Linked recruiting agent').value, '7');
    await submit();
    assert.equal(posts.at(-1).url, '/portal/education/students/22/update');
    assert.equal(posts.at(-1).data.referral, 'SIT');
    assert.equal(posts.at(-1).data.agent_id, '7');
    await act(async () => { posts.at(-1).options.onError({ referral: 'Please check this name.' }); posts.at(-1).options.onFinish(); });
    assert.match(document.querySelector('[role=alert]').textContent, /check this name/);
    assert.equal(control('Agent or referee name').value, 'SIT');
    await change(control('Agent or referee name'), 'Synthetic Referee'); await submit();
    assert.equal(posts.at(-1).data.referral, 'Synthetic Referee');
    assert.equal(posts.at(-1).data.agent_id, '7'); await finish();
    await render(student, 'edit', false);
    await render({ ...student, referral: 'Synthetic Referee' });
    assert.equal(control('Agent or referee name').value, 'Synthetic Referee', 'Reopen uses the saved referral');
    await change(control('Agent or referee name'), ''); await submit();
    assert.equal(posts.at(-1).data.referral, ''); assert.equal(posts.at(-1).data.agent_id, '7'); await finish();

    localStorage.clear();
    await render(null, 'new');
    await click(control('Use SIT as agent or referee'));
    await submit();
    assert.equal(posts.at(-1).url, '/portal/education/students');
    assert.equal(posts.at(-1).data.referral, 'SIT'); assert.equal(posts.at(-1).data.agent_id, ''); await finish();
    assert.equal(JSON.parse(localStorage.getItem('education.newStudent.draft')).referral, 'SIT');
    await render(null, 'new-restore');
    assert.equal(control('Agent or referee name').value, 'SIT');

    await click([...document.querySelectorAll('button')].find(node => node.textContent.includes('Add from existing')));
    await change(document.querySelector('input[placeholder^="Search by name"]'), 'Existing');
    await act(async () => new Promise(resolve => setTimeout(resolve, 350)));
    await click([...document.querySelectorAll('button')].find(node => node.textContent.includes('Existing Person')));
    assert.equal(control('Agent or referee name').value, 'Previous Referee');
    assert.equal(control('Linked recruiting agent').value, '7');
    await click(control('Use SIT as agent or referee')); await submit();
    assert.equal(posts.at(-1).url, '/portal/education/students/link-existing');
    assert.equal(posts.at(-1).data.lead_id, 32); assert.equal(String(posts.at(-1).data.agent_id), '7');
    assert.equal(posts.at(-1).data.referral, 'SIT');

    assert.equal(studentReferralLabel({ agent_name: 'Linked Agent', referral: 'SIT' }), 'Linked Agent · Referee: SIT');
    assert.equal(studentReferralLabel({ referral: 'SIT' }), 'SIT');
    assert.equal(studentReferralLabel({ agent_name: 'SIT', referral: 'sit' }), 'SIT');
    assert.equal(studentReferralLabel({ agent_name: 'Linked Agent' }), 'Linked Agent');
    await act(async () => root.unmount());
    console.log('Student referral names: SIT/manual entry, agent preservation, errors, reopen, clear, new drafts, existing-person linking and combined labels passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });

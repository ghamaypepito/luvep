const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const empty = () => null;
function load(file, extra = '') {
    const compiled = buildSync({
        stdin: { contents: fs.readFileSync(file, 'utf8') + extra, resolveDir: path.dirname(path.resolve(file)), loader: 'jsx' },
        bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react-dom', '@inertiajs/react', 'sonner', 'lucide-react', '@/*'],
    }).outputFiles[0].text;
    const component = new Module(path.resolve('resident-evidence-test.cjs'), module);
    component.require = (name) => {
        if (name === '@inertiajs/react') return { Head: empty, Link: empty, router: {}, useForm: empty, usePage: () => ({ props: {} }) };
        if (name === 'sonner') return { toast: { success: empty, error: empty } };
        if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
        if (name.startsWith('@/')) return Object.assign(empty, { default: empty, AiAssessmentReviewModal: empty });
        return module.require(name);
    };
    component.filename = path.resolve('resident-evidence-test.cjs'); component.paths = module.paths;
    component._compile(compiled, component.filename);
    return component.exports;
}
const { IntakeViewModal } = load('resources/js/pages/portal/immigration/Assessments.jsx');
const { StepEnglishFamily } = load('resources/js/pages/visa/ResidentIntakePage.jsx', '\nexport { StepEnglishFamily };');
const field = { key: 'english_evidence', label: 'English Language Evidence', raw: 'IELTS', value: 'IELTS', provided: true, editable: true, options: ['IELTS', 'Qualification(s)', 'Not Required'] };
const data = { ai_review: {}, notes: [], sections: [{ title: 'English & Family', fields: [field] }] };
const button = (name) => [...document.querySelectorAll('button')].find((el) => el.textContent.trim() === name);
async function change(element, value) {
    await act(async () => {
        const proto = element.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : window.HTMLTextAreaElement.prototype;
        Object.getOwnPropertyDescriptor(proto, 'value').set.call(element, value);
        element.dispatchEvent(new Event(element.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    });
}
(async () => {
    let request, rejectSave = true;
    global.fetch = async (_url, options) => {
        request = JSON.parse(options.body);
        if (rejectSave) return { ok: false, json: async () => ({ errors: { 'fields.english_evidence_reason': ['Reason is required.'] } }) };
        return { ok: true, json: async () => ({ ...data, sections: [{ title: 'English & Family', fields: [{ ...field, raw: 'Not Required', value: 'Not Required' }] }], english_evidence_exception: { reason: 'Staff reviewed the evidence' }, english_evidence_history: [{ actor: 'Synthetic Manager', at: '2026-11-19T06:00:00Z', evidence: 'Not Required', reason: 'Staff reviewed the evidence' }] }) };
    };
    let root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(IntakeViewModal, { intake: { visa_type: 'resident', id: 1 }, data, loading: false, asPage: true, chromeless: true })));
    await act(async () => button('Edit section').click());
    await change(document.querySelector('select[aria-label="English Language Evidence"]'), 'Not Required');
    assert.equal(button('Save').disabled, true);
    assert.match(document.body.textContent, /does not approve an exemption or waive any document checklist/);
    await change(document.querySelector('textarea[maxlength="2000"]'), 'Staff reviewed the evidence');
    assert.equal(button('Save').disabled, false);
    await act(async () => button('Save').click());
    assert.equal(document.querySelector('[role="alert"]').textContent, 'Reason is required.');
    assert.equal(document.querySelector('textarea[maxlength="2000"]').value, 'Staff reviewed the evidence');
    rejectSave = false;
    await act(async () => button('Save').click());
    assert.deepEqual(request.fields, { english_evidence: 'Not Required', english_evidence_reason: 'Staff reviewed the evidence' });
    assert.equal(document.querySelector('select'), null);
    assert.match(document.body.textContent, /Synthetic Manager/);
    assert.match(document.body.textContent, /Reason: Staff reviewed the evidence/);
    await act(async () => root.unmount());
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(StepEnglishFamily, { data: { english_evidence: '', include_family: 'No', family_members: [] }, errors: {}, setData: empty })));
    assert.match(document.body.textContent, /Qualification\(s\)/);
    assert.equal(button('Not Required'), undefined);
    await act(async () => root.render(React.createElement(StepEnglishFamily, { data: { english_evidence: 'Not Required', include_family: 'No', family_members: [] }, errors: {}, setData: empty })));
    assert.match(document.body.textContent, /Not Required — recorded by staff/);
    assert.equal(button('IELTS'), undefined);
    assert.equal(button('Qualification(s)'), undefined);
    await act(async () => root.unmount());
    console.log('Resident English evidence: required reason, save/errors/history and public read-only controls passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });

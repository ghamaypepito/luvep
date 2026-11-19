const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const empty = () => null;
let clientSave;
function load(file, extra = '') {
    const compiled = buildSync({
        stdin: { contents: fs.readFileSync(file, 'utf8') + extra, resolveDir: path.dirname(path.resolve(file)), loader: 'jsx' },
        bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react-dom', '@inertiajs/react', 'sonner', 'lucide-react', '@/*'],
    }).outputFiles[0].text;
    const component = new Module(path.resolve('resident-evidence-test.cjs'), module);
    component.require = (name) => {
        if (name === '@inertiajs/react') return { Head: empty, Link: empty, router: { post: (_url, payload) => { clientSave = payload; } }, useForm: empty, usePage: () => ({ props: {} }) };
        if (name === 'sonner') return { toast: { success: empty, error: empty } };
        if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
        if (name.endsWith('/data/assessmentSections')) return { ASSESSMENT_SECTIONS: { resident: [{ title: 'English & Family', fields: [['english_evidence', 'English Language Evidence'], ['english_test_score', 'Score'], ['job_title', 'Job']] }] }, fieldKind: () => 'text' };
        if (name.startsWith('@/')) return Object.assign(empty, { default: empty, AiAssessmentReviewModal: empty, VifSupplementEditor: empty });
        return module.require(name);
    };
    component.filename = path.resolve('resident-evidence-test.cjs'); component.paths = module.paths;
    component._compile(compiled, component.filename);
    return component.exports;
}
const { IntakeViewModal } = load('resources/js/pages/portal/immigration/Assessments.jsx');
const { StepEnglishFamily } = load('resources/js/pages/visa/ResidentIntakePage.jsx', '\nexport { StepEnglishFamily };');
const ClientAssessment = load('resources/js/pages/portal/lead/VisaAssessment.jsx').default;
const field = { key: 'english_evidence', label: 'English Language Evidence', raw: 'IELTS', value: 'IELTS', provided: true, editable: true, options: ['IELTS', 'Qualification(s)', 'Not Required'] };
const data = { ai_review: {}, notes: [], sections: [{ title: 'English & Family', fields: [field] }] };
const button = (name) => [...document.querySelectorAll('button')].find((el) => el.textContent.trim() === name);
async function change(element, value) {
    await act(async () => {
        const proto = element.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : element.tagName === 'INPUT' ? window.HTMLInputElement.prototype : window.HTMLTextAreaElement.prototype;
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
    // Family-only edits must not send blank or stale English values from the opened section.
    for (const openedEvidence of ['', 'IELTS']) {
        const familyField = { key: 'include_family', label: 'Family Members to Include', raw: 'No', value: 'No', provided: true, editable: true };
        const opened = { ...data, sections: [{ title: 'English & Family', fields: [{ ...field, raw: openedEvidence, value: openedEvidence || '—', provided: !!openedEvidence }, familyField] }] };
        let serverEvidence = openedEvidence;
        let postedFields;
        global.fetch = async (_url, options) => {
            postedFields = JSON.parse(options.body).fields;
            if (Object.hasOwn(postedFields, 'english_evidence')) serverEvidence = postedFields.english_evidence;
            return { ok: true, json: async () => ({ ...opened, sections: [{ title: 'English & Family', fields: [{ ...field, raw: serverEvidence, value: serverEvidence || '—' }, { ...familyField, raw: 'Yes', value: 'Yes' }] }] }) };
        };
        root = createRoot(document.getElementById('root'));
        await act(async () => root.render(React.createElement(IntakeViewModal, { intake: { visa_type: 'resident', id: 1 }, data: opened, loading: false, asPage: true, chromeless: true })));
        await act(async () => button('Edit section').click());
        if (openedEvidence === 'IELTS') serverEvidence = 'Not Required';
        await change(document.querySelector('input[aria-label="Family Members to Include"]'), 'Yes');
        await act(async () => button('Save').click());
        assert.deepEqual(postedFields, { include_family: 'Yes' });
        assert.equal(serverEvidence, openedEvidence === 'IELTS' ? 'Not Required' : '');
        assert.equal(document.querySelector('input[aria-label="Family Members to Include"]'), null);
        await act(async () => root.unmount());
    }
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(StepEnglishFamily, { data: { english_evidence: '', include_family: 'No', family_members: [] }, errors: {}, setData: empty })));
    assert.match(document.body.textContent, /Qualification\(s\)/);
    assert.equal(button('Not Required'), undefined);
    await act(async () => root.render(React.createElement(StepEnglishFamily, { data: { english_evidence: 'Not Required', include_family: 'No', family_members: [] }, errors: {}, setData: empty })));
    assert.match(document.body.textContent, /Not Required — recorded by staff/);
    assert.equal(button('IELTS'), undefined);
    assert.equal(button('Qualification(s)'), undefined);
    await act(async () => root.unmount());
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(ClientAssessment, { assessment: {
        type: 'resident', values: { english_evidence: 'Not Required', english_test_score: '', job_title: 'Chef' },
        read_only_fields: ['english_evidence', 'english_test_score', 'english_test_date'], save_url: '/portal/lead/visa-assessment',
    } })));
    assert.equal(document.querySelectorAll('input').length, 1);
    assert.equal(document.querySelector('input').value, 'Chef');
    assert.match(document.body.textContent, /Not Required · Recorded by staff/);
    await act(async () => button('Save changes').click());
    assert.deepEqual(clientSave, { field_values: { job_title: 'Chef' } });
    await act(async () => root.unmount());
    console.log('Resident English evidence: required reason, save/errors/history and public read-only controls passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/sales/proposals-agreements' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'Blob']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
URL.createObjectURL = () => 'blob:synthetic-pdf'; URL.revokeObjectURL = () => {};
dom.window.HTMLAnchorElement.prototype.click = function () {};
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const requests = [], downloads = [];
global.fetch = async (url, options) => { downloads.push({ url, options }); return { ok: true, headers: { get: key => key === 'Content-Type' ? 'application/pdf' : null }, blob: async () => new Blob(['synthetic-pdf']) }; };
const inertia = { Head: () => null, Link: ({ children, ...props }) => React.createElement('a', props, children), router: { post: (...args) => requests.push(args), get() {} } };
// Expose the actual internal modal for interaction testing without recreating any of its behavior.
const componentPath = path.resolve('resources/js/pages/portal/sales/ProposalsAgreements.jsx');
const compiled = buildSync({ stdin: { contents: fs.readFileSync(componentPath, 'utf8') + '\nexport { NewDocumentModal };', resolveDir: path.dirname(componentPath), loader: 'jsx' }, bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, external: ['react', 'react-dom', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('agreement-description-test.cjs'), module); loaded.filename = path.resolve('agreement-description-test.cjs'); loaded.paths = module.paths; loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(compiled, loaded.filename);
const Modal = loaded.exports.NewDocumentModal;
const root = createRoot(document.getElementById('root'));
const picker = [{ id: 42, name: 'Synthetic Agreement Client', email: 'synthetic@example.test', lead_id: 'SYNTHETIC-42' }];
let current = { open: true, onClose: () => { closed++; }, picker, programs: [], prefill: { leadId: 42, type: 'consultancy_std_single_100' } }, closed = 0;
const render = async props => { current = { ...current, ...props }; await act(async () => root.render(React.createElement(Modal, current))); };
const button = (name, scope = document) => [...scope.querySelectorAll('button')].find(node => node.textContent.trim() === name);
const click = async node => { assert.ok(node, 'Expected control exists'); await act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true }))); };
const input = async (node, value) => { assert.ok(node, 'Expected field exists'); await act(async () => { const prototype = node.tagName === 'SELECT' ? dom.window.HTMLSelectElement.prototype : node.tagName === 'TEXTAREA' ? dom.window.HTMLTextAreaElement.prototype : dom.window.HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(prototype, 'value').set.call(node, value); node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); }); };
const description = () => document.querySelector('textarea[name="application_description"]');
const category = () => document.querySelectorAll('select')[0];
const type = () => document.querySelectorAll('select')[1];
const preview = () => new URL(document.querySelector('iframe[title="Document preview"]').getAttribute('src'), window.location.origin);
const settle = async () => { const iframe = document.querySelector('iframe[title="Document preview"]'); if (iframe) await act(async () => iframe.dispatchEvent(new Event('load'))); };
const confirmation = () => [...document.querySelectorAll('h3')].find(node => node.textContent === 'Confirm before submitting')?.parentElement.parentElement;
const confirm = async () => { await settle(); await click(button('Submit for verification')); assert.ok(confirmation(), 'Existing amount confirmation stays in place'); await click(button('Confirm & submit', confirmation())); };
(async () => {
    // Catches absent default text, voucher HTML leaking into the plain-text editor, and custom text surviving a type change.
    await render({}); assert.ok(description(), 'Supported Philippines agreements provide a custom description editor');
    assert.equal(description().maxLength, 1000); assert.equal(description().value, 'School Enrollment and Documentation Fee');
    assert.equal(preview().searchParams.has('application_description'), false, 'Untouched defaults use trusted template formatting');
    const cases = [
        ['consultancy_std_single_150', 'School Enrollment and Documentation Fee, Immigration New Zealand (INZ) visa application fee'],
        ['consultancy_std_couple_150', 'School Enrollment and Documentation Fee, Immigration New Zealand (INZ) visa application fee'],
        ['consultancy_voucher_single_150', 'School Enrollment and Documentation Fee, inclusive of the Immigration New Zealand (INZ) visa application fee'],
        ['consultancy_voucher_couple_150', 'School Enrollment and Documentation Fee, inclusive of the Immigration New Zealand (INZ) visa application fee'],
        ['consultancy_english_single_100', 'School Enrollment and Documentation Fee'],
        ['consultancy_std_single_100', 'School Enrollment and Documentation Fee'],
    ];
    for (const [value, expected] of cases) { await input(description(), 'Prior document wording'); await input(type(), value); assert.equal(description().value, expected); assert.equal(preview().searchParams.has('application_description'), false); }
    const custom = '<school> & visa + partner?\nSecond line'; await input(description(), custom);
    assert.equal(preview().searchParams.get('application_description'), custom, 'URLSearchParams preserves reserved characters and newlines exactly');
    const fee = [...document.querySelectorAll('label')].find(node => node.textContent.includes('School Enrolment')).querySelector('input'); await input(fee, '125000');
    assert.equal(description().value, custom); assert.equal(preview().searchParams.get('school_enrolment_fee'), '125000');
    // Catches preview/download/send divergence while keeping download read-only and final submission deliberately confirmed.
    await settle(); await click(button('Download PDF')); assert.equal(downloads.length, 1); assert.equal(downloads[0].url, '/admin/leads/42/generate/consultancy_std_100/download-preview'); assert.equal(downloads[0].options.body.get('application_description'), custom); assert.equal(requests.length, 0);
    await settle(); await click(button('Submit for verification')); assert.equal(requests.length, 0); await click(button('Cancel', confirmation())); assert.equal(requests.length, 0);
    await confirm(); const [url, payload, options] = requests.at(-1); assert.equal(url, '/admin/leads/42/generate/consultancy_std_100'); assert.equal(payload.application_description, custom); assert.equal(payload.school_enrolment_fee, 125000); assert.equal(payload.applicant_mode, 'single'); assert.equal(payload.notify, true);
    await act(async () => options.onError({ application_description: 'The custom description must not exceed 1000 characters.' }));
    assert.equal(closed, 0, 'Validation failure must retain the open modal'); assert.equal(description().value, custom); assert.equal(fee.value, '125000'); assert.match(document.querySelector('[role="alert"]').textContent, /1000/);
    await click(button('Reset to default')); assert.equal(description().value, 'School Enrollment and Documentation Fee'); assert.equal(preview().searchParams.has('application_description'), false);
    await input(description(), '   '); assert.equal(preview().searchParams.has('application_description'), false); await confirm(); assert.equal(Object.hasOwn(requests.at(-1)[1], 'application_description'), false);
    await act(async () => requests.at(-1)[2].onError({ agreement: 'Synthetic retry.' }));
    await input(description(), 'Do not carry this to another document');
    await render({ open: false }); await render({ open: true }); assert.equal(description().value, 'School Enrollment and Documentation Fee', 'Reopening the same prefilled type starts a fresh document');
    // Catches leaking this field into unrelated agreement types and their payloads.
    for (const [section, selected] of [['offshore', 'consultancy_offshore'], ['offshore', 'consultancy_offshore_zero'], ['onshore', 'consultancy_onshore'], ['english', 'english_engagement'], ['english', 'english_offshore'], ['english', 'english_offshore_1000']]) {
        await input(category(), section); await input(type(), selected); assert.equal(description(), null); assert.equal(preview().searchParams.has('application_description'), false);
        if (selected === 'consultancy_onshore') { await click(button('Generate')); } else await confirm();
        assert.equal(Object.hasOwn(requests.at(-1)[1], 'application_description'), false); await act(async () => requests.at(-1)[2].onError({ agreement: 'Synthetic retry.' }));
    }
    await input(category(), 'philippines'); await input(type(), 'proposal'); assert.equal(description(), null);
    await act(async () => root.unmount()); dom.window.close(); console.log('Agreement custom description: scenario defaults, encoded live preview/PDF/send, confirmation, optional fallback, validation draft retention, reset and unsupported type isolation verified.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); dom.window.close(); process.exitCode = 1; });

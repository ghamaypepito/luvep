const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');
const React = require('react');
const { act } = React;
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const { createRoot } = require('react-dom/client');
global.fetch = async () => ({ ok: true, json: async () => ({}) });
let settings;
const empty = () => null;
const compiled = buildSync({ entryPoints: ['resources/js/pages/portal/immigration/Engagement.jsx'], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react-dom', '@/*', '@inertiajs/react', 'sonner', 'lucide-react'] }).outputFiles[0].text;
const component = new Module(path.resolve('consultation-mode-test.cjs'), module);
component.require = name => {
    if (name.endsWith('/useEngagementWorkflow')) return (_id, value, draft) => { settings = value; return { savedPackId: draft?.pack_id ?? null }; };
    if (name === '@inertiajs/react') return { Head: empty, Link: empty, router: {} };
    if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
    if (name === 'sonner') return { toast: {} };
    if (name.startsWith('@/')) return Object.assign(empty, { AvatarPhoto: empty, confirmDialog: empty });
    return module.require(name);
};
component.filename = path.resolve('consultation-mode-test.cjs'); component.paths = module.paths; component._compile(compiled, component.filename);
const Modal = component.exports.NewEngagementModal;
const fixture = { id: 999999, name: 'Synthetic Consultation', email: 'fixture@example.test' };
const props = { cases: [fixture], initialCase: fixture, documents: [], signers: [], onClose: empty };
const change = async (element, value) => act(async () => {
    const prototype = element.tagName === 'SELECT' ? dom.window.HTMLSelectElement.prototype : element.tagName === 'TEXTAREA' ? dom.window.HTMLTextAreaElement.prototype : dom.window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(prototype, 'value').set.call(element, value);
    element.dispatchEvent(new Event('change', { bubbles: true }));
    if (element.tagName !== 'SELECT') element.dispatchEvent(new Event('input', { bubbles: true }));
});
const mode = () => [...document.querySelectorAll('select')].find(el => [...el.options].some(option => option.value === 'face_to_face'));
const link = () => document.querySelector('input[type="url"]');
const address = () => document.querySelector('textarea');
const preview = () => new URL(document.querySelector('iframe').getAttribute('src'), 'http://localhost');
(async () => {
    let root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(Modal, props)));
    await change(document.getElementById('engagement-type'), 'initial_consultation');
    assert.equal(mode().value, 'online');
    assert.deepEqual([...mode().options].map(o => o.textContent), ['Online Consultation', 'Face-to-Face Consultation']);
    assert.equal(link().required, true); assert.equal(address(), null);
    await change(link(), 'https://meet.google.com/fixture-only');
    assert.equal(settings.meeting_link, 'https://meet.google.com/fixture-only');
    assert.equal(settings.consultation_mode, 'online'); assert.equal('consultation_address' in settings, false);
    assert.equal(preview().searchParams.get('meeting_link'), settings.meeting_link);
    await change(mode(), 'face_to_face');
    assert.equal(link(), null); assert.equal(address().required, true); assert.equal(address().maxLength, 2000);
    assert.equal('meeting_link' in settings, false);
    await change(address(), '20 Example Road, Auckland');
    assert.equal(settings.consultation_mode, 'face_to_face'); assert.equal(settings.consultation_address, '20 Example Road, Auckland');
    assert.equal(preview().searchParams.get('consultation_address'), settings.consultation_address);
    assert.equal(preview().searchParams.has('meeting_link'), false);
    await change(mode(), 'online');
    assert.equal(address(), null); assert.equal(link().required, true);
    assert.equal(link().value, 'https://meet.google.com/fixture-only'); assert.equal('consultation_address' in settings, false);
    await act(async () => root.unmount());
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(Modal, { ...props, draft: { engagement_type: 'initial_consultation', engagement_settings: { consultation_mode: 'face_to_face', consultation_address: 'Saved Fixture Address', consultation_date: '2026-12-02', consultation_time: '09:30', consultation_timezone: 'Pacific/Auckland' } } })));
    assert.equal(mode().value, 'face_to_face'); assert.equal(address().value, 'Saved Fixture Address'); assert.equal(link(), null);
    assert.equal(settings.consultation_address, 'Saved Fixture Address'); assert.equal(settings.consultation_date, '2026-12-02');
    await act(async () => root.unmount());
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(Modal, { ...props, draft: { engagement_type: 'initial_consultation', engagement_settings: { meeting_link: 'https://meet.google.com/legacy-fixture' } } })));
    assert.equal(mode().value, 'online'); assert.equal(link().value, 'https://meet.google.com/legacy-fixture'); assert.equal(address(), null);
    await act(async () => root.unmount());
    // A saved draft with no recipient must explain why email review is disabled.
    const savedDraft = { pack_id: 'fixture-pack', engagement_type: 'initial_consultation', engagement_settings: {} };
    const signer = { id: 1, name: 'Fixture Adviser', licence_current: true, has_signature: true };
    for (const email of [null, '', 'fixture@example.test']) {
        const client = { ...fixture, email };
        root = createRoot(document.getElementById('root'));
        await act(async () => root.render(React.createElement(Modal, {
            ...props, initialCase: client, cases: [client], signers: [signer], draft: savedDraft,
        })));
        const warning = document.getElementById('engagement-missing-email');
        const verify = [...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Verify & Email');
        if (!email) {
            assert.ok(warning, 'Explain the missing client email');
            assert.equal(warning.getAttribute('role'), 'alert');
            assert.match(warning.textContent, /Verify & Email is disabled because this client has no email address/);
            assert.match(warning.textContent, /refresh this page/i);
            assert.match(warning.textContent, /Add their email to the client record/);
            assert.equal(warning.querySelector('a'), null, 'Shared builder must not send pre-screening leads to a case-only profile');
            assert.equal(verify.getAttribute('aria-describedby'), warning.id);
            assert.equal(verify.disabled, true);
            assert.doesNotMatch(document.body.textContent, /Draft saved\. Ready to review the email/);
        } else {
            assert.equal(warning, null, 'Do not warn when the email is present');
            assert.equal(verify.getAttribute('aria-describedby'), null);
            assert.equal(verify.disabled, false);
            assert.match(document.body.textContent, /Draft saved\. Ready to review the email/);
        }
        await act(async () => root.unmount());
    }
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(Modal, { ...props, initialCase: null })));
    assert.equal(document.getElementById('engagement-missing-email'), null, 'Do not warn before selecting a client');
    await act(async () => root.unmount());
    console.log('Consultation mode switching, required fields, preview payloads, draft restoration and legacy online defaults passed');
})().catch(error => { console.error(error); process.exitCode = 1; });

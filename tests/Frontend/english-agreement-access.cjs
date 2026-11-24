const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { transformSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/english-agreements/7?signature=synthetic' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'localStorage']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
window.HTMLCanvasElement.prototype.getContext = () => ({ beginPath() {}, moveTo() {}, lineTo() {}, stroke() {}, clearRect() {} });
window.HTMLCanvasElement.prototype.toDataURL = () => 'data:image/png;base64,SYNTHETIC';
window.HTMLCanvasElement.prototype.getBoundingClientRect = () => ({ left: 0, top: 0, width: 430, height: 300 });
const React = require('react'); const { act } = React; const { createRoot } = require('react-dom/client');
const posts = [];
const Empty = () => null;
const inertia = { Head: Empty, Link: ({ children, ...props }) => React.createElement('a', props, children),
    router: { post: (...args) => posts.push(args) } };
function load(file, extra = {}) {
    const full = path.resolve(file), loaded = new Module(full, module); loaded.filename = full; loaded.paths = module.paths;
    loaded.require = name => extra[name] || (name === '@inertiajs/react' ? inertia : name === 'lucide-react'
        ? new Proxy({}, { get: () => Empty }) : name.startsWith('@/') ? { __esModule: true, default: Empty } : module.require(name));
    loaded._compile(transformSync(fs.readFileSync(full, 'utf8'), { loader: 'jsx', format: 'cjs', jsx: 'automatic' }).code, full);
    return loaded.exports;
}
const { ClientSignModal } = load('resources/js/pages/track/TrackingPage.jsx');
const Page = load('resources/js/pages/track/EnglishAgreement.jsx', { './TrackingPage': { ClientSignModal } }).default;
const StaffPage = load('resources/js/pages/portal/sales/ProposalsAgreements.jsx').default;
const root = createRoot(document.getElementById('root'));
const button = text => [...document.querySelectorAll('button')].find(b => b.textContent.trim().startsWith(text));
const click = async node => { assert.ok(node); await act(async () => node.click()); };
const agreement = { id: 7, title: 'English Agreement', view_url: '/english-agreements/7/pdf?signature=synthetic',
    download_url: '/english-agreements/7/pdf?signature=synthetic', sign_url: '/english-agreements/7/sign?signature=synthetic',
    issued_agreement_hash: 'a'.repeat(64), signed: false };
(async () => {
    await act(async () => root.render(React.createElement(Page, { document: agreement })));
    assert.equal(document.querySelector('iframe').getAttribute('src'), agreement.view_url);
    assert.equal(document.querySelector('input[type="password"]'), null);
    await click(button('Review and sign'));
    assert.equal(button('Sign agreement').disabled, true);
    const canvas = document.querySelector('canvas');
    await act(async () => {
        canvas.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: 2, clientY: 2 }));
        canvas.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 30, clientY: 25 }));
        canvas.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    });
    await click(document.querySelector('[role="checkbox"]'));
    await click(button('Sign agreement'));
    const [url, data, callbacks] = posts.at(-1);
    assert.equal(url, agreement.sign_url); assert.equal(data.issued_agreement_hash, agreement.issued_agreement_hash);
    assert.equal(data.acknowledged, 1); assert.equal(data.signature_data, 'data:image/png;base64,SYNTHETIC');
    await act(async () => { callbacks.onError({ signature_data: 'Synthetic retry message' }); callbacks.onFinish(); });
    assert.match(document.querySelector('[role="alert"]').textContent, /Synthetic retry message/);
    await act(async () => callbacks.onSuccess());
    assert.equal(document.querySelector('canvas'), null);
    await act(async () => root.render(React.createElement(Page, { document: { ...agreement, signed: true, sign_url: null } })));
    assert.match(document.querySelector('[role="status"]').textContent, /Signed/);
    assert.equal(button('Review and sign'), undefined);

    const row = { id: 42, name: 'Synthetic Client', email: 'synthetic@example.test', documents: [
        { id: 73, type: 'English agreement', checklist_key: 'agree.engagement_english', created_at: '2026-11-20', review_approved: true },
    ] };
    await act(async () => root.render(React.createElement(StaffPage, { portal: 'education', agreements: [row] })));
    await click(button('Agreements'));
    await click(document.querySelector('[aria-label="Row actions"]'));
    await click(button('Email lead'));
    assert.match(document.body.textContent, /No portal login required/);
    await click(button('Send notification'));
    assert.equal(posts.at(-1)[0], '/admin/leads/42/notify-document-ready');
    assert.deepEqual(posts.at(-1)[1], { kind: 'agreement', document_id: 73 });
    await act(async () => root.unmount());
    console.log('English agreement access: actual signed-document UI, consent/signature payload, retry/signed states and exact-document Notify passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

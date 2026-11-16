const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads/7' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'FormData']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const output = buildSync({
    entryPoints: ['resources/js/components/sales/CommunicationsPanel.jsx'], bundle: true, write: false,
    format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@/components/templates/RichTextEditor'],
}).outputFiles[0].text;
const loaded = new Module(path.resolve('client-conversation-errors.cjs'), module);
loaded.filename = path.resolve('client-conversation-errors.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@/components/templates/RichTextEditor' ? () => null : module.require(name);
loaded._compile(output, loaded.filename);
const Panel = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
let status = 403;
let requests = [];
global.fetch = async (url, options = {}) => {
    requests.push({url, options});
    return { ok: status === 200, status, json: async () => status === 200
        ? { data: [], can_email: true, can_attach_documents: true, attachment_documents: [{ id: 11, name: 'Offer of Place.pdf', status: 'Approved' }, { id: 12, name: 'Offer of Place.pdf', status: 'StaffShared' }] }
        : { message: 'You do not have access to this lead.' } };
};
(async () => {
    await act(async () => root.render(React.createElement(Panel, {leadId: 7, leadEmail:'synthetic@example.test'})));
    assert.match(document.body.textContent, /You do not have access to this lead\./, 'A denied conversation must visibly report its access error');
    assert.doesNotMatch(document.body.textContent, /No messages yet/, 'Denied reads must not masquerade as an empty conversation');
    const retry = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Retry');
    assert.ok(retry, 'The user can retry loading the conversation');
    status = 200;
    await act(async () => retry.dispatchEvent(new MouseEvent('click', {bubbles:true})));
    assert.match(document.body.textContent, /No messages yet/);
    assert.doesNotMatch(document.body.textContent, /You do not have access/);
    status = 419;
    const sync = [...document.querySelectorAll('button')].find(b => b.textContent.includes('Check for replies'));
    await act(async () => sync.dispatchEvent(new MouseEvent('click', {bubbles:true})));
    assert.match(document.body.textContent, /refresh.*try again/i, 'Session expiry while syncing must be actionable');
    assert.doesNotMatch(document.body.textContent, /Checked the mailbox/, 'Failed sync must not claim success');
    const click = async label => {
        const btn = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === label);
        assert.ok(btn, `Expected ${label}`);
        await act(async () => btn.click());
    };
    await click('New email');
    const documents = document.querySelector('select[aria-label="Attach a student document"]');
    assert.ok(documents);
    assert.match(documents.options[1].textContent, /Offer of Place\.pdf.*Approved.*#11/s);
    assert.match(documents.options[2].textContent, /Offer of Place\.pdf.*Staff shared.*#12/s);
    await act(async () => {
        documents.value = '11';
        documents.dispatchEvent(new Event('change', { bubbles: true }));
    });
    assert.ok(document.querySelector('[aria-label="Remove Offer of Place.pdf"]'));
    status = 413;
    await click('Send email');
    assert.match(document.body.textContent, /attachments are too large/);
    assert.ok(document.querySelector('[aria-label="Remove Offer of Place.pdf"]'), 'Failed send keeps the selected attachment');
    status = 200;
    await click('Send email');
    const send = requests.findLast(({ options }) => options.body);
    assert.equal(send.url, '/admin/leads/7/communications');
    assert.deepEqual(Object.fromEntries(send.options.body), { body: '', 'document_ids[]': '11' });
    await act(async () => root.unmount());
    console.log('Conversation access errors, reply sync and student attachment retry verified');
})().catch(error => { console.error(error); process.exitCode = 1; });

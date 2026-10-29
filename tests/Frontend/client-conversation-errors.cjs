const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads/7' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
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
loaded.require = name => name === '@/components/templates/RichTextEditor' ? {default: () => null} : module.require(name);
loaded._compile(output, loaded.filename);
const Panel = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
let status = 403;
let requests = [];
global.fetch = async (url, options = {}) => {
    requests.push({url, options});
    return { ok: status === 200, status, json: async () => status === 200 ? { data: [], can_email: true } : { message: 'You do not have access to this lead.' } };
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
    await act(async () => root.unmount());
    console.log('Conversation access errors, retry recovery and failed reply sync verified');
})().catch(error => { console.error(error); process.exitCode = 1; });

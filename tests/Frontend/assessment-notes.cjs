const assert = require('node:assert/strict');
const { test, beforeEach, afterEach } = require('node:test');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');

const dom = new JSDOM('<meta name="csrf-token" content="stale-login-token"><div id="root"></div>', { url: 'http://localhost' });
for (const name of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[name] = dom.window[name];
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const compiled = buildSync({
    entryPoints: ['resources/js/pages/portal/immigration/Assessments.jsx'],
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react-dom', '@inertiajs/react', 'sonner', 'lucide-react', '@/*'],
}).outputFiles[0].text;
const component = new Module(path.resolve('assessment-notes-test.cjs'), module);
const empty = () => null;
const successToasts = [];
component.require = (name) => {
    if (name === '@inertiajs/react') return { Head: empty, Link: empty, router: {} };
    if (name === 'sonner') return { toast: { success: (message) => successToasts.push(message), error: empty } };
    if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
    if (name.startsWith('@/')) return { default: empty, AiAssessmentReviewModal: empty };
    return module.require(name);
};
component.filename = path.resolve('assessment-notes-test.cjs');
component.paths = module.paths;
component._compile(compiled, component.filename);
const { IntakeViewModal } = component.exports;
let root, requests, response;
beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=current%3Dtoken; path=/';
    successToasts.length = 0;
    requests = [];
    response = { ok: true, json: async () => ({ note: { id: 7, body: 'Progress update', author: 'Staff' } }) };
    global.fetch = async (url, options) => {
        if (!url.endsWith('/notes')) return { ok: true, json: async () => ({ review: {} }) };
        requests.push({ url, ...options });
        if (response instanceof Error) throw response;
        return response;
    };
    root = createRoot(document.getElementById('root'));
});
afterEach(async () => { await act(async () => root.unmount()); });
async function render(extra = {}) {
    await act(async () => root.render(React.createElement(IntakeViewModal, {
        intake: { visa_type: 'work', id: 42 },
        data: { ai_review: {}, notes: [], sections: [] },
        loading: false, asPage: true, chromeless: true, ...extra,
    })));
    const textarea = document.querySelector('textarea[placeholder="Add a note…"]');
    await act(async () => {
        Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set.call(textarea, 'Progress update');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    });
    return textarea;
}
async function post() {
    await act(async () => [...document.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Post').click());
}

test('assessment notes prefer the current cookie over stale HTML and clear the draft only after saving', async () => {
    const textarea = await render();
    await post();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/portal/immigration/assessments/work/42/notes');
    assert.equal(requests[0].headers['X-XSRF-TOKEN'], 'current=token');
    assert.equal(requests[0].headers['X-CSRF-TOKEN'], undefined);
    assert.equal(requests[0].credentials, 'same-origin');
    assert.deepEqual(JSON.parse(requests[0].body), { body: 'Progress update' });
    assert.equal(textarea.value, '');
    assert.match(document.body.textContent, /Progress update/);
    assert.equal(document.querySelector('[role="alert"]'), null);
});

test('assessment notes fall back to the HTML token when the cookie is absent', async () => {
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
    await render();
    await post();
    assert.equal(requests[0].headers['X-CSRF-TOKEN'], 'stale-login-token');
    assert.equal(requests[0].headers['X-XSRF-TOKEN'], undefined);
});

for (const [label, failed] of [
    ['expired session', { ok: false, status: 419, json: async () => ({ message: 'CSRF token mismatch.' }) }],
    ['validation failure', { ok: false, status: 422, json: async () => ({ errors: { body: ['The note is too long.'] } }) }],
    ['server failure', { ok: false, status: 500, json: async () => { throw new Error('HTML response'); } }],
    ['network failure', new Error('Connection lost')],
    ['missing saved note', { ok: true, json: async () => ({}) }],
]) {
    test(`assessment notes show ${label} and preserve the draft for retry`, async () => {
        response = failed;
        const textarea = await render();
        await post();
        assert.equal(textarea.value, 'Progress update');
        assert.ok(document.querySelector('[role="alert"]')?.textContent.trim(), 'The failure must be visible');
        assert.match(document.body.textContent, /No notes yet/);
        assert.deepEqual(successToasts, []);
        response = { ok: true, json: async () => ({ note: { id: 8, body: 'Progress update', author: 'Staff' } }) };
        await post();
        assert.equal(textarea.value, '');
        assert.equal(document.querySelector('[role="alert"]'), null);
    });
}

for (const onPostNote of [async () => false, async () => { throw new Error('Save failed'); }]) {
    test('case-profile note persistence failures remain visible and preserve the draft', async () => {
        const textarea = await render({ onPostNote });
        await post();
        assert.equal(textarea.value, 'Progress update');
        assert.ok(document.querySelector('[role="alert"]')?.textContent.trim());
        assert.deepEqual(successToasts, []);
    });
}

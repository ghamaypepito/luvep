const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/immigration/cases/7/profile?tab=documents' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'); const { act } = React; const { createRoot } = require('react-dom/client');
let role = 'immigration_manager', revealed = 0;
const posts = [];
const output = buildSync({ entryPoints: ['resources/js/components/immigration/case-profile/tabs/OtherDocumentsUpload.jsx'], bundle: true,
    write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('other-case-documents-test.cjs'), module);
loaded.filename = path.resolve('other-case-documents-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? { usePage: () => ({ props: { auth: { user: { role } } } }), router: { post: (...args) => posts.push(args) } } : module.require(name);
loaded._compile(output, loaded.filename);
const Upload = loaded.exports.default; const root = createRoot(document.getElementById('root'));
const render = () => act(async () => root.render(React.createElement(Upload, { leadId: 7, onUploaded: () => revealed++ })));
const choose = files => act(async () => { const input = document.querySelector('input'); Object.defineProperty(input, 'files', { configurable: true, value: files }); input.dispatchEvent(new Event('change', { bubbles: true })); });
const file = name => new dom.window.File(['%PDF-1.4 synthetic'], name, { type: 'application/pdf' });
(async () => {
    await render();
    assert.match(document.body.textContent, /does not send a document request email/);
    let pick = 0; document.querySelector('input').click = () => pick++;
    await act(async () => document.querySelector('button').click()); assert.equal(pick, 1);
    await choose([]); assert.equal(posts.length, 0);
    await choose(Array.from({ length: 11 }, (_, i) => file(`${i}.pdf`)));
    assert.equal(posts.length, 0); assert.match(document.querySelector('[role=alert]').textContent, /10 files per batch/);
    const huge = file('large.pdf'); Object.defineProperty(huge, 'size', { value: 50 * 1024 * 1024 + 1 });
    await choose([huge]); assert.equal(posts.length, 0); assert.match(document.querySelector('[role=alert]').textContent, /50 MB/);
    const first = [file('same.pdf'), file('same.pdf')];
    await choose(first);
    assert.equal(posts.length, 1); assert.equal(posts[0][0], '/portal/immigration/cases/7/documents/other');
    assert.deepEqual(posts[0][1].files, first); assert.equal(posts[0][2].forceFormData, true);
    assert.ok(posts[0][2].only.includes('documents')); assert.equal(document.querySelector('button').disabled, true);
    await choose([file('busy.pdf')]); assert.equal(posts.length, 1, 'Do not submit a second batch while busy');
    await act(async () => { posts[0][2].onSuccess(); posts[0][2].onFinish(); });
    assert.equal(revealed, 1, 'Successful upload reveals Other in the parent');
    assert.match(document.querySelector('[role=status]').textContent, /2 supporting documents uploaded/);
    assert.equal(document.querySelector('button').disabled, false);
    await choose([file('next.pdf')]); assert.equal(posts.length, 2, 'Repeated batches remain available');
    await act(async () => { posts[1][2].onError({ 'files.0': 'Unsupported file type' }); posts[1][2].onFinish(); });
    assert.match(document.querySelector('[role=alert]').textContent, /Unsupported file type/);
    assert.equal(document.querySelector('[role=status]'), null); assert.equal(revealed, 1);
    await choose([file('retry.pdf')]); assert.equal(posts.length, 3);
    await act(async () => { posts[2][2].onSuccess(); posts[2][2].onFinish(); });
    assert.match(document.querySelector('[role=status]').textContent, /1 supporting document uploaded/);
    for (role of ['lead', 'agent', 'sub_agent', 'finance', 'education', 'sales', 'accommodation']) {
        await render(); assert.equal(document.getElementById('root').textContent, '', `${role} receives no new control`);
    }
    for (role of ['immigration', 'immigration_adviser', 'admin', 'super_admin']) {
        await render(); assert.ok(document.querySelector('button'), `${role} retains ordinary staff upload access`);
    }
    await act(async () => root.unmount());
    console.log('Other documents upload: picker, batch/size validation, repeated uploads, busy guard, success/errors/retry, refresh callback and staff-only control passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });

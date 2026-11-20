const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads/7?tab=documents' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
let requests = 0;
global.fetch = async () => { requests++; throw new Error('A read-only attachment list must not create requests'); };
const output = buildSync({ entryPoints: ['resources/js/components/education/EducationNoteDocuments.jsx'], bundle: true,
    write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('education-note-documents-test.cjs'), module);
loaded.filename = path.resolve('education-note-documents-test.cjs'); loaded.paths = module.paths;
loaded._compile(output, loaded.filename);
const Documents = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const url = id => `https://luvep.test/admin/leads/7/notes/${id}/attachments/0`;
const notes = [
    { id: 10, body: 'PRIVATE NOTE BODY MUST NOT BE REPEATED', author_name: 'Education Staff', created_at: '2026-11-17T03:35:00Z', attachments: [{ name: 'same-name.pdf', url: url(10) }] },
    { id: 11, parent_id: 10, author_name: 'Another Staff', created_at: '2026-11-12T03:35:00Z', attachments: [{ name: 'same-name.pdf', url: url(11) }] },
    { id: 12, body: 'No attachment', attachments: null },
];
const render = props => act(async () => root.render(React.createElement(Documents, props)));
(async () => {
    await render({ notes, portalBase: '/portal/education', currentUser: { role: 'education' } });
    assert.match(document.body.textContent, /Other Documents - Internal note attachments \(2\)/);
    assert.equal(document.querySelectorAll('li').length, 2, 'Same filename and reply attachments remain separate');
    assert.match(document.body.textContent, /Education Staff/);
    assert.match(document.body.textContent, /Another Staff/);
    assert.equal(document.body.textContent.includes('PRIVATE NOTE BODY MUST NOT BE REPEATED'), false);
    assert.match(document.body.textContent, /Staff only/);
    for (const [index, row] of [...document.querySelectorAll('li')].entries()) {
        const [view, download] = row.querySelectorAll('a');
        assert.equal(view.href, url(index + 10) + '?inline=1');
        assert.equal(view.target, '_blank');
        assert.equal(view.rel, 'noreferrer');
        assert.equal(download.href, url(index + 10));
        assert.equal(download.getAttribute('aria-label'), 'Download same-name.pdf');
    }
    await render({ notes: [notes[1]], portalBase: '/portal/education' });
    assert.equal(document.querySelectorAll('li').length, 1, 'Removed notes disappear on profile refresh');
    assert.equal(document.querySelector('li a').href, url(11) + '?inline=1');
    await render({ notes: [], portalBase: '/portal/education' });
    assert.match(document.body.textContent, /No files attached to internal notes yet/);
    assert.equal(document.querySelectorAll('a').length, 0);
    for (const portalBase of ['/portal/immigration', '/portal/sales', '/portal/accommodation', '/portal/english', '/portal/agent', '/portal/sub-agent', '/portal/lead', '/admin']) {
        await render({ notes, portalBase, currentUser: { role: 'admin' } });
        assert.equal(document.getElementById('root').textContent, '', `${portalBase} remains unchanged`);
    }
    await render({ notes, portalBase: '/admin', currentUser: { role: 'education' } });
    assert.equal(document.querySelectorAll('li').length, 2, 'Existing Education admin-route profile is supported');
    await render({ notes: [{ id: 20, attachments: [{ name: '<img src=x onerror=alert(1)>.pdf', url: url(20) }] }], portalBase: '/portal/education' });
    assert.equal(document.querySelector('img'), null, 'Filenames are rendered as text');
    assert.match(document.body.textContent, /<img src=x onerror=alert\(1\)>.pdf/);
    assert.equal(requests, 0, 'Display does not copy files or post changes');
    await act(async () => root.unmount());
    console.log('Education note documents: existing/reply attachments, duplicate names, private view/download links, removal refresh, empty state, escaped names and portal isolation passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });

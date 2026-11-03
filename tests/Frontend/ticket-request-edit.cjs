const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/tickets' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'File', 'FormData']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
URL.createObjectURL = () => 'blob:synthetic-preview'; URL.revokeObjectURL = () => {};
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const requests = [];
const inertia = {
    Head: () => null, router: { get() {}, post() {} },
    useForm(initial) {
        const [data, setData] = React.useState(initial), [errors, setErrors] = React.useState({}), [processing, setProcessing] = React.useState(false), [progress, setProgress] = React.useState(null);
        const transform = React.useRef(values => values);
        return { data, errors, processing, progress, setData: (key, value) => setData(old => ({ ...old, [key]: value })), transform: callback => { transform.current = callback; },
            post(url, options) {
                setProcessing(true); setErrors({}); setProgress(null);
                const request = { url, options, data: transform.current(data), fail: messages => { setErrors(messages); setProcessing(false); options.onError?.(messages); options.onFinish?.(); }, progress: percent => setProgress({ percentage: percent }), succeed: () => { setProcessing(false); options.onSuccess?.(); options.onFinish?.(); } };
                requests.push(request);
            },
        };
    },
};
const axios = require('axios');
axios.defaults.adapter = async config => ({ data: { messages: { data: [], next_page_url: null } }, status: 200, statusText: 'OK', headers: {}, config });
const output = buildSync({ stdin: { contents: `export {default as Mine} from './resources/js/pages/tickets/MyTickets.jsx'; export {default as Admin} from './resources/js/pages/admin/SystemTickets.jsx';`, resolveDir: process.cwd(), loader: 'jsx' }, bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, external: ['react', 'react/jsx-runtime', '@inertiajs/react', 'axios'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('ticket-request-edit.cjs'), module); loaded.filename = path.resolve('ticket-request-edit.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(output, loaded.filename);
const { Mine, Admin } = loaded.exports;
const root = createRoot(document.getElementById('root'));
let currentComponent, currentProps, instance = 0;
const render = async (Component, props, fresh = true) => { currentComponent = Component; currentProps = props; if (fresh) instance++; await act(async () => root.render(React.createElement(Component, { ...props, key: instance }))); };
const update = props => render(currentComponent, { ...currentProps, ...props }, false);
const button = text => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === text);
const click = async node => { assert.ok(node, 'Requested control exists'); await act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true }))); };
const editor = () => document.querySelector('form[aria-label="Edit ticket request"]');
const field = name => editor()?.querySelector(`[name="${name}"]`);
const input = async (node, value) => act(async () => { const prototype = node.tagName === 'TEXTAREA' ? dom.window.HTMLTextAreaElement.prototype : node.tagName === 'SELECT' ? dom.window.HTMLSelectElement.prototype : dom.window.HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(prototype, 'value').set.call(node, value); node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); });
const submit = async () => act(async () => editor().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
const selectFiles = async files => { const node = editor().querySelector('input[type=file]'); Object.defineProperty(node, 'files', { value: files, configurable: true }); await act(async () => node.dispatchEvent(new Event('change', { bubbles: true }))); };
const file = name => new File(['synthetic'], name, { type: 'image/png' });
const attachment = (name = 'evidence.pdf') => ({ name, mime: 'application/pdf', size: 123, url: `/private/${name}`, download_url: `/private/${name}` });
const ticket = { id: 42, ticket_ref: 'TKT-42', title: 'Synthetic sender request', description: 'Original details', category: 'bug', priority: 'normal', status: 'done', images: ['/legacy.png'], attachments: [attachment()], can_edit_request: true, request_version: 'a'.repeat(64), request_edited_at: null, assigned_to: 7, assignee: 'Support owner', admin_response: 'Completed response', resolution_report: { issue_summary: 'Saved issue', resolution: 'Saved resolution' }, archived_at: null };
const page = data => ({ tickets: { data, links: [] } });
(async () => {
    // Catches missing sender editing on completed tickets, incorrect multipart fields and accidental management-field submission.
    await render(Mine, page([ticket]));
    await click(button('Edit request'));
    assert.equal(field('title').value, 'Synthetic sender request'); assert.equal(field('description').value, 'Original details');
    assert.equal(field('title').maxLength, 160); assert.equal(field('description').maxLength, 5000);
    assert.match(editor().textContent, /saving.*status.*done|done.*status.*saving/is);
    assert.ok(document.querySelector('img[src="/legacy.png"]')); assert.ok(document.querySelector('a[href="/private/evidence.pdf"]'));
    await input(field('title'), 'Updated sender title'); await input(field('description'), 'New detail with evidence'); await input(field('category'), 'feature'); await input(field('priority'), 'high');
    await selectFiles([file('new.png')]); await selectFiles([file('extra.png')]);
    await submit(); const request = requests.at(-1);
    assert.equal(request.url, '/system-tickets/42/request'); assert.equal(request.options.forceFormData, true); assert.equal(request.options.preserveScroll, true);
    assert.ok(request.data instanceof FormData); assert.equal(request.data.get('title'), 'Updated sender title'); assert.equal(request.data.get('description'), 'New detail with evidence'); assert.equal(request.data.get('category'), 'feature'); assert.equal(request.data.get('priority'), 'high'); assert.equal(request.data.get('request_version'), 'a'.repeat(64));
    assert.deepEqual(request.data.getAll('attachments[]').map(item => item.name), ['new.png', 'extra.png']);
    assert.deepEqual([...new Set(request.data.keys())].sort(), ['attachments[]', 'category', 'description', 'priority', 'request_version', 'title']);
    assert.equal(button('Saving…').disabled, true); assert.equal(button('Cancel').disabled, true); await submit(); assert.equal(requests.length, 1);
    await act(async () => request.progress(35)); assert.match(editor().textContent, /35%/);
    await act(async () => request.fail({ title: 'Review this title.', 'attachments.1': 'Second upload failed.' }));
    assert.equal(field('title').value, 'Updated sender title'); assert.match(editor().textContent, /Review this title/); assert.match(editor().textContent, /Second upload failed/); assert.match(editor().textContent, /extra.png/);
    // Catches losing edits/files or silently changing the version when updated server props arrive during an edit.
    await update(page([{ ...ticket, title: 'Another saved revision', request_version: 'b'.repeat(64) }]));
    assert.equal(field('title').value, 'Updated sender title'); await submit(); assert.equal(requests.at(-1).data.get('request_version'), 'a'.repeat(64));
    await act(async () => requests.at(-1).fail({ request_version: 'This request changed. Reload before saving.' }));
    assert.match(editor().textContent, /reload/i); assert.match(editor().textContent, /copy.*draft.*before.*reload|copy.*before.*reload/is); assert.equal(field('title').value, 'Updated sender title'); assert.match(editor().textContent, /new.png/);
    await click(button('Cancel')); assert.equal(editor(), null); await click(button('Edit request'));
    assert.equal(field('title').value, 'Another saved revision'); assert.equal(editor().querySelector('[aria-label="Selected attachments"]'), null);
    await submit(); assert.equal(requests.at(-1).data.get('request_version'), 'b'.repeat(64));
    await update(page([{ ...ticket, title: 'Saved current title', description: 'Saved current details', request_version: 'c'.repeat(64), request_edited_at: '2026-11-03T02:00:00Z' }]));
    await act(async () => requests.at(-1).succeed()); assert.equal(editor(), null); assert.match(document.body.textContent, /Saved current title/); assert.ok(document.querySelector('time[datetime="2026-11-03T02:00:00Z"]'));
    // Catches ignoring existing evidence in the combined ten-file limit or silently truncating a selection.
    const nearLimit = { ...ticket, status: 'declined', images: ['/legacy.png'], attachments: Array.from({ length: 8 }, (_, index) => attachment(`saved-${index}.pdf`)) };
    await render(Mine, page([nearLimit])); await click(button('Edit request')); assert.match(editor().textContent, /declined/i);
    await selectFiles([file('last.png')]); await selectFiles([file('over-limit.png')]);
    assert.equal(editor().querySelector('[aria-label="Selected attachments"]').children.length, 1); assert.match(editor().textContent, /10/); assert.match(editor().querySelector('[role=alert]').textContent, /existing|remaining|total/i);
    await submit(); assert.deepEqual(requests.at(-1).data.getAll('attachments[]').map(item => item.name), ['last.png']); await act(async () => requests.at(-1).fail({ request: 'Try again.' }));
    assert.match(editor().textContent, /Try again/);
    // Catches exposing edits to foreign/archived requests even if ordinary ticket details remain readable.
    for (const denied of [{ ...ticket, can_edit_request: false }, { ...ticket, archived_at: '2026-11-03T00:00:00Z' }]) { await render(Mine, page([denied])); assert.equal(button('Edit request'), undefined); }
    // Catches an admin drawer using a stale active snapshot or remounting and discarding independent management drafts.
    const adminProps = { ...page([ticket]), meta: { statuses: ['open', 'done', 'declined'] }, assignees: [{ id: 7, name: 'Support owner' }] };
    await render(Admin, adminProps); await click(button('Synthetic sender request'));
    const topic = [...document.querySelectorAll('label')].find(node => node.textContent === 'Topic').querySelector('input'); await input(topic, 'Keep management draft');
    await click(button('Edit request')); await input(field('title'), 'Updated from admin drawer'); await submit();
    const saved = { ...ticket, title: 'Updated from admin drawer', description: 'Refreshed request details', request_version: 'd'.repeat(64), request_edited_at: '2026-11-03T03:00:00Z', attachments: [...ticket.attachments, attachment('appended.pdf')] };
    // Renaming can remove a ticket from a search result; the authorized returned ticket still refreshes its open drawer.
    await update({ ...adminProps, ...page([]), updated_request: saved }); await act(async () => requests.at(-1).succeed());
    assert.equal(document.querySelector('[role=dialog] h2').textContent, 'Updated from admin drawer'); assert.match(document.querySelector('[role=dialog]').textContent, /Refreshed request details/);
    assert.equal(topic.value, 'Keep management draft'); assert.ok(document.querySelector('[role=dialog] a[href="/private/appended.pdf"]')); assert.ok(document.querySelector('[role=dialog] time[datetime="2026-11-03T03:00:00Z"]'));
    await update({ ...adminProps, ...page([]), updated_request: null });
    assert.equal(document.querySelector('[role=dialog] h2').textContent, 'Updated from admin drawer', 'A later partial refresh must retain the updated fallback after the one-use response prop clears');
    await click(button('Edit request')); assert.equal(field('title').value, 'Updated from admin drawer'); await submit(); assert.equal(requests.at(-1).data.get('request_version'), 'd'.repeat(64));
    await act(async () => root.unmount()); console.log('Ticket sender edits: completed requests, multipart append, evidence limits, progress/errors, stale versions, cancel reset, permissions and refreshed admin drawer with preserved management drafts verified.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });

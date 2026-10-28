const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://preview.test/program-verification' });
for (const key of ['window', 'document', 'HTMLElement', 'HTMLDialogElement', 'Event', 'MouseEvent']) {
    global[key] = dom.window[key];
}
global.IS_REACT_ACT_ENVIRONMENT = true;
// jsdom does not implement native dialog top-layer behavior; browser checks cover it.
HTMLDialogElement.prototype.showModal = function () { this.open = true; };
HTMLDialogElement.prototype.close = function () { this.open = false; };

const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const requests = [];
const inertia = {
    Head: () => null,
    Link: ({ children, ...props }) => React.createElement('a', props, children),
    router: { post: (...args) => requests.push(args), get: () => {} },
};
const compiled = buildSync({
    entryPoints: ['resources/js/pages/admin/ProgramVerification.jsx'],
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react-dom', '@inertiajs/react'],
}).outputFiles[0].text;
const componentModule = new Module(path.resolve('proposal-confirmation-test.cjs'), module);
componentModule.filename = path.resolve('proposal-confirmation-test.cjs');
componentModule.paths = module.paths;
componentModule.require = (name) => name === '@inertiajs/react' ? inertia : module.require(name);
componentModule._compile(compiled, componentModule.filename);
const Page = componentModule.exports.default;
const root = createRoot(document.getElementById('root'));
const proposal = { id: 42, name: 'Sample Student', email: 'student@example.test', initials: 'SS', status: 'pending', programs: [], programs_count: 0 };
const render = async (p = proposal) => act(async () => root.render(React.createElement(Page, { proposals: [p], selectedLeadId: p.id, selectedStatus: p.status })));
const button = (text, scope = document) => [...scope.querySelectorAll('button')].find((el) => el.textContent.trim() === text);
const click = async (el) => { assert.ok(el, 'Expected button to exist'); await act(async () => el.click()); };
const dialog = () => document.querySelector('dialog[open], [role="dialog"]');

(async () => {
    await render();
    await click(button('Verify & approve all'));
    assert.equal(requests.length, 0, 'Opening confirmation must not approve or email the proposal');
    assert.ok(dialog(), 'Confirmation is visible before approval');
    assert.match(dialog().textContent, /student@example\.test/);
    assert.match(dialog().textContent, /client.*email/i);
    assert.equal(document.activeElement, button('Cancel', dialog()), 'Cancel is the safe default focus');
    await click(button('Cancel', dialog()));
    assert.equal(dialog(), null);
    assert.equal(requests.length, 0, 'Cancel must not submit');

    await click(button('Verify & approve all'));
    await act(async () => dialog().dispatchEvent(new Event('cancel', { cancelable: true })));
    assert.equal(dialog(), null, 'Escape cancels confirmation');
    assert.equal(requests.length, 0);

    await click(button('Verify & approve all'));
    await act(async () => dialog().click());
    assert.equal(dialog(), null, 'Backdrop click cancels confirmation');
    assert.equal(requests.length, 0);

    await click(button('Verify & approve all'));
    const confirm = button('Verify & approve', dialog());
    await click(confirm);
    assert.equal(requests.length, 1);
    assert.equal(requests[0][0], '/program-verification/42/approve');
    assert.deepEqual(requests[0][1], { verify_all: true, send_email: true });
    assert.equal(confirm.disabled, true, 'Prevent repeat submissions while sending');
    assert.equal(button('Cancel', dialog()).disabled, true, 'Cannot cancel a request already submitted');
    await click(confirm);
    assert.equal(requests.length, 1);
    await act(async () => dialog().dispatchEvent(new Event('cancel', { cancelable: true })));
    assert.ok(dialog(), 'Do not imply an in-flight approval was cancelled');
    await act(async () => { requests[0][2].onError({ proposal: 'Please review the proposal.' }); requests[0][2].onFinish(); });
    assert.match(dialog().querySelector('[role="alert"]').textContent, /Please review/);
    assert.equal(button('Verify & approve', dialog()).disabled, false, 'Failed approval can be retried');
    await click(button('Verify & approve', dialog()));
    assert.equal(requests.length, 2);
    await act(async () => { requests[1][2].onSuccess(); requests[1][2].onFinish(); });
    assert.equal(dialog(), null, 'Successful approval dismisses confirmation');

    await render({ ...proposal, id: 43, email: null });
    await click(button('Verify & approve all'));
    assert.match(dialog().textContent, /no email address/i, 'Do not promise an email when no recipient is saved');
    await click(button('Cancel', dialog()));
    await render({ ...proposal, id: 44, status: 'approved', approved_at: '2026-10-28T02:00:00Z' });
    assert.equal(button('Verify & approve all').disabled, true, 'Already approved proposals cannot be submitted again');
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Proposal approval confirmation tests passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });

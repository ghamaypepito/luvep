const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/agent/assigned-cases/7' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'File']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'); const { act } = React; const { createRoot } = require('react-dom/client');
let role = 'agent', modules = ['referral_cases'];
const posts = [], empty = () => null;
function load(file) {
    const output = buildSync({ entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react-dom', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react', './AffiliateWorkspaceLayout'] }).outputFiles[0].text;
    const compiled = new Module(path.resolve('assigned-case-helper-test.cjs'), module);
    compiled.filename = path.resolve('assigned-case-helper-test.cjs'); compiled.paths = module.paths;
    compiled.require = name => {
        if (name === '@inertiajs/react') return { Head: empty, Link: ({ children, ...props }) => React.createElement('a', props, children), usePage: () => ({ props: { auth: { user: { role }, modules } } }), router: { post: (...args) => posts.push(args) } };
        if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
        if (name === './AffiliateWorkspaceLayout') return ({ nav }) => React.createElement('nav', {}, nav.filter(item => item.href).map(item => React.createElement('a', { key: item.href, href: item.href }, item.name)));
        return module.require(name);
    };
    compiled._compile(output, compiled.filename); return compiled.exports.default;
}
const List = load('resources/js/pages/portal/agent/AssignedCases.jsx');
const Detail = load('resources/js/pages/portal/agent/AssignedCase.jsx');
const Staff = load('resources/js/pages/portal/immigration/CaseHelper.jsx');
const StaffLink = load('resources/js/components/immigration/case-profile/CaseHelperLink.jsx');
const Layout = load('resources/js/components/layout/AgentLayout.jsx');
const root = createRoot(document.getElementById('root'));
const render = (Component, props) => act(async () => root.render(React.createElement(Component, props)));
const button = text => [...document.querySelectorAll('button')].find(el => el.textContent === text);
async function click(el) { assert.ok(el); await act(async () => el.click()); }
async function change(el, value) { assert.ok(el); await act(async () => { Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set.call(el, value); el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); }); }
const record = { id: 7, name: 'Synthetic assigned case', reference: 'CASE-TEST', visa: 'Test visa', stage: 'For Assessment' };
(async () => {
    await render(Layout, {});
    assert.ok(document.querySelector('a[href="/portal/agent/cases"]'));
    assert.equal(Boolean(document.querySelector('a[href="/portal/agent/assigned-cases"]')), false);
    modules = ['referral_cases', 'assigned_immigration_cases']; await render(Layout, {});
    assert.ok(document.querySelector('a[href="/portal/agent/assigned-cases"]'));
    role = 'admin'; await render(Layout, {});
    assert.equal(Boolean(document.querySelector('a[href="/portal/agent/assigned-cases"]')), false);
    await render(List, { cases: { data: [], links: [] } }); assert.match(document.body.textContent, /No cases are currently assigned/);
    await render(List, { cases: { data: [record], links: [] } }); assert.ok(document.querySelector('a[href="/portal/agent/assigned-cases/7"]'));
    await render(Detail, { case: record, documents: [{ id: 12, name: 'Visible.pdf', status: 'Approved', url: '/portal/agent/assigned-cases/7/documents/12/download' }], slots: [{ key: 'passport', label: 'Passport' }], requests: [{ id: 21, label: 'Specific requested evidence' }] });
    assert.ok(document.querySelector('a[href$="download?inline=1"]'));
    assert.match(document.body.textContent, /Bank, funds and income records, signatures or signed forms, and sponsor, partner, child or mixed-person records stay with staff/);
    assert.equal(button('Upload requested file').disabled, true);
    assert.equal([...document.querySelectorAll('option')].some(option => option.textContent === 'Specific requested evidence'), false);
    assert.match(document.body.textContent, /Unclassified ad hoc and RFI requests stay with staff/);
    await change(document.querySelector('select'), 'passport');
    const file = new File(['%PDF-1.4 synthetic'], 'new.pdf', { type: 'application/pdf' });
    await act(async () => { const input = document.querySelector('input[type=file]'); Object.defineProperty(input, 'files', { configurable: true, value: [file] }); input.dispatchEvent(new Event('change', { bubbles: true })); });
    await click(button('Upload requested file'));
    assert.equal(posts[0][0], '/portal/agent/assigned-cases/7/uploads');
    assert.deepEqual(posts[0][1], { file, checklist_key: 'passport' }); assert.equal(posts[0][2].forceFormData, true);
    assert.equal(button('Uploading…').disabled, true);
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(posts.length, 1);
    await act(async () => { posts[0][2].onError({ request_id: 'Request is no longer active' }); posts[0][2].onFinish(); });
    assert.match(document.querySelector('[role=alert]').textContent, /no longer active/);
    await click(button('Upload requested file'));
    await act(async () => { posts[1][2].onSuccess(); posts[1][2].onFinish(); });
    assert.match(document.querySelector('[role=status]').textContent, /staff review/);
    assert.equal(button('Upload requested file').disabled, true);
    await render(Detail, { case: { ...record, id: 8 }, slots: [{ key: 'passport', label: 'Passport' }] });
    assert.equal(document.querySelector('select').value, '');
    await render(Detail, { case: record, requests: [{ id: 21, label: 'Specific requested evidence' }] });
    assert.equal(document.querySelector('form'), null);
    assert.match(document.body.textContent, /No eligible required checklist uploads/);
    await render(Staff, { case: record, helper: null, eligibleHelpers: [{ id: 10, name: 'Synthetic helper' }] });
    assert.match(document.body.textContent, /current owner and adviser remain responsible/);
    assert.match(document.body.textContent, /Unclassified ad hoc and RFI requests stay with staff/);
    assert.match(document.body.textContent, /Bank, funds and income records, signatures or signed forms, and sponsor, partner, child or mixed-person records stay with staff/);
    assert.match(document.body.textContent, /excludes invoices, payments, internal notes, stage changes, signatures and approvals/);
    await change(document.querySelector('select'), '10'); assert.equal(button('Update helper access').disabled, true);
    await click(document.querySelector('input[type=checkbox]')); await click(button('Update helper access'));
    assert.equal(posts[2][0], '/portal/immigration/cases/7/helper');
    assert.deepEqual(posts[2][1], { helper_id: 10, expected_helper_id: null, confirmed: true });
    await act(async () => { posts[2][2].onError({ expected_helper_id: 'Helper access changed; refresh' }); posts[2][2].onFinish(); });
    assert.match(document.querySelector('[role=alert]').textContent, /refresh/);
    await render(Staff, { case: { ...record, id: 8 }, helper: null, eligibleHelpers: [{ id: 10, name: 'Synthetic helper' }] });
    assert.equal(document.querySelector('select').value, ''); assert.equal(document.querySelector('input').checked, false);
    await render(Staff, { case: record, helper: { id: 10, name: 'Synthetic helper' }, eligibleHelpers: [{ id: 10, name: 'Synthetic helper' }] });
    await change(document.querySelector('select'), ''); await click(document.querySelector('input')); await click(button('Update helper access'));
    assert.deepEqual(posts[3][1], { helper_id: null, expected_helper_id: 10, confirmed: true });
    await act(async () => { posts[3][2].onSuccess(); posts[3][2].onFinish(); });
    assert.equal(document.querySelector('input').checked, false);
    for (role of ['agent', 'immigration', 'immigration_adviser', 'education']) { await render(StaffLink, { leadId: 7 }); assert.equal(document.getElementById('root').textContent, ''); }
    for (role of ['admin', 'super_admin', 'immigration_manager']) { await render(StaffLink, { leadId: 7 }); assert.ok(document.querySelector('a[href="/portal/immigration/cases/7/helper"]')); }
    await act(async () => root.unmount());
    console.log('Assigned helper UI: opt-in navigation, narrow case links, requested upload/error/retry/busy states, case reset, confirmed assignment/revocation and manager-only link passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });

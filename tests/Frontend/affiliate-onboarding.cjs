const assert = require('node:assert/strict');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/affiliate/apply' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
dom.window.HTMLCanvasElement.prototype.getContext = () => ({ clearRect() {}, fillText() {}, measureText: () => ({ width: 100 }) });
dom.window.HTMLCanvasElement.prototype.toDataURL = () => 'data:image/png;base64,canvas';
const React = require('react');
const { createRoot } = require('react-dom/client');
const { act } = React;
const requests = [];
const router = { post: (url, data, options) => requests.push({ url, data, options }) };
function useForm(initial) {
    const [data, set] = React.useState(initial);
    const [errors, setErrors] = React.useState({});
    const transform = React.useRef(value => value);
    const form = {
        data, errors, clearErrors: () => setErrors({}),
        setData: (key, value) => set(previous => ({ ...previous, [key]: value })),
        transform: fn => { transform.current = fn; },
        post: (url, options) => router.post(url, transform.current(data), { ...options, onError: setErrors }),
    };
    return form;
}
const inertia = { router, useForm, Head: () => null, Link: props => React.createElement('a', props, props.children) };
const compiled = buildSync({
    stdin: { contents: `
        export {default as Apply} from './resources/js/pages/affiliate/Apply';
        export {default as Progress} from './resources/js/pages/affiliate/Progress';
        export {default as Application} from './resources/js/pages/admin/agents/Application';
        export {default as AccountActions} from './resources/js/components/affiliate/AccountActions';
        export {ConfirmHost} from './resources/js/components/ui/ConfirmDialog';
    `, resolveDir: process.cwd() },
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', loader: { '.css': 'empty' },
    external: ['react', 'react-dom', '@inertiajs/react', 'react-signature-canvas'],
}).outputFiles[0].text;
const loaded = new Module(__filename, module);
loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
loaded._compile(compiled, __filename);
const { Apply, Progress, AccountActions, Application, ConfirmHost } = loaded.exports;
const root = createRoot(document.getElementById('root'));
const render = (Component, props, key) => act(async () => root.render(React.createElement(React.Fragment, null,
    React.createElement(Component, { ...props, key }), React.createElement(ConfirmHost))));
const button = (name, scope = document) => [...scope.querySelectorAll('button')].find(el => el.textContent.trim() === name);
const click = el => act(async () => el.click());
const submit = () => act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
const input = async (selector, value) => act(async () => {
    const field = document.querySelector(selector);
    Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(field, value);
    field.dispatchEvent(new Event('input', { bubbles: true }));
});

(async () => {
    await render(Apply);
    for (const [key, value] of Object.entries({ name: 'Demo Applicant', email: 'demo@example.test', phone: '123', location: 'Canada' })) {
        await input(`[name="${key}"]`, value);
    }
    await click(document.querySelector('[name=consent]'));
    await submit();
    const apply = requests.pop();
    assert.equal(apply.url, '/affiliate/apply');
    assert.equal(apply.data.name, 'Demo Applicant');
    assert.equal(apply.data.consent, true);
    await act(async () => apply.options.onError({ email: 'Check this email.' }));
    assert.match(document.querySelector('[role=alert]').textContent, /Check this email/);
    await act(async () => apply.options.onSuccess());
    assert.match(document.querySelector('h1').textContent, /Check your email/);
    await click(button('Already applied? Request a fresh link'));
    await submit();
    const resend = requests.pop();
    assert.equal(resend.url, '/affiliate/apply/resend');
    assert.deepEqual(resend.data, { email: 'demo@example.test' });

    await render(Progress, { application: { name: 'Demo', status: 'Awaiting email verification' }, verifyUrl: '/verified-link' });
    await click(button('Confirm my email'));
    assert.equal(requests.pop().url, '/verified-link');
    await render(Progress, { application: { name: 'Demo', status: 'Awaiting signatures' }, agreement: {
        id: 7, version: 2, status: 'Generated', sign_url: '/exact-sign-link', document_url: '/own-pdf', download_url: '/own-download', fields: [], initial: {},
    } });
    assert.ok(document.querySelector('a[href="/own-download"]'));
    await click(button('Sign agreement'));
    await click(button('Sign agreement →'));
    assert.match(document.body.textContent, /You must agree to the terms/);
    assert.equal(requests.length, 0);
    await input('[placeholder="e.g. Lillian Novida Ejorango"]', 'Demo Applicant');
    await click(document.querySelector('input[type=checkbox]'));
    await click(button('Sign agreement →'));
    const signature = requests.pop();
    assert.equal(signature.url, '/exact-sign-link');
    assert.deepEqual(signature.data, { signer_name: 'Demo Applicant', signature_data: 'data:image/png;base64,canvas', agreement_id: 7, terms_accepted: 1 });
    await act(async () => { signature.options.onError({ agreement_id: 'Refresh this agreement.' }); signature.options.onFinish(); });
    assert.match(document.body.textContent, /Refresh this agreement/);

    await render(Application, { application: { name: 'Demo', status: 'Awaiting review', approve_url: '/approve', decline_url: '/decline' } });
    await click(button('Decline application'));
    await click(button('Cancel', document.querySelector('[role=dialog]')));
    assert.equal(requests.length, 0);
    await click(button('Approve application'));
    const review = requests.pop();
    assert.equal(review.url, '/approve');
    await act(async () => { review.options.onError({ application: 'Account conflict' }); review.options.onFinish(); });
    assert.equal(document.querySelector('[role=alert]').textContent, 'Account conflict');

    await render(AccountActions, { agent: { account_state: 'awaiting_signatures', account_actions: ['archive'], lifecycle_url: '/change' } });
    assert.equal(button('Reactivate account'), undefined);
    await click(button('Archive partner'));
    await click(button('Archive partner', document.querySelector('[role=dialog]')));
    const archive = requests.pop();
    assert.equal(archive.url, '/change');
    assert.deepEqual(archive.data, { action: 'archive', expected: 'awaiting_signatures' });
    console.log('Affiliate onboarding: application, renewal, verification, exact-version signing, review, archive and validation passed.');
})().catch(error => { console.error(error); process.exitCode = 1; })
    .finally(async () => { await act(async () => root.unmount()); dom.window.close(); });

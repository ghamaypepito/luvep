const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/immigration/cases/42/profile?tab=personal' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'); const { act } = React;
const { createRoot } = require('react-dom/client');
const calls = [], posts = [], reloads = [], accepted = [];
const inertia = {
    Head: () => null, Link: ({ children, ...p }) => React.createElement('a', p, children),
    usePage: () => ({ props: { auth: { user: { role: 'immigration' } } } }),
    router: { reload: p => reloads.push(p), post: (...a) => posts.push(a) },
    useForm(initial) {
        const [data, set] = React.useState(initial); const [defaults, baseline] = React.useState(initial);
        return { data, errors: {}, processing: false, isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
            setData: (key, value) => set(old => typeof key === 'string' ? { ...old, [key]: value } : key),
            defaults: next => baseline(next || data), post: (...args) => posts.push(args), reset() {}, clearErrors() {} };
    },
};
function compile(file) {
    const target = path.resolve(file);
    const output = buildSync({ entryPoints: [target], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, external: ['react', 'react-dom', '@inertiajs/react'] }).outputFiles[0].text;
    const loaded = new Module(target, module); loaded.filename = target; loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(output, target);
    return loaded.exports.default;
}
const Review = compile('resources/js/components/immigration/case-profile/PassportPrefillReview.jsx');
const Personal = compile('resources/js/components/immigration/case-profile/tabs/PersonalTab.jsx');
const root = createRoot(document.getElementById('root'));
const fixture = { token: 'synthetic-token', document: { id: 8, name: 'synthetic-passport.pdf', url: '/private-source' }, full_name: 'SURNAME, COMBINED GIVEN NAMES', expires_in_minutes: 15, fields: [
    { key: 'passport_number', label: 'Passport number', value: 'NEW-SYNTHETIC', current: 'OLD-SYNTHETIC', confidence: .99, page: 1, evidence: 'Printed synthetic value', can_apply: true },
    { key: 'dob', label: 'Date of birth', value: '1990-01-01', current: '1980-01-01', confidence: .3, page: 1, evidence: 'Unclear', can_apply: false },
] };
let getState = { status: 'none', draft: null }, failApply = false, deferred = null, deferredApply = null;
global.fetch = async (url, options = {}) => {
    calls.push({ url, options });
    if (!options.method) return { ok: true, json: async () => getState };
    if (url.endsWith('/passport-prefill')) {
        if (deferred) return await new Promise(resolve => { deferred.resolve = resolve; });
        return { ok: true, json: async () => fixture };
    }
    if (deferredApply) return await new Promise(resolve => { deferredApply.resolve = resolve; });
    return failApply ? { ok: false, json: async () => ({ message: 'The passport changed. Extract again.' }) } : { ok: true, json: async () => ({ saved: { passport_number: 'NEW-SYNTHETIC' }, personal: { passport_number: 'NEW-SYNTHETIC', phone: 'concurrently corrected phone' } }) };
};
const render = async (Component, props = {}) => act(async () => root.render(React.createElement(Component, props)));
const click = async el => { assert.ok(el, 'Expected control'); await act(async () => el.dispatchEvent(new MouseEvent('click', { bubbles: true }))); };
const button = text => [...document.querySelectorAll('button')].find(el => el.textContent === text);
const confirm = () => document.querySelector('input[type="checkbox"]:not([aria-label])');
const pause = ms => act(async () => await new Promise(resolve => setTimeout(resolve, ms)));

(async () => {
    // A ready automatic draft loads with a GET only; nothing is selected or saved.
    getState = { status: 'ready', draft: fixture };
    await render(Review, { leadId: 42, onApplied: (...args) => accepted.push(args) });
    assert.equal(calls.filter(c => c.options.method === 'POST').length, 0);
    assert.ok(document.querySelector('a[href="/private-source"]'));
    assert.match(document.body.textContent, /model estimate, not verification/);
    assert.ok([...document.querySelectorAll('input[type="checkbox"]')].every(el => !el.checked));
    assert.equal(document.querySelector('[aria-label="Accept Date of birth"]').disabled, true);
    assert.equal(button('Confirm and save selected fields').disabled, true);
    await click(document.querySelector('[aria-label="Accept Passport number"]'));
    assert.equal(button('Confirm and save selected fields').disabled, true);
    await click(confirm()); await click(button('Confirm and save selected fields'));
    assert.deepEqual(JSON.parse(calls.at(-1).options.body), { fields: ['passport_number'], confirmed: true });
    assert.equal(accepted.length, 1);
    assert.deepEqual(accepted[0][0], { passport_number: 'NEW-SYNTHETIC' });
    assert.match(document.body.textContent, /Selected passport fields saved/);

    // Error responses do not apply anything; discard remains read-only.
    getState = { status: 'none', draft: null };
    await render(Review, { leadId: 43, onApplied: (...args) => accepted.push(args) });
    await click(button('Extract passport suggestions'));
    await click(document.querySelector('[aria-label="Accept Passport number"]')); await click(confirm());
    failApply = true; await click(button('Confirm and save selected fields'));
    assert.match(document.querySelector('[role="alert"]').textContent, /passport changed/);
    assert.equal(accepted.length, 1);
    const writesBeforeDiscard = calls.filter(c => c.options.method === 'POST').length;
    await click(button('Discard suggestions'));
    assert.equal(calls.filter(c => c.options.method === 'POST').length, writesBeforeDiscard);
    failApply = false;

    // A late extraction result cannot populate a different case after navigation.
    deferred = {};
    let requestPromise;
    await act(async () => { button('Extract passport suggestions').dispatchEvent(new MouseEvent('click', { bubbles: true })); });
    const oldSignal = calls.at(-1).options.signal;
    await render(Review, { leadId: 44, onApplied: (...args) => accepted.push(args) });
    assert.equal(oldSignal.aborted, true);
    await act(async () => deferred.resolve({ ok: true, json: async () => fixture }));
    assert.equal(document.querySelector('a[href="/private-source"]'), null);
    deferred = null;

    // A late save response for the previous case must not update the next form.
    getState = { status: 'ready', draft: fixture };
    await render(Review, { leadId: 45, onApplied: (...args) => accepted.push(args) });
    await click(document.querySelector('[aria-label="Accept Passport number"]')); await click(confirm());
    deferredApply = {};
    await act(async () => button('Confirm and save selected fields').dispatchEvent(new MouseEvent('click', { bubbles: true })));
    getState = { status: 'none', draft: null };
    await render(Review, { leadId: 46, onApplied: (...args) => accepted.push(args) });
    await act(async () => deferredApply.resolve({ ok: true, json: async () => ({ saved: { passport_number: 'OLD-CASE-RESPONSE' } }) }));
    assert.equal(accepted.length, 1);
    assert.equal(document.querySelector('a[href="/private-source"]'), null);
    deferredApply = null;
    await render(Review, { leadId: 47, blocked: true });
    assert.equal(button('Extract passport suggestions').disabled, true);
    // Actual Personal tab: scan/review never triggers its existing autosave.
    const lead = { id: 42, first_name: 'Existing', last_name: 'Synthetic', email: 'synthetic@example.test', passport_number: 'OLD-SYNTHETIC', phone: 'old phone' };
    await render(Personal, { lead, passportPrefillAvailable: true });
    await click(button('Extract passport suggestions'));
    assert.ok([...document.querySelectorAll('input')].some(el => el.value === 'OLD-SYNTHETIC'));
    await pause(1000); assert.equal(posts.length, 0);
    await click(document.querySelector('[aria-label="Accept Passport number"]')); await click(confirm());
    await click(button('Confirm and save selected fields'));
    assert.ok([...document.querySelectorAll('input')].some(el => el.value === 'NEW-SYNTHETIC'));
    assert.ok([...document.querySelectorAll('input')].some(el => el.value === 'concurrently corrected phone'));
    assert.ok([...document.querySelectorAll('input')].some(el => el.value === 'Existing'));
    await pause(1000); assert.equal(posts.length, 0, 'Accepting already saved fields must not post the whole form again');
    assert.deepEqual(reloads.at(-1).only, ['lead', 'activity']);
    await render(Personal, { lead: { ...lead, id: 99, first_name: 'Different', passport_number: 'CASE-99' }, passportPrefillAvailable: false });
    assert.ok([...document.querySelectorAll('input')].some(el => el.value === 'Different'));
    assert.ok(![...document.querySelectorAll('input')].some(el => el.value === 'Existing'));
    assert.equal(button('Extract passport suggestions'), undefined);
    await pause(1000); assert.equal(posts.length, 0);
    await act(async () => root.unmount()); dom.window.close();
    console.log('Passport prefill: automatic draft read, explicit selection/confirmation, low-confidence denial, source link, stale error, no autosave, fresh form snapshot and case-switch isolation passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); dom.window.close(); process.exitCode = 1; });
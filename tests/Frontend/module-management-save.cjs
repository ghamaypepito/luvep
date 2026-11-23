const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/admin/module-management' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const calls = [];
const inertia = { Head: () => null, router: { post: (...args) => calls.push(args) } };
const entry = 'resources/js/pages/admin/ModuleManagement.jsx';
const code = buildSync({ entryPoints: [entry], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', '@inertiajs/react'] }).outputFiles[0].text;
const compiled = new Module(path.resolve(entry + '.test.cjs'), module);
compiled.filename = path.resolve(entry + '.test.cjs');
compiled.paths = module.paths;
compiled.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
compiled._compile(code, compiled.filename);
const Page = compiled.exports.default;
const root = createRoot(document.getElementById('root'));
const modules = [
    { key: 'case_name_correction', label: 'Name Correction', description: 'Correct names', features: [] },
    { key: 'hr', label: 'Existing module', description: 'Existing access', features: [{ key: 'hr.people', label: 'People' }] },
];
const user = { id: 42, name: 'Synthetic Manager', email: 'manager@example.test', role: 'immigration_manager',
    modules: ['hr'], portals: [], is_admin: false, is_super_admin: false };
const button = label => [...document.querySelectorAll('button')].find(el => el.textContent.trim() === label);
const click = el => act(async () => { assert.ok(el, 'Expected control'); el.click(); });
const render = users => act(async () => root.render(React.createElement(Page, { modules, users, portals: [] })));
const start = async (saved = user) => {
    await act(async () => root.render(null));
    await render([saved]);
    await click([...document.querySelectorAll('button')].find(el => el.textContent.includes('manager@example.test')));
    await click([...document.querySelectorAll('button')].find(el => el.textContent.includes('Name Correction')));
    await click(button('Save access'));
    return calls.at(-1);
};

(async () => {
    const failed = await start();
    assert.deepEqual(failed[1], { modules: ['hr', 'case_name_correction'] }, 'Submit the selected grant while retaining existing access');
    assert.equal([...document.querySelectorAll('button')].find(el => el.textContent.includes('Name Correction')).disabled, true,
        'A pending save holds the submitted module selection');
    assert.equal([...document.querySelectorAll('button')].find(el => el.textContent.includes('manager@example.test')).disabled, true,
        'A pending save cannot be presented under another selected account');
    assert.equal(typeof failed[2].onError, 'function', 'Rejected saves must have visible failure feedback');
    await act(async () => { failed[2].onError({ 'modules.0': 'Synthetic validation failure' }); failed[2].onFinish(); });
    assert.match(document.querySelector('[role="alert"]')?.textContent || '', /Synthetic validation failure/);
    assert.equal(document.querySelector('[role="status"]'), null, 'A rejected save must not claim success');

    const mismatch = await start();
    await act(async () => { mismatch[2].onSuccess({ props: { users: [user] } }); mismatch[2].onFinish(); });
    assert.match(document.querySelector('[role="alert"]')?.textContent || '', /not.*saved|could not.*verify/i,
        'A redirect without the requested stored grant must not be acknowledged as success');
    assert.equal(document.querySelector('[role="status"]'), null);

    const missing = await start();
    await render([]);
    await act(async () => { missing[2].onSuccess({ props: { users: [] } }); missing[2].onFinish(); });
    assert.match(document.querySelector('[role="alert"]')?.textContent || '', /could not.*verify/i,
        'A missing returned account must still display the failed verification');

    const denied = await start();
    const retained = { ...user, modules: ['hr', 'case_name_correction'] };
    await render([retained]);
    await act(async () => { denied[2].onSuccess({ props: { users: [retained], flash: { error: 'Synthetic save refusal' } } }); denied[2].onFinish(); });
    assert.match(document.querySelector('[role="alert"]')?.textContent || '', /Synthetic save refusal/);
    assert.equal(document.querySelector('[role="status"]'), null, 'Explicit server errors must never receive a contradictory success acknowledgement');

    const good = await start();
    const persisted = { ...user, modules: ['hr', 'case_name_correction'] };
    await render([persisted]);
    await act(async () => { good[2].onSuccess({ props: { users: [persisted] } }); good[2].onFinish(); });
    assert.match(document.querySelector('[role="status"]')?.textContent || '', /saved.*Synthetic Manager/i);
    assert.equal(button('Save access').disabled, true, 'A confirmed response clears the dirty state');
    await act(async () => root.render(null));
    await render([persisted]);
    await click([...document.querySelectorAll('button')].find(el => el.textContent.includes('manager@example.test')));
    assert.equal(button('Save access').disabled, true, 'A fresh page retains the saved grant');
    assert.equal([...document.querySelectorAll('button')].find(el => el.textContent.includes('Name Correction')).getAttribute('aria-pressed'), 'true');

    const legacy = await start({ ...user, modules: ['hr', 'hr.people'] });
    await render([persisted]);
    await act(async () => { legacy[2].onSuccess({ props: { users: [persisted] } }); legacy[2].onFinish(); });
    assert.ok(document.querySelector('[role="status"]'), 'Expected backend removal of redundant child grants is accepted');
    await click([...document.querySelectorAll('button')].find(el => el.textContent.includes('Name Correction')));
    assert.equal(document.querySelector('[role="status"]'), null, 'Editing clears the previous save acknowledgement');

    await act(async () => root.unmount());
    dom.window.close();
    console.log('Module Management actual-page checks passed: retained grants, visible errors, verified stored response, reload and acknowledgement reset.');
})().catch(error => { console.error(error); process.exitCode = 1; });
